<?php

namespace App\Services\Envelopes\Sending;

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Models\Envelope;
use App\Models\PlanConsumption;
use App\Models\Recipient;
use App\Services\Documents\EnvelopeReadiness as DocumentReadiness;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\Envelopes\EnvelopeReadiness;
use App\Services\Envelopes\Sending\Exceptions\SendingException;
use App\Services\Plans\Exceptions\SendingBlockedException;
use App\Services\Plans\PlanLedger;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Enviar para assinatura (arquitetura §3.2 `ready → in_progress`, §3.3, §5 item 5).
 *
 * Duas fases, deliberadamente separadas:
 *
 * 1. **Transação curta** com `SELECT ... FOR UPDATE` no envelope. Só banco: revalida a
 *    completude, congela `sent_document_version_id`, gera `verification_code`, calcula
 *    `expires_at`, reserva o consumo do plano e transiciona para `in_progress`. Nenhuma
 *    chamada externa, nenhuma fila, nenhum e-mail — a regra "nada de transação aberta
 *    durante chamada externa" vale aqui.
 *
 * 2. **Fora da transação**: emissão dos links e despacho dos convites (ordem 1 no
 *    sequencial, todos no paralelo). Deu certo → o consumo vira `committed`. Falhou → o
 *    consumo é `released` e o erro é PT-BR.
 *
 * Idempotência contra corrida (dois cliques, duas abas): o lock serializa; a segunda
 * requisição encontra o envelope já `in_progress` e para com `already_sent`. A rede de
 * segurança é `plan_consumptions.idempotency_key = envelope:{id}:send`, UNIQUE — nem em
 * caso de falha do lock (SQLite) o plano é debitado duas vezes.
 */
class SendEnvelope
{
    /** Tentativas de gerar um `verification_code` sem colidir com o UNIQUE da coluna. */
    private const CODE_ATTEMPTS = 8;

    public function __construct(
        private readonly PlanLedger $ledger,
        private readonly InvitationDispatcher $invitations,
        private readonly DocumentReadiness $readiness,
        private readonly AccessLinks $links,
    ) {}

    /**
     * @return array{envelope: Envelope, invitations: int}
     *
     * @throws SendingException|SendingBlockedException
     */
    public function handle(Envelope $envelope): array
    {
        try {
            [$envelope, $consumption] = $this->commitSend($envelope);
        } catch (SendingException $exception) {
            // A transação foi desfeita junto com o recálculo feito sob lock. Sem gravar de
            // novo, a lista continuaria mostrando "Pronto para enviar" um documento que já
            // não está — e o usuário bateria no mesmo erro no próximo clique.
            if (in_array($exception->errorCode, ['incomplete', 'no_sent_version'], true)) {
                $fresh = $envelope->fresh();

                if ($fresh !== null) {
                    $this->readiness->recompute($fresh);
                }
            }

            throw $exception;
        }

        try {
            $dispatched = $this->invitations->dispatchInitial($envelope);
        } catch (Throwable $exception) {
            // O envio é DESFEITO, não apenas estornado. Antes, a fase 1 continuava
            // commitada: o envelope ficava `in_progress` (vivo, assinável, sem link
            // nenhum) com o consumo do plano `released` — e o remetente entregava os
            // convites pelo botão "Lembrar pendentes", percorrendo o ciclo inteiro sem
            // que o plano fosse debitado uma única vez.
            $this->revertSend($envelope);
            $this->ledger->release($consumption, $envelope, 'dispatch_failed');

            Log::error('Falha ao despachar os convites de um envelope recém-enviado.', [
                'envelope' => $envelope->ulid,
                'exception' => $exception::class,
                'message' => Str::limit($exception->getMessage(), 300, ''),
            ]);

            throw SendingException::dispatchFailed();
        }

        $this->ledger->commit($consumption, $envelope);

        return ['envelope' => $envelope->refresh(), 'invitations' => $dispatched];
    }

