<?php

namespace App\Services\BulkGeneration;

use App\Enums\EnvelopeStatus;
use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Models\BulkGeneration;
use App\Models\BulkGenerationRow;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\Envelopes\EnvelopeReadiness;
use App\Services\Envelopes\Sending\Exceptions\SendingException;
use App\Services\Envelopes\Sending\ScheduledSend;
use App\Services\Envelopes\Sending\SendEnvelope;
use App\Services\Plans\Exceptions\SendingBlockedException;
use App\Services\Templates\CreateEnvelopeFromTemplate;
use App\Support\CurrentOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Gera o envelope de UMA linha (job GenerateEnvelopeFromRowJob) — idempotente.
 *
 * 1. **Criação** em transação com a linha bloqueada: se a linha já tem envelope, nada é
 *    criado. O envelope nasce pelo MESMO `CreateEnvelopeFromTemplate` de "Usar modelo", com a
 *    versão FIXADA no lote, e a linha recebe `envelope_id` na mesma transação — reexecutar o
 *    job depois de uma queda não duplica envelope (ou a transação inteira valeu, ou nada).
 * 2. **Destino**, fora da transação: devolve a unidade de cota do lote e, conforme o lote,
 *    deixa para revisão, envia (`SendEnvelope`) ou agenda (`ScheduledSend`). Linha `created`
 *    sem `outcome` é exatamente "criada, destino pendente": a reexecução só refaz este passo
 *    (os dois serviços também são idempotentes).
 *
 * Toda falha fica na linha com mensagem PT-BR e devolve a cota; nenhuma exceção sobe (na fila
 * `sync` ela derrubaria a requisição que confirmou o lote).
 */
final class RowGenerator
{
    public function __construct(
        private readonly CreateEnvelopeFromTemplate $creator,
        private readonly BulkGenerationQuota $quota,
        private readonly BulkGenerationPump $pump,
        private readonly BulkGenerationProgress $progress,
    ) {}

    public function handle(int $rowId): void
    {
        $row = BulkGenerationRow::withoutOrganizationScope()->whereKey($rowId)->first();

        if ($row === null) {
            return;
        }

        $batch = BulkGeneration::withoutOrganizationScope()->whereKey($row->bulk_generation_id)->first();
        $organization = $batch !== null ? Organization::query()->whereKey($batch->organization_id)->first() : null;

        if ($batch === null || $organization === null) {
            return;
        }

        try {
            CurrentOrganization::instance()->runAs($organization, function () use ($row, $batch, $organization): void {
                $this->process($row, $batch, $organization);
            });
        } catch (Throwable $exception) {
            Log::error('Falha inesperada ao gerar uma linha do lote.', [
                'bulk_generation' => $batch->ulid,
                'row' => $row->row_index,
                'exception' => $exception::class,
            ]);

            $this->fail($row, 'Não foi possível gerar o documento desta linha. Tente em um novo lote.');
        } finally {
            $this->progress->finishIfDone((int) $batch->getKey());
            $this->pump->pump((int) $organization->getKey());
        }
    }

    /**
     * O job falhou de vez (tempo esgotado, worker derrubado): a linha não fica presa.
     */
    public function markLost(int $rowId): void
    {
        $row = BulkGenerationRow::withoutOrganizationScope()->whereKey($rowId)->first();

        if ($row === null) {
            return;
        }

        $this->fail($row, 'A geração desta linha foi interrompida. Tente em um novo lote.');
        $this->progress->finishIfDone((int) $row->bulk_generation_id);
        $this->pump->pump((int) $row->organization_id);
    }

