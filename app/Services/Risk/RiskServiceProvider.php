<?php

namespace App\Services\Risk;

use App\Listeners\Risk\QueueRiskEvaluation;
use App\Models\AuditEvent;
use App\Models\DeliveryAttempt;
use App\Models\PaymentChargeback;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Antifraude (Fase 3 §3.7, P3-RISK). Registrado em bootstrap/providers.php.
 *
 * Escuta eventos que o domínio já emite — criação de `audit_events`, gravação de
 * `delivery_attempts`, criação de `payment_chargebacks` e o `Registered` do cadastro — sem
 * tocar em nenhum serviço de domínio. Com a flag `antifraud` desligada, os listeners saem antes
 * de qualquer consulta.
 */
final class RiskServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen('eloquent.created: '.AuditEvent::class, [QueueRiskEvaluation::class, 'onAuditEventCreated']);
        Event::listen('eloquent.saved: '.DeliveryAttempt::class, [QueueRiskEvaluation::class, 'onDeliveryAttemptSaved']);
        Event::listen('eloquent.created: '.PaymentChargeback::class, [QueueRiskEvaluation::class, 'onChargebackCreated']);
        Event::listen(Registered::class, [QueueRiskEvaluation::class, 'onUserRegistered']);
    }
}
