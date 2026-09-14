<?php

namespace App\Services\Risk;

use App\Enums\AuditEventType;
use App\Enums\DeliveryPurpose;
use App\Enums\DeliveryStatus;
use App\Enums\MembershipStatus;
use App\Models\AuditEvent;
use App\Models\DeliveryAttempt;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\PaymentChargeback;
use App\Models\Recipient;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * As regras declarativas (roadmap §3.7): janelas de tempo e limiares sobre dados que o domínio
 * JÁ grava — `audit_events`, `delivery_attempts`, `recipients`, `payment_chargebacks` — mais
 * a contagem mínima de cadastros em `risk_observations`. Nenhuma regra lê conteúdo de
 * documento, e-mail completo, telefone ou CPF para compor a evidência: só contagens, IDs
 * opacos e o prefixo truncado da rede.
 *
 * Cada método dispara quando a contagem ATINGE o limiar (`>=`) e não dispara abaixo dele.
 * Chamado pelo job App\Jobs\Risk\EvaluateRiskEvent (fila), nunca na requisição do usuário.
 */
final class RiskDetector
{
    /** Teto de linhas lidas para contar destinatários externos (protege a fila). */
    private const RECIPIENT_SCAN_CAP = 20000;

    public function __construct(private readonly RiskObservations $observations) {}

    /**
     * `envelope.sent` → pico de envios em conta nova e rajada de destinatários externos.
     */
    public function envelopeSent(AuditEvent $event): void
    {
        $organization = Organization::withTrashed()->find($event->organization_id);

        if ($organization === null) {
            return;
        }

        $envelope = $event->envelope_id !== null
            ? Envelope::withoutOrganizationScope()->find($event->envelope_id)
            : null;

        $this->sendSpike($organization, $envelope);
        $this->externalRecipients($organization, $envelope);
    }

    /**
     * `challenge.failed` / `challenge.pin_failed` → força bruta por link e por rede.
     */
    public function challengeFailed(AuditEvent $event): void
    {
        $rule = RiskRule::CodeBruteForce;
        $organization = Organization::withTrashed()->find($event->organization_id);

        if ($organization === null) {
            return;
        }

        $window = $rule->windowMinutes();
        $since = Carbon::now()->subMinutes($window);
        $types = [AuditEventType::ChallengeFailed->value, AuditEventType::ChallengePinFailed->value];
        $envelope = $event->envelope_id !== null ? Envelope::withoutOrganizationScope()->find($event->envelope_id) : null;

        if ($event->recipient_id !== null) {
            $perLink = max(1, (int) $rule->config('per_link', 10));
            $failures = AuditEvent::withoutOrganizationScope()
                ->where('recipient_id', $event->recipient_id)
                ->whereIn('event_type', $types)
                ->where('occurred_at', '>=', $since)
                ->count();

            if ($failures >= $perLink) {
                $recipient = Recipient::withoutOrganizationScope()->find($event->recipient_id);

                RiskSignals::record($rule->value, $organization, [
                    'scope' => 'link',
                    'failures_in_window' => $failures,
                    'window_minutes' => $window,
                    'threshold' => $perLink,
                    'recipient' => $recipient?->ulid,
                ], $envelope, 'link:'.$event->recipient_id);
            }
        }

        $ip = $event->ip_address;

        if (is_string($ip) && $ip !== '') {
            $perIp = max(1, (int) $rule->config('per_ip', 25));
            $failures = AuditEvent::withoutOrganizationScope()
                ->where('ip_address', $ip)
                ->whereIn('event_type', $types)
                ->where('occurred_at', '>=', $since)
                ->count();

            if ($failures >= $perIp) {
                $prefix = SubjectKeys::truncateIp($ip);

                RiskSignals::record($rule->value, $organization, [
                    'scope' => 'ip',
                    'failures_in_window' => $failures,
                    'window_minutes' => $window,
                    'threshold' => $perIp,
                    'ip_prefix' => $prefix,
                ], null, 'ip:'.($prefix ?? $ip));
            }
        }
    }

