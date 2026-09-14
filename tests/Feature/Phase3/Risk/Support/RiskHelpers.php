<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes do antifraude (P3-RISK, docs/fase-3/antifraude.md)
|--------------------------------------------------------------------------
| Incluído com require_once. Não contém testes. Os eventos são SINTÉTICOS: linhas de
| `audit_events`, `delivery_attempts` e `payment_chargebacks` criadas direto, como o domínio as
| criaria — o antifraude só escuta a criação delas.
*/

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\RiskReview;
use App\Models\RiskSignal;
use App\Models\User;
use App\Services\Risk\RiskSignals;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../Support/OrganizationHelpers.php';

if (! function_exists('riskEnable')) {
    /**
     * Liga a flag e baixa os limiares das regras para caber em testes.
     *
     * @param  array<string, array<string, mixed>>  $rules
     * @param  array<string, mixed>  $risk
     */
    function riskEnable(array $rules = [], array $risk = [], bool $enabled = true): void
    {
        config()->set('assinavelox.features.antifraud', $enabled);

        $defaults = [
            'new_org_send_spike' => ['threshold' => 3],
            'delivery_failure_rate' => ['min_attempts' => 4, 'rate' => 0.5],
            'code_brute_force' => ['per_link' => 3, 'per_ip' => 5],
            'external_recipients_burst' => ['threshold' => 4],
            'payment_chargeback' => ['threshold' => 2],
            'serial_signup' => ['threshold' => 3],
        ];

        foreach (array_replace_recursive($defaults, $rules) as $code => $values) {
            foreach ($values as $key => $value) {
                config()->set("assinavelox.risk.rules.{$code}.{$key}", $value);
            }
        }

        foreach ($risk as $key => $value) {
            config()->set("assinavelox.risk.{$key}", $value);
        }
    }
}

if (! function_exists('riskAuditEvent')) {
    /**
     * @param  array<string, mixed>  $attributes
     */
    function riskAuditEvent(Organization $organization, AuditEventType $type, array $attributes = []): AuditEvent
    {
        return AuditEvent::query()->create(array_merge([
            'organization_id' => $organization->id,
            'actor_type' => ActorType::System,
            'event_type' => $type,
            'correlation_id' => (string) Str::ulid(),
            'occurred_at' => Carbon::now(),
        ], $attributes));
    }
}

if (! function_exists('riskSentEnvelope')) {
    /**
     * Envelope enviado agora com os destinatários informados + o `envelope.sent` da trilha.
     *
     * @param  list<string>  $emails
     */
    function riskSentEnvelope(Organization $organization, User $owner, array $emails = ['maria@cliente.test']): Envelope
    {
        $envelope = Envelope::factory()->forOrganization($organization, $owner)->inProgress()->create(['sent_at' => Carbon::now()]);

        foreach (array_values($emails) as $index => $email) {
            Recipient::factory()->forEnvelope($envelope, $index + 1)->create(['email' => $email]);
        }

        riskAuditEvent($organization, AuditEventType::EnvelopeSent, ['envelope_id' => $envelope->id]);

        return $envelope;
    }
}

if (! function_exists('riskSignalsOf')) {
    /**
     * @return Collection<int, RiskSignal>
     */
    function riskSignalsOf(Organization $organization, ?string $rule = null): Collection
    {
        return RiskSignal::query()
            ->where('organization_id', $organization->id)
            ->when($rule !== null, fn ($query) => $query->where('rule_code', $rule))
            ->orderBy('id')
            ->get();
    }
}

if (! function_exists('riskStatusOf')) {
    function riskStatusOf(Organization $organization): string
    {
        return (string) Organization::withTrashed()->whereKey($organization->id)->value('risk_status');
    }
}

if (! function_exists('riskSetStatus')) {
    /**
     * Força o estado (para testes do bloqueio isolado do motor).
     */
    function riskSetStatus(Organization $organization, string $status): void
    {
        Organization::withTrashed()->whereKey($organization->id)->update(['risk_status' => $status, 'risk_status_changed_at' => Carbon::now()]);
    }
}

if (! function_exists('riskRestrictViaSignals')) {
    /**
     * Pico de envios (40) + rajada de destinatários externos (50) = 90 ≥ 70 → `restricted`
     * pelo motor, com caso aberto.
     */
    function riskRestrictViaSignals(Organization $organization): RiskReview
    {
        RiskSignals::record('new_org_send_spike', $organization, ['sent_in_window' => 40, 'window_minutes' => 1440, 'threshold' => 30]);
        RiskSignals::record('external_recipients_burst', $organization, ['distinct_external_recipients' => 200, 'window_minutes' => 1440, 'threshold' => 150]);

        return RiskReview::query()->where('organization_id', $organization->id)->where('status', 'open')->sole();
    }
}