    private function process(BulkGenerationRow $row, BulkGeneration $batch, Organization $organization): void
    {
        $row->refresh();

        if ($row->status === BulkRowStatus::Created) {
            if ($row->outcome === null) {
                // Reexecução depois de uma queda entre a criação e o destino: refaz só o destino,
                // como quem confirmou o lote (sem membership, o envio é recusado com motivo).
                [$user, $membership] = $this->confirmer($batch);
                CurrentOrganization::instance()->setMembership($membership);

                $user !== null
                    ? ActingUser::run($user, fn () => $this->deliver($row, $batch, $organization, $membership))
                    : $this->deliver($row, $batch, $organization);
            }

            return;
        }

        if (! in_array($row->status, [BulkRowStatus::Queued, BulkRowStatus::Processing], true)) {
            return; // cancelada, falhou ou nunca foi reivindicada
        }

        if ($batch->status === BulkGenerationStatus::Canceled) {
            $this->cancelRow($row);

            return;
        }

        if (! BulkGenerationFeature::enabled($organization)) {
            $this->fail($row, 'A geração em lote não está disponível no plano atual da organização.');

            return;
        }

        [$user, $membership] = $this->confirmer($batch);

        if ($user === null || $membership === null) {
            $this->fail($row, 'Quem confirmou o lote não faz mais parte desta organização.');

            return;
        }

        CurrentOrganization::instance()->setMembership($membership);

        /** @var Template|null $template */
        $template = Template::query()->whereKey($batch->template_id)->first();
        /** @var TemplateVersion|null $version */
        $version = TemplateVersion::query()->whereKey($batch->template_version_id)->first();

        if ($template === null || $version === null) {
            $this->fail($row, 'O modelo deste lote não existe mais.');

            return;
        }

        if (! Gate::forUser($user)->allows('use', $template)) {
            $this->fail($row, $template->isUsable()
                ? 'Quem confirmou o lote não tem mais permissão para gerar documentos a partir deste modelo.'
                : 'O modelo foi arquivado depois da confirmação do lote.');

            return;
        }

        BulkGenerationRow::withoutOrganizationScope()
            ->whereKey($row->getKey())
            ->whereIn('status', [BulkRowStatus::Queued->value, BulkRowStatus::Processing->value])
            ->update([
                'status' => BulkRowStatus::Processing->value,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => Carbon::now(),
            ]);

        try {
            $created = ActingUser::run($user, fn (): ?Envelope => $this->create($row, $batch, $template, $version, $user));
        } catch (ValidationException $exception) {
            $this->fail($row, self::firstMessage($exception));

            return;
        }

        if ($created === null) {
            return; // outra execução já tratou a linha
        }

        $row->refresh();
        ActingUser::run($user, fn () => $this->deliver($row, $batch->fresh() ?? $batch, $organization, $membership));
    }

    /**
     * @throws ValidationException
     */
    private function create(BulkGenerationRow $row, BulkGeneration $batch, Template $template, TemplateVersion $version, User $user): ?Envelope
    {
        return DB::transaction(function () use ($row, $batch, $template, $version, $user): ?Envelope {
            /** @var BulkGenerationRow|null $locked */
            $locked = BulkGenerationRow::withoutOrganizationScope()->whereKey($row->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->envelope_id !== null || $locked->status !== BulkRowStatus::Processing) {
                return null;
            }

            $payload = $locked->payload ?? [];
            $participants = is_array($payload['participants'] ?? null) ? $payload['participants'] : [];

            $envelope = $this->creator->handle($template, $user, [
                'title' => is_string($payload['title'] ?? null) ? $payload['title'] : self::defaultTitle($template, $version, $participants),
                'values' => is_array($payload['values'] ?? null) ? $payload['values'] : [],
                'participants' => $participants,
            ], null, $version, [
                'bulk_generation' => $batch->ulid,
                'bulk_row' => $locked->row_index,
            ]);

            $locked->forceFill([
                'envelope_id' => $envelope->getKey(),
                'status' => BulkRowStatus::Created,
                'payload' => null,
                'error' => null,
                'processed_at' => Carbon::now(),
            ])->save();

            BulkGeneration::withoutOrganizationScope()->whereKey($batch->getKey())->increment('created_count');

            return $envelope;
        });
    }

