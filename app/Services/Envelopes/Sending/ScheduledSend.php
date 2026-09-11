<?php

namespace App\Services\Envelopes\Sending;

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Jobs\Envelopes\DispatchScheduledEnvelope;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Organization;
use App\Notifications\Envelopes\ScheduledSendFailedNotification;
use App\Services\Documents\EnvelopeReadiness as DocumentReadiness;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\Envelopes\EnvelopeReadiness;
use App\Services\Envelopes\Reminders\RemindersFeature;
use App\Services\Envelopes\Sending\Exceptions\SendingException;
use App\Services\Plans\Exceptions\SendingBlockedException;
use App\Services\Plans\PlanLedger;
use App\Support\CurrentOrganization;
use App\Support\Timezones;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envio agendado (Fase 2 §2.5) — `envelopes.scheduled_send_at` (UTC), sem novo status.
 *
 * Ciclo:
 *
 *   ready ──agendar──▶ ready + scheduled_send_at ──(hora chegou)──▶ SendEnvelope ──▶ in_progress
 *                        │
 *                        ├── cancelar (usuário) ............ envelope.schedule_canceled {reason: user}
 *                        ├── editar o envelope ............. envelope.schedule_canceled {reason: edited}
 *                        ├── enviar agora .................. envelope.schedule_canceled {reason: sent_now}
 *                        ├── cancelar o envelope ........... envelope.schedule_canceled {reason: envelope_canceled}
 *                        └── disparo falhou (cota, pendência, flag desligada)
 *                                                           envelope.schedule_canceled {reason: <código>}
 *                                                           + aviso ao remetente
 *
 * **Validação dupla**: completude e cota são verificadas ao AGENDAR (para o usuário saber na
 * hora) e de novo no DISPARO, pelo próprio SendEnvelope, sob lock — o envelope pode ter sido
 * editado e a cota consumida por outros envios nesse meio tempo. A cota só é RESERVADA no
 * disparo (SendEnvelope, idempotente por `envelope:{id}:send`).
 *
 * **Uma única vez**: o disparo começa por uma reivindicação atômica —
 * `UPDATE envelopes SET scheduled_send_at = NULL WHERE id = ? AND scheduled_send_at = ?`.
 * Só um processo vê a linha afetada; os demais param. SendEnvelope ainda tem a própria trava
 * (`FOR UPDATE` + `already_sent`) e a chave única do consumo do plano.
 *
 * **Edição cancela**: o agendamento guarda o id do evento `envelope.scheduled`
 * (`scheduled_send_audit_id`). Qualquer evento de edição registrado DEPOIS dele (ver
 * `editEventTypes()`) cancela o agendamento — na varredura de cada minuto e, de novo, no
 * disparo. Quem edita pode também chamar `cancelBecauseEdited()` para o cancelamento ser
 * imediato; a detecção pela trilha é a rede de segurança que não depende de ninguém lembrar.
 */
class ScheduledSend
{
    public const COLUMN = 'scheduled_send_at';

    public const MARKER_COLUMN = 'scheduled_send_audit_id';

    /** Formato gravado (UTC). */
    public const STORAGE_FORMAT = 'Y-m-d H:i:s';

    /** Formato aceito do navegador (`<input type="datetime-local">`), no fuso da organização. */
    public const INPUT_FORMAT = 'Y-m-d\TH:i';

    public function __construct(
        private readonly SendEnvelope $sender,
        private readonly PlanLedger $ledger,
        private readonly DocumentReadiness $readiness,
        private readonly RemindersFeature $feature,
    ) {}

    /**
     * Tipos de evento que contam como edição do envelope. Strings, e não casos do enum, para
     * incluir eventos de outras áreas da Fase 2 sem acoplar a existência deles.
     *
     * @return list<string>
     */
    public static function editEventTypes(): array
    {
        return [
            AuditEventType::EnvelopeUpdated->value,
            AuditEventType::DocumentUploaded->value,
            AuditEventType::DocumentRemoved->value,
            AuditEventType::FieldsUpdated->value,
            AuditEventType::RecipientsUpdated->value,
            'documents.reordered',
        ];
    }

