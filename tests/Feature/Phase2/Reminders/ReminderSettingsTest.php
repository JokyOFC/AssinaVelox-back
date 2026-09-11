<?php

use App\Models\Envelope;
use App\Services\Envelopes\Reminders\ReminderProps;
use App\Services\Envelopes\Sending\ScheduledSend;
use App\Services\Envelopes\Sending\SendEnvelope;
use App\Support\OrganizationSettings;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/ReminderHelpers.php';

/*
| Onde a cadência é configurada (Fase 2 §2.5): padrão da organização em Configurações ›
| Padrões de assinatura, e por envelope em PUT documentos/{envelope}/lembretes.
*/

beforeEach(function (): void {
    $this->withoutVite();
    $this->provider = fakeEmailProvider();
    remTravel('2026-09-14 13:00:00');
    remRegisterRoutes();
});

afterEach(fn () => Carbon::setTestNow());

/**
 * @return array<string, mixed>
 */
function remSigningPayload(array $reminders = []): array
{
    return [
        'expires_in_days' => 15,
        'signing_order' => 'parallel',
        'initials_on_all_pages' => false,
        'allow_typed_signature' => true,
        'allow_uploaded_signature' => true,
        ...($reminders === [] ? [] : ['reminders' => $reminders]),
    ];
}

$validReminders = [
    'enabled' => true,
    'first_after_days' => 1,
    'interval_days' => 3,
    'max_count' => 4,
    'window_start_hour' => 9,
    'window_end_hour' => 18,
];

test('flag desligada: o controle continua de Fase 2 e o que vier em reminders é ignorado', function () use ($validReminders) {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    actingAsMember($owner, $organization);

    $this->get(route('settings.signing'))->assertInertia(fn (Assert $page) => $page
        ->component('settings/signing')
        ->where('phase2.reminders', false)
        ->where('reminders.enabled', false)
        ->where('reminders.window_start_hour', 8)
        ->where('reminders.window_end_hour', 20));

    $this->patch(route('settings.signing.update'), remSigningPayload($validReminders))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(OrganizationSettings::of($organization->fresh())->get('reminders'))->toBeNull()
        ->and(OrganizationSettings::of($organization->fresh())->defaultExpirationDays())->toBe(15);
});

test('flag ligada: salva a cadência e a janela da organização', function () use ($validReminders) {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    remEnableFeature($organization);
    actingAsMember($owner, $organization);

    $this->get(route('settings.signing'))->assertInertia(fn (Assert $page) => $page
        ->where('phase2.reminders', true)
        ->where('limits.reminders.max_count.max', 10)
        ->where('reminders.timezone', 'America/Sao_Paulo'));

    $this->patch(route('settings.signing.update'), remSigningPayload($validReminders))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(OrganizationSettings::of($organization->fresh())->get('reminders'))->toBe($validReminders);

    // O payload da Fase 1 (sem `reminders`) continua válido e não apaga a cadência.
    $this->patch(route('settings.signing.update'), remSigningPayload())->assertSessionHasNoErrors();
    expect(OrganizationSettings::of($organization->fresh())->get('reminders'))->toBe($validReminders);
});

test('flag ligada: valida os limites da cadência e da janela', function () use ($validReminders) {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    remEnableFeature($organization);
    actingAsMember($owner, $organization);

    $this->patch(route('settings.signing.update'), remSigningPayload([
        ...$validReminders,
        'max_count' => 11,
        'interval_days' => 0,
        'window_start_hour' => 18,
        'window_end_hour' => 9,
    ]))->assertSessionHasErrors([
        'reminders.max_count',
        'reminders.interval_days',
        'reminders.window_end_hour',
    ]);
});

test('o wizard grava a cadência do envelope e ela vale no envio', function () {
    [$envelope, $organization, $owner] = remReadyForSchedule();
    actingAsMember($owner, $organization);

    $this->put(route('envelopes.reminders.update', $envelope), [
        'enabled' => true,
        'first_after_days' => 1,
        'interval_days' => 4,
        'max_count' => 5,
    ])->assertRedirect()->assertSessionHas('success');

    $expected = ['enabled' => true, 'first_after_days' => 1, 'interval_days' => 4, 'max_count' => 5];
    expect($envelope->fresh()->setting('reminders'))->toBe($expected);

    app(SendEnvelope::class)->handle($envelope->fresh());
    expect($envelope->fresh()->setting('reminders'))->toBe($expected);
});

test('o endpoint por envelope valida os limites', function () {
    [$envelope, $organization, $owner] = remReadyForSchedule();
    actingAsMember($owner, $organization);

    $this->put(route('envelopes.reminders.update', $envelope), [
        'enabled' => true,
        'first_after_days' => 31,
        'interval_days' => 0,
        'max_count' => 11,
    ])->assertSessionHasErrors(['first_after_days', 'interval_days', 'max_count']);

    expect($envelope->fresh()->setting('reminders'))->toBeNull();
});

test('um envelope em andamento pode ter os lembretes desligados', function () {
    $envelope = remSentEnvelope();
    actingAsMember(test()->owner, test()->organization);

    $this->put(route('envelopes.reminders.update', $envelope), [
        'enabled' => false,
        'first_after_days' => 2,
        'interval_days' => 2,
        'max_count' => 3,
    ])->assertRedirect()->assertSessionHas('success');

    remTravel('2026-09-17 13:00:00');
    remRunReminders();

    expect(remReminderAttempts())->toBe(0);
});

test('mudar os lembretes de um envelope agendado não cancela o agendamento', function () {
    [$envelope, $organization, $owner] = remReadyForSchedule();
    app(ScheduledSend::class)->schedule($envelope, Carbon::parse('2026-09-15 12:00:00'));

    actingAsMember($owner, $organization);
    $this->put(route('envelopes.reminders.update', $envelope), [
        'enabled' => true,
        'first_after_days' => 3,
        'interval_days' => 3,
        'max_count' => 2,
    ])->assertSessionHas('success');

    remTravel('2026-09-14 13:05:00');
    remRunScheduled();

    expect(ScheduledSend::scheduledAt($envelope->fresh()))->not->toBeNull();
});

test('props do envelope para o front: disponibilidade, cadência, agendamento e contagem por destinatário', function () {
    $envelope = remSentEnvelope();

    remTravel('2026-09-16 13:00:00');
    remRunReminders();

    $props = app(ReminderProps::class)->forEnvelope($envelope->fresh());
    $recipient = $envelope->recipients()->firstOrFail();

    expect($props['available'])->toBeTrue()
        ->and($props['is_default'])->toBeFalse()
        ->and($props['summary'])->toBe('A cada 2 dias · até 3 lembretes')
        ->and($props['scheduled_send'])->toBeNull()
        ->and($props['recipients'][$recipient->ulid]['sent'])->toBe(1)
        ->and($props['recipients'][$recipient->ulid]['last_sent_at'])->toBe('2026-09-16T13:00:00+00:00');

    // Rascunho sem configuração própria: mostra o padrão da organização.
    $draft = Envelope::factory()->forOrganization(test()->organization, test()->owner)->draft()->create();
    $draftProps = app(ReminderProps::class)->forEnvelope($draft);

    expect($draftProps['is_default'])->toBeTrue()
        ->and($draftProps['settings']['enabled'])->toBeTrue();
});