    /**
     * Destino do envelope já criado. Revisão e agendamento: a unidade do lote volta antes.
     * Envio ao gerar: a unidade é entregue ao envio ({@see BulkGenerationQuota::handOver()}).
     */
    private function deliver(BulkGenerationRow $row, BulkGeneration $batch, Organization $organization, ?Membership $membership = null): void
    {
        /** @var Envelope|null $envelope */
        $envelope = Envelope::withoutOrganizationScope()->whereKey($row->envelope_id)->first();

        if ($envelope === null) {
            $this->setOutcome($row, BulkRowOutcome::Draft, 'O documento gerado foi excluído.');

            return;
        }

        $ready = $envelope->status === EnvelopeStatus::Ready && EnvelopeReadiness::isReady($envelope);
        $mode = (string) $batch->option('mode', 'review');
        $canceled = $batch->status === BulkGenerationStatus::Canceled && $envelope->status->isDraftLike();

        // "Enviar ao gerar": a unidade reservada na confirmação é ENTREGUE ao envio (sem janela em
        // que outro envio da organização a tome). Nos demais destinos ela volta agora.
        $handOver = $mode === 'send' && $ready && ! $canceled;

        if ($handOver) {
            $this->quota->handOver($row, $envelope);
        } else {
            $this->quota->releaseRow($row, 'bulk_row_created');
        }

        try {
            $this->route($row, $batch, $envelope, $mode, $ready, $canceled, $membership);
        } finally {
            if ($handOver) {
                // Enviado: a unidade já foi trocada dentro do envio (release idempotente, nada muda).
                // Não enviado por qualquer motivo: a unidade do lote volta aqui.
                $this->quota->releaseRow($row, 'bulk_row_not_sent');
            }
        }
    }

    private function route(BulkGenerationRow $row, BulkGeneration $batch, Envelope $envelope, string $mode, bool $ready, bool $canceled, ?Membership $membership): void
    {
        if ($canceled) {
            $this->setOutcome($row, $ready ? BulkRowOutcome::Ready : BulkRowOutcome::Draft, 'Lote cancelado antes do envio; o documento ficou para revisão.');

            return;
        }

        if ($mode === 'review') {
            // Rascunho: a linha diz QUAL é a pendência (sugestão do modelo a revisar, campo a
            // posicionar…), para o remetente não precisar abrir documento por documento.
            $this->setOutcome($row, $ready ? BulkRowOutcome::Ready : BulkRowOutcome::Draft, $ready ? null : self::pendingMessage($envelope));

            return;
        }

        if (! $ready) {
            $issues = EnvelopeReadiness::issues($envelope);
            $this->setOutcome($row, BulkRowOutcome::NotSent, 'O documento precisa de revisão antes do envio'.($issues !== [] ? ': '.$issues[0] : '.'));

            return;
        }

        $membership ??= CurrentOrganization::instance()->membership();

        if ($membership === null || ! $membership->hasPermission(Permission::SendEnvelopes)) {
            $this->setOutcome($row, BulkRowOutcome::NotSent, 'Quem confirmou o lote não pode mais enviar documentos.');

            return;
        }

        try {
            if ($mode === 'schedule') {
                $at = ScheduledSend::parse($batch->option('scheduled_for'));

                if ($at === null) {
                    $this->setOutcome($row, BulkRowOutcome::NotSent, 'O horário do envio agendado é inválido.');

                    return;
                }

                app(ScheduledSend::class)->schedule($envelope, $at);
                $this->setOutcome($row, BulkRowOutcome::Scheduled);

                return;
            }

            app(SendEnvelope::class)->handle($envelope);
            $this->setOutcome($row, BulkRowOutcome::Sent);
        } catch (SendingException $exception) {
            $exception->errorCode === 'already_sent'
                ? $this->setOutcome($row, BulkRowOutcome::Sent)
                : $this->setOutcome($row, BulkRowOutcome::NotSent, $exception->getMessage());
        } catch (SendingBlockedException $exception) {
            $this->setOutcome($row, BulkRowOutcome::NotSent, $exception->getMessage());
        }
    }