    /**
     * @return array{min_lead_minutes: int, max_days: int}
     */
    public static function limits(): array
    {
        return [
            'min_lead_minutes' => max(1, (int) config('assinavelox.scheduled_send.min_lead_minutes', 5)),
            'max_days' => max(1, (int) config('assinavelox.scheduled_send.max_days', 60)),
        ];
    }

    public static function scheduledAt(Envelope $envelope): ?Carbon
    {
        return self::parse($envelope->getAttribute(self::COLUMN));
    }

    public static function parse(mixed $raw): ?Carbon
    {
        if ($raw instanceof DateTimeInterface) {
            return Carbon::instance($raw)->utc();
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $parsed = Carbon::createFromFormat(self::STORAGE_FORMAT, substr($raw, 0, 19), 'UTC');
        } catch (Throwable) {
            return null;
        }

        return $parsed instanceof Carbon ? $parsed : null;
    }

    /**
     * Agenda (ou reagenda) o envio.
     *
     * @throws SendingException|SendingBlockedException
     */
    public function schedule(Envelope $envelope, CarbonInterface $at): Envelope
    {
        $at = Carbon::instance($at)->utc()->startOfMinute();
        $now = Carbon::now();
        $limits = self::limits();

        if ($at->lessThan($now->copy()->addMinutes($limits['min_lead_minutes']))) {
            throw new SendingException(
                'schedule_too_soon',
                "Escolha um horário com pelo menos {$limits['min_lead_minutes']} minutos de antecedência.",
            );
        }

        if ($at->greaterThan($now->copy()->addDays($limits['max_days']))) {
            throw new SendingException(
                'schedule_too_far',
                "O envio pode ser agendado para no máximo {$limits['max_days']} dias à frente.",
            );
        }

        try {
            DB::transaction(function () use ($envelope, $at): void {
                /** @var Envelope $locked */
                $locked = Envelope::withoutOrganizationScope()
                    ->whereKey($envelope->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $locked->status->isDraftLike()) {
                    throw $locked->sent_at !== null
                        ? SendingException::alreadySent()
                        : SendingException::invalidStatus();
                }

                // Mesma dupla verificação do SendEnvelope: status gravado E lista de pendências.
                $this->readiness->recompute($locked);
                $locked->refresh();

                if ($locked->status !== EnvelopeStatus::Ready) {
                    throw SendingException::incomplete(EnvelopeReadiness::issues($locked));
                }

                $issues = EnvelopeReadiness::issues($locked);

                if ($issues !== []) {
                    throw SendingException::incomplete($issues);
                }

                // Cota verificada, NÃO reservada: a reserva é do disparo.
                $subscription = $this->ledger->subscriptionFor((int) $locked->organization_id);

                if ($subscription === null) {
                    throw SendingBlockedException::noSubscription();
                }

                $this->ledger->assertCanSend($subscription);

                $previous = self::scheduledAt($locked);

                $event = EnvelopeAudit::record($locked, AuditEventType::EnvelopeScheduled, array_filter([
                    'scheduled_for' => $at->toIso8601String(),
                    'previous' => $previous?->toIso8601String(),
                ]));

                $locked->forceFill([
                    self::COLUMN => $at->format(self::STORAGE_FORMAT),
                    self::MARKER_COLUMN => $event->getKey(),
                ])->save();
            }, 3);
        } catch (SendingException $exception) {
            // O recálculo sob lock foi desfeito com a transação; grava de novo para a tela
            // não continuar anunciando "pronto" um envelope que já não está.
            if ($exception->errorCode === 'incomplete') {
                $fresh = $envelope->fresh();

                if ($fresh !== null) {
                    $this->readiness->recompute($fresh);
                }
            }

            throw $exception;
        }

        return $envelope->refresh();
    }

