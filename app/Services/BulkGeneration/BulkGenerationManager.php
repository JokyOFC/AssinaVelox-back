<?php

namespace App\Services\BulkGeneration;

use App\Jobs\BulkGeneration\StartBulkGenerationJob;
use App\Models\BulkGeneration;
use App\Models\BulkGenerationRow;
use App\Models\Organization;
use App\Models\User;
use App\Services\Envelopes\Reminders\RemindersFeature;
use App\Services\Envelopes\Sending\ScheduledSend;
use App\Services\Plans\Exceptions\SendingBlockedException;
use App\Services\Plans\PlanLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Confirmar, cancelar e descartar um lote (docs/fase-3/geracao-em-lote.md §5–§6).
 */
final class BulkGenerationManager
{
    public const MODES = ['review', 'send', 'schedule'];

    public function __construct(
        private readonly PlanLedger $ledger,
        private readonly BulkGenerationQuota $quota,
        private readonly BulkGenerationStorage $storage,
        private readonly RemindersFeature $reminders,
    ) {}

    /**
     * Confirma o lote: reserva a cota das linhas VÁLIDAS (ou nada, se não couber) e dispara
     * o orquestrador.
     *
     * @param  array{mode?: string|null, scheduled_for?: string|null}  $options
     *
     * @throws ValidationException
     */
    public function confirm(BulkGeneration $batch, User $user, array $options): BulkGeneration
    {
        /** @var Organization $organization */
        $organization = $batch->organization;
        $mode = (string) ($options['mode'] ?? 'review');

        if (! in_array($mode, self::MODES, true)) {
            throw ValidationException::withMessages(['mode' => 'Escolha o que fazer com os documentos gerados.']);
        }

        if ($mode !== 'review' && ! BulkGenerationAccess::canSend()) {
            throw ValidationException::withMessages(['mode' => 'Sua função não permite enviar documentos. Gere os documentos para revisão.']);
        }

        $scheduledFor = $mode === 'schedule' ? $this->scheduledFor($organization, $options['scheduled_for'] ?? null) : null;
        $limits = BulkGenerationLimits::for($organization);

        DB::transaction(function () use ($batch, $user, $organization, $mode, $scheduledFor, $limits): void {
            /** @var BulkGeneration|null $locked */
            $locked = BulkGeneration::withoutOrganizationScope()->whereKey($batch->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->status !== BulkGenerationStatus::Validated) {
                throw ValidationException::withMessages(['batch' => 'Este lote não está pronto para confirmação. Refaça a pré-validação.']);
            }

            if ($locked->valid_count < 1) {
                throw ValidationException::withMessages(['batch' => 'Nenhuma linha válida para gerar. Corrija a planilha e envie de novo.']);
            }

            $running = BulkGeneration::withoutOrganizationScope()
                ->where('organization_id', $organization->getKey())
                ->where('status', BulkGenerationStatus::Running->value)
                ->count();

            if ($running >= $limits->maxConcurrentBatches) {
                throw ValidationException::withMessages(['batch' => sprintf(
                    'Já há %d %s em geração nesta organização (o limite do plano). Aguarde um terminar para confirmar este.',
                    $running,
                    $running === 1 ? 'lote' : 'lotes',
                )]);
            }

            $subscription = $this->ledger->subscriptionFor((int) $organization->getKey(), lock: true);

            if ($subscription === null) {
                throw ValidationException::withMessages(['batch' => 'A organização não tem uma assinatura ativa.']);
            }

            try {
                $this->ledger->assertCanSend($subscription, 0);
            } catch (SendingBlockedException $exception) {
                throw ValidationException::withMessages(['batch' => $exception->getMessage()]);
            }

            $valid = (int) $locked->valid_count;

            if (! $subscription->hasEnvelopeQuotaAvailable($valid)) {
                $remaining = (int) $subscription->remainingEnvelopes();

                throw ValidationException::withMessages(['batch' => sprintf(
                    'A cota do plano comporta mais %d %s neste ciclo, e o lote tem %d %s. Nada foi criado. Remova linhas da planilha ou amplie o plano.',
                    $remaining,
                    $remaining === 1 ? 'documento' : 'documentos',
                    $valid,
                    $valid === 1 ? 'linha válida' : 'linhas válidas',
                )]);
            }

            /** @var list<int> $rowIds */
            $rowIds = BulkGenerationRow::withoutOrganizationScope()
                ->where('bulk_generation_id', $locked->getKey())
                ->where('status', BulkRowStatus::Valid->value)
                ->orderBy('row_index')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $this->quota->reserve($locked, $subscription, $rowIds);

            BulkGenerationRow::withoutOrganizationScope()
                ->where('bulk_generation_id', $locked->getKey())
                ->where('status', BulkRowStatus::Valid->value)
                ->update(['status' => BulkRowStatus::Pending->value, 'updated_at' => Carbon::now()]);

            $locked->forceFill([
                'status' => BulkGenerationStatus::Running,
                'options' => [
                    'mode' => $mode,
                    'scheduled_for' => $scheduledFor?->format(ScheduledSend::STORAGE_FORMAT),
                ],
                'confirmed_at' => Carbon::now(),
                'confirmed_by_user_id' => $user->getKey(),
            ])->save();
        }, 3);

        StartBulkGenerationJob::dispatch((int) $batch->getKey());

        return $batch->refresh();
    }