    private function setOutcome(BulkGenerationRow $row, BulkRowOutcome $outcome, ?string $message = null): void
    {
        BulkGenerationRow::withoutOrganizationScope()->whereKey($row->getKey())->update([
            'outcome' => $outcome->value,
            'outcome_message' => $message !== null ? Str::limit($message, 480, '…') : null,
            'updated_at' => Carbon::now(),
        ]);
    }

    private function fail(BulkGenerationRow $row, string $message): void
    {
        $updated = BulkGenerationRow::withoutOrganizationScope()
            ->whereKey($row->getKey())
            ->whereIn('status', [BulkRowStatus::Pending->value, BulkRowStatus::Queued->value, BulkRowStatus::Processing->value])
            ->whereNull('envelope_id')
            ->update([
                'status' => BulkRowStatus::Failed->value,
                'error' => Str::limit($message, 480, '…'),
                'payload' => null,
                'processed_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

        if ($updated === 1) {
            BulkGeneration::withoutOrganizationScope()->whereKey($row->bulk_generation_id)->increment('failed_count');
            $this->quota->releaseRow($row, 'bulk_row_failed');
        }
    }

    private function cancelRow(BulkGenerationRow $row): void
    {
        $updated = BulkGenerationRow::withoutOrganizationScope()
            ->whereKey($row->getKey())
            ->whereIn('status', [BulkRowStatus::Pending->value, BulkRowStatus::Queued->value, BulkRowStatus::Processing->value])
            ->whereNull('envelope_id')
            ->update(['status' => BulkRowStatus::Canceled->value, 'payload' => null, 'updated_at' => Carbon::now()]);

        if ($updated === 1) {
            BulkGeneration::withoutOrganizationScope()->whereKey($row->bulk_generation_id)->increment('canceled_count');
            $this->quota->releaseRow($row, 'bulk_canceled');
        }
    }

    /**
     * @return array{0: User|null, 1: Membership|null}
     */
    private function confirmer(BulkGeneration $batch): array
    {
        $userId = $batch->confirmed_by_user_id ?? $batch->created_by_user_id;

        if ($userId === null) {
            return [null, null];
        }

        $user = User::query()->whereKey($userId)->first();

        $membership = Membership::query()
            ->where('organization_id', $batch->organization_id)
            ->where('user_id', $userId)
            ->where('status', MembershipStatus::Active->value)
            ->first();

        return [$user, $membership];
    }

    /**
     * @param  array<string, mixed>  $participants
     */
    private static function defaultTitle(Template $template, TemplateVersion $version, array $participants): string
    {
        $first = $version->roles->first();
        $name = $first !== null && is_array($participants[$first->ulid] ?? null) ? (string) ($participants[$first->ulid]['name'] ?? '') : '';

        return Str::limit($name !== '' ? "{$template->name} — {$name}" : $template->name, 160, '');
    }

    private static function pendingMessage(Envelope $envelope): ?string
    {
        $issues = EnvelopeReadiness::issues($envelope);

        if ($issues === []) {
            return null;
        }

        $first = $issues[0];

        return 'Antes de enviar: '.mb_strtolower(mb_substr($first, 0, 1)).mb_substr($first, 1).(count($issues) > 1 ? sprintf(' (e mais %d pendência%s)', count($issues) - 1, count($issues) > 2 ? 's' : '') : '');
    }

    private static function firstMessage(ValidationException $exception): string
    {
        foreach ($exception->errors() as $messages) {
            foreach ($messages as $message) {
                return (string) $message;
            }
        }

        return 'Os dados desta linha não foram aceitos.';
    }
}