    /**
     * Cancela o agendamento. `$expectedMarker` evita cancelar um REagendamento feito entre a
     * leitura e o lock (a varredura usa; o usuário não precisa).
     *
     * @param  array<string, mixed>  $extra
     */
    public function cancel(Envelope $envelope, string $reason = 'user', ?int $expectedMarker = null, array $extra = []): bool
    {
        $cleared = DB::transaction(function () use ($envelope, $reason, $expectedMarker, $extra): bool {
            /** @var Envelope|null $locked */
            $locked = Envelope::withoutOrganizationScope()
                ->withTrashed()
                ->whereKey($envelope->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return false;
            }

            if ($expectedMarker !== null && (int) $locked->getAttribute(self::MARKER_COLUMN) !== $expectedMarker) {
                return false;
            }

            return self::clearWithinLock($locked, $reason, $extra);
        }, 3);

        if ($cleared && $envelope->exists) {
            $envelope->refresh();
        }

        return $cleared;
    }

    /**
     * PONTO DE EXTENSÃO para quem edita um envelope `ready` (EnvelopeController::update,
     * RecipientSync, FieldSync, EnvelopeDocumentController): cancela na hora, com o motivo.
     * Sem a chamada, a varredura de cada minuto cancela do mesmo jeito pela trilha.
     */
    public function cancelBecauseEdited(Envelope $envelope, string $change): bool
    {
        return $this->cancel($envelope, 'edited', null, ['change' => Str::limit($change, 40, '')]);
    }

    /**
     * Limpa o agendamento de um envelope JÁ bloqueado por quem chama (SendEnvelope,
     * CancelEnvelope). Não faz nada — nem grava trilha — quando não há agendamento.
     *
     * @param  array<string, mixed>  $extra
     */
    public static function clearWithinLock(Envelope $locked, string $reason, array $extra = []): bool
    {
        $at = self::scheduledAt($locked);

        if ($at === null) {
            return false;
        }

        $locked->forceFill([self::COLUMN => null, self::MARKER_COLUMN => null])->save();

        self::recordCanceled($locked, $reason, $at, $extra);

        return true;
    }

    public function editedSince(Envelope $envelope, int $marker): bool
    {
        return AuditEvent::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('id', '>', $marker)
            ->whereIn('event_type', self::editEventTypes())
            ->exists();
    }

    /**
     * Varredura de cada minuto (`envelopes:dispatch-scheduled`): cancela o que foi editado ou
     * saiu de `ready`, e despacha um job por envelope vencido.
     *
     * @return array{dispatched: int, canceled: int}
     */
    public function sweep(?CarbonInterface $now = null, ?int $limit = null): array
    {
        $now ??= Carbon::now();
        $limit ??= (int) config('assinavelox.scheduled_send.batch_size', 200);

        $dispatched = 0;
        $canceled = 0;

        $envelopes = Envelope::withoutOrganizationScope()
            ->withTrashed()
            ->whereNotNull(self::COLUMN)
            ->orderBy(self::COLUMN)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        foreach ($envelopes as $envelope) {
            $marker = $envelope->getAttribute(self::MARKER_COLUMN);
            $marker = $marker !== null ? (int) $marker : null;

            if ($envelope->trashed()) {
                $canceled += (int) $this->cancel($envelope, 'deleted', $marker);

                continue;
            }

            if (! $envelope->status->isDraftLike()) {
                $canceled += (int) $this->cancel($envelope, 'invalid_status', $marker);

                continue;
            }

            if ($marker !== null && $this->editedSince($envelope, $marker)) {
                $canceled += (int) $this->cancel($envelope, 'edited', $marker);

                continue;
            }

            $at = self::scheduledAt($envelope);

            if ($at !== null && $at->lessThanOrEqualTo($now)) {
                DispatchScheduledEnvelope::dispatch((int) $envelope->getKey(), $at->format(self::STORAGE_FORMAT));
                $dispatched++;
            }
        }

        return ['dispatched' => $dispatched, 'canceled' => $canceled];
    }