    /**
     * Cancela o lote em geração: as linhas que ainda não viraram envelope param e devolvem a
     * cota; envelopes já criados, enviados ou agendados NÃO são tocados.
     *
     * @throws ValidationException
     */
    public function cancel(BulkGeneration $batch, User $user): BulkGeneration
    {
        /** @var list<int> $canceled */
        $canceled = DB::transaction(function () use ($batch, $user): array {
            /** @var BulkGeneration|null $locked */
            $locked = BulkGeneration::withoutOrganizationScope()->whereKey($batch->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->status !== BulkGenerationStatus::Running) {
                throw ValidationException::withMessages(['batch' => 'Só é possível cancelar um lote em geração.']);
            }

            $ids = BulkGenerationRow::withoutOrganizationScope()
                ->where('bulk_generation_id', $locked->getKey())
                ->whereIn('status', [BulkRowStatus::Pending->value, BulkRowStatus::Queued->value])
                ->whereNull('envelope_id')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $affected = 0;

            foreach (array_chunk($ids, 200) as $chunk) {
                $affected += BulkGenerationRow::withoutOrganizationScope()
                    ->whereIn('id', $chunk)
                    ->whereIn('status', [BulkRowStatus::Pending->value, BulkRowStatus::Queued->value])
                    ->update(['status' => BulkRowStatus::Canceled->value, 'payload' => null, 'updated_at' => Carbon::now()]);
            }

            $locked->forceFill([
                'status' => BulkGenerationStatus::Canceled,
                'canceled_at' => Carbon::now(),
                'canceled_by_user_id' => $user->getKey(),
                'canceled_count' => (int) $locked->canceled_count + $affected,
            ])->save();

            return $ids;
        }, 3);

        foreach (array_chunk($canceled, 200) as $chunk) {
            BulkGenerationRow::withoutOrganizationScope()->whereIn('id', $chunk)->get()
                ->each(fn (BulkGenerationRow $row) => $this->quota->releaseRow($row, 'bulk_canceled'));
        }

        $fresh = $batch->refresh();
        $this->storage->delete($fresh);

        return $fresh;
    }

    /**
     * Descarta um lote ainda não confirmado: linhas, registro e arquivo. Nada foi reservado.
     *
     * @throws ValidationException
     */
    public function discard(BulkGeneration $batch): void
    {
        if (! $batch->status->isEditable()) {
            throw ValidationException::withMessages(['batch' => 'Um lote confirmado não pode ser descartado. Cancele-o se ainda estiver em geração.']);
        }

        $this->storage->delete($batch);

        DB::transaction(function () use ($batch): void {
            BulkGenerationRow::withoutOrganizationScope()->where('bulk_generation_id', $batch->getKey())->delete();
            $batch->delete();
        });
    }

    /**
     * `Y-m-d\TH:i` no fuso da organização → UTC, com os limites do envio agendado (§2.5).
     *
     * @throws ValidationException
     */
    private function scheduledFor(Organization $organization, mixed $raw): Carbon
    {
        if (! $this->reminders->enabledFor($organization)) {
            throw ValidationException::withMessages(['mode' => 'O envio agendado não está disponível no plano atual da organização.']);
        }

        $timezone = $organization->timezone !== '' ? $organization->timezone : Organization::DEFAULT_TIMEZONE;

        try {
            $at = is_string($raw) ? Carbon::createFromFormat(ScheduledSend::INPUT_FORMAT, $raw, $timezone) : null;
        } catch (Throwable) {
            $at = null;
        }

        if (! $at instanceof Carbon) {
            throw ValidationException::withMessages(['scheduled_for' => 'Informe a data e a hora do envio.']);
        }

        $at = $at->utc()->startOfMinute();
        $limits = ScheduledSend::limits();

        if ($at->lessThan(Carbon::now()->addMinutes($limits['min_lead_minutes']))) {
            throw ValidationException::withMessages(['scheduled_for' => "Escolha um horário com pelo menos {$limits['min_lead_minutes']} minutos de antecedência."]);
        }

        if ($at->greaterThan(Carbon::now()->addDays($limits['max_days']))) {
            throw ValidationException::withMessages(['scheduled_for' => "O envio pode ser agendado para no máximo {$limits['max_days']} dias à frente."]);
        }

        return $at;
    }
}
