<?php

namespace App\Listeners\Risk;

use App\Enums\AuditEventType;
use App\Enums\DeliveryStatus;
use App\Jobs\Risk\EvaluateRiskEvent;
use App\Models\AuditEvent;
use App\Models\DeliveryAttempt;
use App\Models\PaymentChargeback;
use App\Models\User;
use App\Services\Risk\RiskDetector;
use App\Services\Risk\RiskFeature;
use App\Services\Risk\SubjectKeys;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ganchos do antifraude sobre eventos QUE JÁ EXISTEM (nenhum serviço de domínio foi alterado
 * para emiti-los). Registrado explicitamente por App\Services\Risk\RiskServiceProvider — os
 * métodos não se chamam `handle*` para a descoberta automática de eventos não os registrar de
 * novo.
 *
 * Com a flag `antifraud` desligada, cada método sai na primeira linha, sem consulta nem fila.
 * Nunca lança: o evento foi gravado por uma operação de domínio (envio, aceite, cobrança,
 * cadastro) que não pode ser desfeita nem virar erro por causa do antifraude.
 */
final class QueueRiskEvaluation
{
    public function onAuditEventCreated(AuditEvent $event): void
    {
        if (! RiskFeature::enabled()) {
            return;
        }

        if (! in_array($event->event_type, [
            AuditEventType::EnvelopeSent,
            AuditEventType::ChallengeFailed,
            AuditEventType::ChallengePinFailed,
        ], true)) {
            return;
        }

        $this->dispatch(EvaluateRiskEvent::AUDIT_EVENT, ['id' => (int) $event->getKey()]);
    }

    public function onDeliveryAttemptSaved(DeliveryAttempt $attempt): void
    {
        if (! RiskFeature::enabled()) {
            return;
        }

        $failed = in_array($attempt->status, [DeliveryStatus::Failed, DeliveryStatus::Bounced], true);
        $purpose = $attempt->purpose->value;

        if (! $failed
            || ! ($attempt->wasRecentlyCreated || $attempt->wasChanged('status'))
            || ! in_array($purpose, RiskDetector::invitationPurposes(), true)) {
            return;
        }

        $this->dispatch(EvaluateRiskEvent::DELIVERY_ATTEMPT, ['id' => (int) $attempt->getKey()]);
    }

    public function onChargebackCreated(PaymentChargeback $chargeback): void
    {
        if (! RiskFeature::enabled()) {
            return;
        }

        $this->dispatch(EvaluateRiskEvent::CHARGEBACK, ['id' => (int) $chargeback->getKey()]);
    }

    /**
     * Cadastro: a rede (IP truncado) e o dispositivo DECLARADO pelo cliente (cabeçalho
     * `X-Device-Id` ou campo `device_id`, quando existirem) viram HMAC aqui, na requisição; a
     * fila nunca vê o IP completo nem o identificador bruto.
     */
    public function onUserRegistered(Registered $event): void
    {
        if (! RiskFeature::enabled()) {
            return;
        }

        try {
            $user = $event->user;
            $organizationId = $user instanceof User ? $user->fresh()?->current_organization_id : null;

            $request = request();
            $prefix = SubjectKeys::truncateIp($request->ip());
            $device = $request->header('X-Device-Id') ?? $request->input('device_id');
            $device = is_string($device) ? mb_substr(trim($device), 0, 200) : '';

            $this->dispatch(EvaluateRiskEvent::SIGNUP, [
                'organization_id' => $organizationId !== null ? (int) $organizationId : null,
                'ip_key' => $prefix !== null ? SubjectKeys::digest('ip:'.$prefix) : null,
                'ip_prefix' => $prefix,
                'device_key' => $device !== '' ? SubjectKeys::digest('device:'.$device) : null,
            ]);
        } catch (Throwable $exception) {
            $this->logFailure(EvaluateRiskEvent::SIGNUP, $exception);
        }
    }

    /**
     * @param  array<string, int|string|null>  $context
     */
    private function dispatch(string $kind, array $context): void
    {
        try {
            EvaluateRiskEvent::dispatch($kind, $context);
        } catch (Throwable $exception) {
            $this->logFailure($kind, $exception);
        }
    }

    private function logFailure(string $kind, Throwable $exception): void
    {
        Log::error('risk.listener_failed', [
            'kind' => $kind,
            'exception' => $exception::class,
            'message' => mb_substr($exception->getMessage(), 0, 300),
        ]);
    }
}