    /**
     * Fase 1 — tudo o que precisa ser atômico.
     *
     * @return array{0: Envelope, 1: PlanConsumption}
     */
    private function commitSend(Envelope $envelope): array
    {
        return DB::transaction(function () use ($envelope): array {
            /** @var Envelope $locked */
            $locked = Envelope::withoutOrganizationScope()
                ->whereKey($envelope->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === EnvelopeStatus::InProgress || ! $locked->status->isDraftLike()) {
                throw $locked->sent_at !== null
                    ? SendingException::alreadySent()
                    : SendingException::invalidStatus();
            }

            // A completude é revalidada AGORA, sob lock: o wizard pode ter ficado aberto
            // enquanto alguém removia o documento ou um signatário em outra aba.
            $this->readiness->recompute($locked);
            $locked->refresh();

            if ($locked->status !== EnvelopeStatus::Ready) {
                throw SendingException::incomplete(EnvelopeReadiness::issues($locked));
            }

            // Segunda leitura da mesma invariante, e de propósito: o status é um resumo
            // gravado; a lista de pendências é a que a tela mostra. Se as duas
            // discordarem, o envio para — nunca sai um documento cuja própria tela
            // acusava uma pendência.
            $issues = EnvelopeReadiness::issues($locked);

            if ($issues !== []) {
                throw SendingException::incomplete($issues);
            }

            $versionId = $locked->document?->current_version_id;

            if ($versionId === null) {
                throw SendingException::noSentVersion();
            }

            $subscription = $this->ledger->subscriptionFor((int) $locked->organization_id, lock: true);

            if ($subscription === null) {
                throw SendingBlockedException::noSubscription();
            }

            $this->ledger->assertCanSend($subscription);

            $now = Carbon::now();

            $locked->transitionTo(EnvelopeStatus::InProgress);
            $locked->forceFill([
                'sent_document_version_id' => $versionId,
                'sent_at' => $locked->sent_at ?? $now,
                'expires_at' => $this->expiresAt($locked, $now),
                'current_order' => 1,
                'terms_version' => $locked->terms_version ?? (string) config('assinavelox.terms_version'),
                'verification_code' => $locked->verification_code ?? $this->uniqueVerificationCode(),
            ])->save();

            $consumption = $this->ledger->reserve($locked, $subscription);

            EnvelopeAudit::record($locked, AuditEventType::EnvelopeSent, [
                'signing_order' => $locked->signing_order->value,
                'recipients' => $locked->recipients()->count(),
                'document_version' => $versionId,
                'expires_at' => $locked->expires_at?->toIso8601String(),
                'verification_code' => $locked->verification_code,
            ]);

            return [$locked, $consumption];
        }, 3);
    }

    /**
     * Desfaz a fase 1 quando o despacho dos convites falha.
     *
     * Volta o envelope para `ready` e apaga tudo o que só faz sentido em um envelope
     * enviado: `sent_at`, `expires_at`, a versão congelada e a vez corrente. O
     * `verification_code` fica — ele é UNIQUE, já foi gerado e será o mesmo quando o
     * remetente clicar em "Enviar" de novo. Os links porventura emitidos antes da falha
     * são revogados e quem chegou a ser notificado volta a `pending`: um convite de
     * envelope que não está mais enviado não pode abrir.
     *
     * Não é `transitionTo()`: `in_progress → ready` não existe na máquina de estados
     * (arquitetura §3.2) justamente porque não é uma transição de negócio — é a reversão
     * de um envio que não chegou a acontecer.
     */
    private function revertSend(Envelope $envelope): void
    {
        DB::transaction(function () use ($envelope): void {
            /** @var Envelope|null $locked */
            $locked = Envelope::withoutOrganizationScope()
                ->whereKey($envelope->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== EnvelopeStatus::InProgress) {
                return;
            }

            $this->links->revokeForEnvelope($locked);

            Recipient::withoutOrganizationScope()
                ->where('envelope_id', $locked->getKey())
                ->whereIn('status', [RecipientStatus::Notified->value, RecipientStatus::Viewed->value])
                ->update([
                    'status' => RecipientStatus::Pending->value,
                    'last_notified_at' => null,
                    'updated_at' => Carbon::now(),
                ]);

            $locked->forceFill([
                'status' => EnvelopeStatus::Ready,
                'sent_at' => null,
                'expires_at' => null,
                'sent_document_version_id' => null,
                'current_order' => 1,
            ])->save();

            EnvelopeAudit::record($locked, AuditEventType::EnvelopeUpdated, [
                'reverted' => 'dispatch_failed',
                'status' => EnvelopeStatus::Ready->value,
            ]);
        });

        $envelope->refresh();
    }

    /**
     * RECONCILIACAO Q22: fim do dia (23:59:59) no fuso da ORGANIZAÇÃO, `expiration_days`
     * dias depois do envio. O valor é gravado em UTC, como todo timestamp.
     */
    public function expiresAt(Envelope $envelope, ?CarbonInterface $sentAt = null): CarbonInterface
    {
        $organization = $envelope->organization;
        $timezone = $organization->timezone !== '' ? $organization->timezone : config('app.timezone', 'UTC');

        $days = (int) ($envelope->setting('expiration_days')
            ?? $organization->setting('default_expiration_days')
            ?? config('assinavelox.default_expiration_days', 30));

        $min = (int) config('assinavelox.expiration_days.min', 1);
        $max = (int) config('assinavelox.expiration_days.max', 90);
        $days = max($min, min($max, $days));

        return ($sentAt ?? Carbon::now())
            ->copy()
            ->setTimezone($timezone)
            ->addDays($days)
            ->endOfDay()
            ->setTimezone('UTC');
    }

    /**
     * Código de 12 caracteres do alfabeto sem ambíguos (RECONCILIACAO §1). O UNIQUE da
     * coluna é a autoridade; aqui só evitamos a colisão previsível.
     */
    private function uniqueVerificationCode(): string
    {
        for ($attempt = 0; $attempt < self::CODE_ATTEMPTS; $attempt++) {
            $code = Envelope::generateVerificationCode();

            $taken = Envelope::withoutOrganizationScope()
                ->withTrashed()
                ->where('verification_code', $code)
                ->exists();

            if (! $taken) {
                return $code;
            }
        }

        throw new SendingException(
            'verification_code_collision',
            'Não foi possível gerar o código de verificação do documento. Tente enviar novamente.',
        );
    }
}
