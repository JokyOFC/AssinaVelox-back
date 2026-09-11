<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes de lembretes e envio agendado (B-REM, Fase 2 §2.5)
|--------------------------------------------------------------------------
| Incluído com require_once pelos arquivos de teste. Não contém testes. Prefixo `rem` para
| não colidir com helpers globais de outras pastas (o Pest compartilha o escopo de funções).
*/

use App\Enums\DeliveryPurpose;
use App\Enums\SigningOrder;
use App\Http\Controllers\Envelopes\EnvelopeSendController;
use App\Models\AuditEvent;
use App\Models\DeliveryAttempt;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\User;
use App\Services\Envelopes\Sending\SendEnvelope;
use App\Support\OrganizationSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Sending\Support\FakeEmailProvider;

require_once __DIR__.'/../../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../../Sending/Support/SendingHelpers.php';

if (! function_exists('remEnableFeature')) {
    /**
     * Liga `features.reminders`: interruptor global E plano da organização.
     */
    function remEnableFeature(Organization $organization): void
    {
        config(['assinavelox.features.reminders' => true]);

        $plan = subscriptionFor($organization)->plan;
        $plan->forceFill(['features' => array_merge($plan->features ?? [], ['reminders' => true])])->save();
    }
}

if (! function_exists('remOrganization')) {
    /**
     * Organização com cota ilimitada e padrão de lembretes ligado (a cada 2 dias, até 3,
     * das 8h às 20h no fuso da organização).
     *
     * @param  array<string, mixed>  $cadence
     * @return array{organization: Organization, owner: User}
     */
    function remOrganization(array $cadence = [], bool $enable = true): array
    {
        ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
        setPlanQuota($organization, null);

        if ($enable) {
            remEnableFeature($organization);
        }

        OrganizationSettings::of($organization)->put(['reminders' => array_merge([
            'enabled' => true,
            'first_after_days' => 2,
            'interval_days' => 2,
            'max_count' => 3,
            'window_start_hour' => 8,
            'window_end_hour' => 20,
        ], $cadence)]);

        return ['organization' => $organization->fresh(), 'owner' => $owner];
    }
}

if (! function_exists('remSentEnvelope')) {
    /**
     * Envelope enviado com lembretes (a cadência do padrão da organização é copiada no envio).
     *
     * @param  list<array{name: string, email: string}>  $recipients
     * @param  array<string, mixed>  $cadence
     */
    function remSentEnvelope(
        array $recipients = [['name' => 'Maria Alves', 'email' => 'maria@exemplo.com']],
        SigningOrder $order = SigningOrder::Sequential,
        array $cadence = [],
    ): Envelope {
        ['organization' => $organization, 'owner' => $owner] = remOrganization($cadence);

        $envelope = readyEnvelope($organization, $owner, $recipients, $order);

        app(SendEnvelope::class)->handle($envelope);

        test()->organization = $organization;
        test()->owner = $owner;

        return $envelope->fresh();
    }
}

if (! function_exists('remReadyForSchedule')) {
    /**
     * @return array{0: Envelope, 1: Organization, 2: User}
     */
    function remReadyForSchedule(bool $enable = true): array
    {
        ['organization' => $organization, 'owner' => $owner] = remOrganization(enable: $enable);

        return [readyEnvelope($organization, $owner), $organization, $owner];
    }
}

if (! function_exists('remTravel')) {
    function remTravel(string|Carbon $moment): void
    {
        Carbon::setTestNow($moment instanceof Carbon ? $moment : Carbon::parse($moment));
    }
}

if (! function_exists('remRunReminders')) {
    function remRunReminders(): void
    {
        test()->artisan('envelopes:send-reminders')->assertExitCode(0);
    }
}

if (! function_exists('remRunScheduled')) {
    function remRunScheduled(): void
    {
        test()->artisan('envelopes:dispatch-scheduled')->assertExitCode(0);
    }
}

if (! function_exists('remReminderAttempts')) {
    /**
     * Lembretes automáticos que chegaram ao provedor (`delivery_attempts.purpose = reminder`).
     */
    function remReminderAttempts(?Recipient $recipient = null): int
    {
        return DeliveryAttempt::withoutOrganizationScope()
            ->where('purpose', DeliveryPurpose::Reminder->value)
            ->when($recipient !== null, fn ($query) => $query->where('recipient_id', $recipient->getKey()))
            ->count();
    }
}

if (! function_exists('remAuditCount')) {
    function remAuditCount(Envelope $envelope, string $type): int
    {
        return AuditEvent::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('event_type', $type)
            ->count();
    }
}

if (! function_exists('remLastAudit')) {
    function remLastAudit(Envelope $envelope, string $type): ?AuditEvent
    {
        /** @var AuditEvent|null */
        return AuditEvent::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('event_type', $type)
            ->latest('id')
            ->first();
    }
}

if (! function_exists('remTokenFrom')) {
    /**
     * Token bruto do link de assinatura contido no e-mail.
     */
    function remTokenFrom(?object $email): ?string
    {
        if ($email === null) {
            return null;
        }

        return preg_match('#assinar/([A-Za-z0-9_-]{43})#', $email->htmlBody.$email->textBody, $matches) === 1
            ? $matches[1]
            : null;
    }
}

if (! function_exists('remRegisterRoutes')) {
    /**
     * Rotas da Fase 2 §2.5 — ESPELHO do que deve entrar em routes/web.php, no grupo
     * `documentos` (ver docs/fase-2/lembretes-e-agendamento.md §7). routes/web.php é de
     * outra área; enquanto as linhas não chegam lá, os testes registram as mesmas rotas,
     * com os mesmos middlewares. Quando existirem, esta função não faz nada.
     */
    function remRegisterRoutes(): void
    {
        if (Route::has('envelopes.schedule')) {
            return;
        }

        Route::middleware(['web', 'auth', 'verified', 'org', 'org.2fa'])
            ->prefix('documentos')
            ->name('envelopes.')
            ->scopeBindings()
            ->group(function (): void {
                Route::post('{envelope}/agendamento', [EnvelopeSendController::class, 'schedule'])->name('schedule');
                Route::delete('{envelope}/agendamento', [EnvelopeSendController::class, 'cancelSchedule'])->name('schedule.cancel');
                Route::put('{envelope}/lembretes', [EnvelopeSendController::class, 'reminders'])->name('reminders.update');
            });

        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();
    }
}

if (! function_exists('remProvider')) {
    function remProvider(): FakeEmailProvider
    {
        /** @var FakeEmailProvider */
        return test()->provider;
    }
}
