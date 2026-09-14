<?php

namespace App\Jobs\Risk;

use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\DeliveryAttempt;
use App\Models\PaymentChargeback;
use App\Services\Risk\RiskDetector;
use App\Services\Risk\RiskFeature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Avalia as regras do antifraude para um evento do domínio (roadmap §3.7: "motor de regras
 * declarativas rodando em fila sobre eventos").
 *
 * O payload só leva IDs internos e, no cadastro, HMACs e o prefixo truncado da rede — nunca IP
 * completo, e-mail, token ou conteúdo (T10). Despachado depois do commit. Nunca propaga
 * exceção: com a fila `sync`, um erro aqui não pode virar erro de quem enviou ou assinou.
 */
class EvaluateRiskEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const AUDIT_EVENT = 'audit_event';

    public const DELIVERY_ATTEMPT = 'delivery_attempt';

    public const CHARGEBACK = 'chargeback';

    public const SIGNUP = 'signup';

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @param  array<string, int|string|null>  $context
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $context,
    ) {
        $this->onQueue((string) config('assinavelox.queues.default', 'default'));
        $this->afterCommit();
    }

    public function handle(RiskDetector $detector): void
    {
        if (! RiskFeature::enabled()) {
            return;
        }

        try {
            match ($this->kind) {
                self::AUDIT_EVENT => $this->auditEvent($detector),
                self::DELIVERY_ATTEMPT => $this->deliveryAttempt($detector),
                self::CHARGEBACK => $this->chargeback($detector),
                self::SIGNUP => $detector->signup(
                    isset($this->context['organization_id']) ? (int) $this->context['organization_id'] : null,
                    self::stringOrNull($this->context['ip_key'] ?? null),
                    self::stringOrNull($this->context['ip_prefix'] ?? null),
                    self::stringOrNull($this->context['device_key'] ?? null),
                ),
                default => null,
            };
        } catch (Throwable $exception) {
            Log::error('risk.evaluation_failed', [
                'kind' => $this->kind,
                'exception' => $exception::class,
                'message' => mb_substr($exception->getMessage(), 0, 300),
            ]);
        }
    }

    private function auditEvent(RiskDetector $detector): void
    {
        /** @var AuditEvent|null $event */
        $event = AuditEvent::withoutOrganizationScope()->find((int) ($this->context['id'] ?? 0));

        match ($event?->event_type) {
            AuditEventType::EnvelopeSent => $detector->envelopeSent($event),
            AuditEventType::ChallengeFailed, AuditEventType::ChallengePinFailed => $detector->challengeFailed($event),
            default => null,
        };
    }

    private function deliveryAttempt(RiskDetector $detector): void
    {
        /** @var DeliveryAttempt|null $attempt */
        $attempt = DeliveryAttempt::withoutOrganizationScope()->find((int) ($this->context['id'] ?? 0));

        if ($attempt !== null) {
            $detector->deliveryFailed($attempt);
        }
    }

    private function chargeback(RiskDetector $detector): void
    {
        /** @var PaymentChargeback|null $chargeback */
        $chargeback = PaymentChargeback::withoutOrganizationScope()->find((int) ($this->context['id'] ?? 0));

        if ($chargeback !== null) {
            $detector->chargebackCreated($chargeback);
        }
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