    /**
     * Disparo de UM envelope (job DispatchScheduledEnvelope).
     *
     * @param  string  $expectedAt  valor de `scheduled_send_at` visto pela varredura (STORAGE_FORMAT)
     * @return string `sent` | `noop` | `canceled:<motivo>`
     */
    public function fire(int $envelopeId, string $expectedAt): string
    {
        /** @var Envelope|null $envelope */
        $envelope = Envelope::withoutOrganizationScope()->whereKey($envelopeId)->first();

        if ($envelope === null) {
            return 'noop';
        }

        $marker = $envelope->getAttribute(self::MARKER_COLUMN);
        $scheduledFor = self::parse($expectedAt);

        // Reivindicação atômica: um único processo passa daqui.
        $claimed = Envelope::withoutOrganizationScope()
            ->whereKey($envelopeId)
            ->where(self::COLUMN, $expectedAt)
            ->update([self::COLUMN => null, self::MARKER_COLUMN => null]);

        if ($claimed !== 1) {
            return 'noop';
        }

        $envelope->refresh();

        /** @var Organization|null $organization */
        $organization = Organization::query()->whereKey($envelope->organization_id)->first();

        if ($organization === null) {
            return 'noop';
        }

        /** @var string */
        return CurrentOrganization::instance()->runAs($organization, function () use ($envelope, $organization, $marker, $scheduledFor): string {
            if ($marker !== null && $this->editedSince($envelope, (int) $marker)) {
                self::recordCanceled($envelope, 'edited', $scheduledFor);

                return 'canceled:edited';
            }

            if (! $this->feature->enabledFor($organization)) {
                return $this->fail($envelope, $scheduledFor, 'feature_disabled', 'O envio agendado não está disponível no plano atual da organização.');
            }

            try {
                $this->sender->handle($envelope, $scheduledFor);
            } catch (SendingBlockedException $exception) {
                return $this->fail($envelope, $scheduledFor, $exception->errorCode, $exception->getMessage());
            } catch (SendingException $exception) {
                return $this->fail($envelope, $scheduledFor, $exception->errorCode, $exception->getMessage());
            }

            return 'sent';
        });
    }

    /**
     * Marca o disparo como perdido quando o job falha de vez (exceção inesperada).
     */
    public function markDispatchFailed(int $envelopeId, string $expectedAt): void
    {
        /** @var Envelope|null $envelope */
        $envelope = Envelope::withoutOrganizationScope()->whereKey($envelopeId)->first();

        if ($envelope === null || $envelope->status !== EnvelopeStatus::Ready) {
            return;
        }

        $this->fail($envelope, self::parse($expectedAt), 'dispatch_error', 'Ocorreu um erro inesperado ao enviar o documento na hora agendada.');
    }

    /**
     * Estado do agendamento para a interface (wizard, detalhe, lista).
     *
     * @return array{at: string, at_local: string, input_value: string, timezone: string, timezone_label: string}|null
     */
    public static function present(Envelope $envelope): ?array
    {
        $at = self::scheduledAt($envelope);

        if ($at === null) {
            return null;
        }

        $timezone = $envelope->organization->timezone ?? Organization::DEFAULT_TIMEZONE;
        $local = $at->copy()->setTimezone($timezone !== '' ? $timezone : Organization::DEFAULT_TIMEZONE);

        return [
            'at' => $at->toIso8601String(),
            'at_local' => $local->format('d/m/Y \à\s H:i'),
            'input_value' => $local->format(self::INPUT_FORMAT),
            'timezone' => $local->getTimezone()->getName(),
            // "horário de Brasília (GMT-3)" — o que a interface mostra (nunca o IANA cru).
            'timezone_label' => Timezones::humanLabel($local->getTimezone()->getName()),
        ];
    }

    private function fail(Envelope $envelope, ?Carbon $scheduledFor, string $code, string $message): string
    {
        self::recordCanceled($envelope, $code, $scheduledFor);

        $creator = $envelope->creator;

        if ($creator !== null) {
            try {
                $creator->notify(new ScheduledSendFailedNotification($envelope, $message, (string) Str::ulid()));
            } catch (Throwable $exception) {
                Log::warning('Não foi possível avisar o remetente sobre o envio agendado que falhou.', [
                    'envelope' => $envelope->ulid,
                    'exception' => $exception::class,
                ]);
            }
        }

        return 'canceled:'.$code;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private static function recordCanceled(Envelope $envelope, string $reason, ?Carbon $scheduledFor, array $extra = []): void
    {
        EnvelopeAudit::record($envelope, AuditEventType::EnvelopeScheduleCanceled, array_merge([
            'reason' => $reason,
            'scheduled_for' => $scheduledFor?->toIso8601String(),
        ], $extra));
    }
}