    /**
     * Convite/reenvio/lembrete que falhou ou voltou → taxa de falha de entrega da organização.
     */
    public function deliveryFailed(DeliveryAttempt $attempt): void
    {
        $rule = RiskRule::DeliveryFailureRate;
        $organization = Organization::withTrashed()->find($attempt->organization_id);

        if ($organization === null) {
            return;
        }

        $window = $rule->windowMinutes();
        $minimum = max(1, (int) $rule->config('min_attempts', 20));
        $rate = (float) $rule->config('rate', 0.3);

        $base = DeliveryAttempt::withoutOrganizationScope()
            ->where('organization_id', $organization->getKey())
            ->whereIn('purpose', self::invitationPurposes())
            ->where('created_at', '>=', Carbon::now()->subMinutes($window));

        $total = (clone $base)->whereIn('status', [
            DeliveryStatus::Sent->value,
            DeliveryStatus::Delivered->value,
            DeliveryStatus::Failed->value,
            DeliveryStatus::Bounced->value,
        ])->count();

        $failed = (clone $base)->whereIn('status', [DeliveryStatus::Failed->value, DeliveryStatus::Bounced->value])->count();

        // `$minimum` é no mínimo 1, então passar daqui garante `$total > 0` na divisão.
        if ($total < $minimum || ($failed / $total) < $rate) {
            return;
        }

        RiskSignals::record($rule->value, $organization, [
            'attempts_in_window' => $total,
            'failed_in_window' => $failed,
            'failure_rate' => round($failed / $total, 4),
            'window_minutes' => $window,
            'threshold' => $minimum,
        ]);
    }

    /**
     * Contestação (chargeback) registrada para um pagamento da organização.
     */
    public function chargebackCreated(PaymentChargeback $chargeback): void
    {
        $rule = RiskRule::PaymentChargeback;
        $organization = Organization::withTrashed()->find($chargeback->organization_id);

        if ($organization === null) {
            return;
        }

        $days = max(1, (int) $rule->config('window_days', 90));
        $threshold = max(1, (int) $rule->config('threshold', 1));

        $count = PaymentChargeback::withoutOrganizationScope()
            ->where('organization_id', $organization->getKey())
            ->where('received_at', '>=', Carbon::now()->subDays($days))
            ->count();

        if ($count < $threshold) {
            return;
        }

        RiskSignals::record($rule->value, $organization, [
            'chargebacks_in_window' => $count,
            'window_days' => $days,
            'threshold' => $threshold,
            'payment' => $chargeback->payment->ulid,
        ], null, 'chargeback:'.$chargeback->provider.':'.$chargeback->provider_chargeback_id);
    }

    /**
     * Cadastro concluído → contagem por rede (IP truncado) e por dispositivo declarado. Chaves
     * já chegam como HMAC (o listener nunca põe IP ou identificador bruto na fila).
     */
    public function signup(?int $organizationId, ?string $ipKey, ?string $ipPrefix, ?string $deviceKey): void
    {
        $rule = RiskRule::SerialSignup;
        $window = $rule->windowMinutes();
        $threshold = max(1, (int) $rule->config('threshold', 3));
        $organization = $organizationId !== null ? Organization::withTrashed()->find($organizationId) : null;

        $checks = array_filter([
            'ip' => $ipKey !== null ? [RiskObservations::SIGNUP_IP, $ipKey] : null,
            'device' => $deviceKey !== null ? [RiskObservations::SIGNUP_DEVICE, $deviceKey] : null,
        ]);

        foreach ($checks as $scope => [$kind, $key]) {
            $this->observations->record($kind, $key, $organization?->getKey());

            if ($organization === null) {
                continue;
            }

            $count = $this->observations->count($kind, $key, $window);

            if ($count < $threshold) {
                continue;
            }

            RiskSignals::record($rule->value, $organization, array_filter([
                'scope' => $scope,
                'signups_in_window' => $count,
                'window_minutes' => $window,
                'threshold' => $threshold,
                'ip_prefix' => $scope === 'ip' ? $ipPrefix : null,
            ], fn ($value): bool => $value !== null), null, 'signup-'.$scope.':'.$key);
        }
    }

    private function sendSpike(Organization $organization, ?Envelope $envelope): void
    {
        $rule = RiskRule::NewOrganizationSendSpike;
        $maxAgeDays = max(1, (int) $rule->config('organization_max_age_days', 7));
        $createdAt = $organization->created_at;

        if ($createdAt === null || $createdAt->lt(Carbon::now()->subDays($maxAgeDays))) {
            return;
        }

        $window = $rule->windowMinutes();
        $threshold = max(1, (int) $rule->config('threshold', 30));

        $sent = AuditEvent::withoutOrganizationScope()
            ->where('organization_id', $organization->getKey())
            ->where('event_type', AuditEventType::EnvelopeSent->value)
            ->where('occurred_at', '>=', Carbon::now()->subMinutes($window))
            ->count();

        if ($sent < $threshold) {
            return;
        }

        RiskSignals::record($rule->value, $organization, [
            'sent_in_window' => $sent,
            'organization_age_days' => (int) floor($createdAt->diffInDays(Carbon::now(), true)),
            'window_minutes' => $window,
            'threshold' => $threshold,
        ], $envelope);
    }

    private function externalRecipients(Organization $organization, ?Envelope $envelope): void
    {
        $rule = RiskRule::ExternalRecipientsBurst;
        $window = $rule->windowMinutes();
        $threshold = max(1, (int) $rule->config('threshold', 150));
        $since = Carbon::now()->subMinutes($window);

        $envelopeIds = Envelope::withoutOrganizationScope()
            ->where('organization_id', $organization->getKey())
            ->where('sent_at', '>=', $since)
            ->pluck('id');

        if ($envelopeIds->isEmpty()) {
            return;
        }

        $teamDomains = array_flip($this->teamDomains($organization));
        $external = [];

        Recipient::withoutOrganizationScope()
            ->whereIn('envelope_id', $envelopeIds)
            ->limit(self::RECIPIENT_SCAN_CAP)
            ->pluck('email')
            ->each(function ($email) use (&$external, $teamDomains): void {
                $email = Str::lower(trim((string) $email));
                $domain = Str::after($email, '@');

                if ($email === '' || isset($teamDomains[$domain])) {
                    return;
                }

                // Só a contagem sai daqui: o endereço é usado como chave em memória e descartado.
                $external[hash('sha256', $email)] = true;
            });

        $distinct = count($external);

        if ($distinct < $threshold) {
            return;
        }

        RiskSignals::record($rule->value, $organization, [
            'distinct_external_recipients' => $distinct,
            'envelopes_in_window' => $envelopeIds->count(),
            'window_minutes' => $window,
            'threshold' => $threshold,
        ], $envelope);
    }

    /**
     * Domínios de e-mail dos membros ativos: destinatários nesses domínios são "internos".
     *
     * @return list<string>
     */
    private function teamDomains(Organization $organization): array
    {
        $userIds = Membership::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', MembershipStatus::Active->value)
            ->pluck('user_id');

        return array_values(User::query()
            ->whereIn('id', $userIds)
            ->pluck('email')
            ->map(fn ($email): string => Str::lower(Str::after((string) $email, '@')))
            ->filter()
            ->unique()
            ->all());
    }

    /**
     * @return list<string>
     */
    public static function invitationPurposes(): array
    {
        return [DeliveryPurpose::Invitation->value, DeliveryPurpose::Resend->value, DeliveryPurpose::Reminder->value];
    }
}
