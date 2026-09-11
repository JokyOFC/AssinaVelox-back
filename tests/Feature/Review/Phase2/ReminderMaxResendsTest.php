<?php

use App\Services\Envelopes\Sending\ResendInvitations;
use App\Services\Envelopes\Sending\SendEnvelope;
use App\Support\OrganizationSettings;
use Illuminate\Support\Carbon;

require_once __DIR__.'/../../Phase2/Reminders/Support/ReminderHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da Fase 2, onda A — lembretes além do máximo de reenvios (Q11)
|--------------------------------------------------------------------------
| docs/roadmap.md §2.5 ("Riscos e decisões"): o lembrete "respeita `settings.max_resends`
| (Q11) e o throttle de 10 min". A implementação (docs/fase-2/lembretes-e-agendamento.md
| §4.5) desacoplou os dois contadores: o lembrete tem só o próprio `max_count` (até 10) e não
| lê `max_resends`. Com a organização limitando a UM reenvio por destinatário, a mesma pessoa
| recebe tantos lembretes quanto `max_count` — e o remetente ainda pode reenviar à mão.
*/

beforeEach(function (): void {
    $this->withoutVite();
    $this->provider = fakeEmailProvider();
    remTravel('2026-09-14 13:00:00'); // segunda, 10:00 em São Paulo
});

afterEach(fn () => Carbon::setTestNow());

test('lembretes automáticos respeitam organizations.settings.max_resends', function () {
    ['organization' => $organization, 'owner' => $owner] = remOrganization([
        'first_after_days' => 1,
        'interval_days' => 1,
        'max_count' => 5,
    ]);

    OrganizationSettings::of($organization)->put(['max_resends' => 1]);
    $organization = $organization->fresh();

    expect($organization->setting('max_resends'))->toBe(1)
        ->and($organization->setting('reminders')['enabled'] ?? null)->toBeTrue();

    $envelope = readyEnvelope($organization, $owner, [['name' => 'Maria Alves', 'email' => 'maria@exemplo.com']]);
    app(SendEnvelope::class)->handle($envelope);
    test()->organization = $organization;

    foreach (['2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18', '2026-09-19'] as $day) {
        remTravel($day.' 13:00:00');
        remRunReminders();
    }

    $recipient = $envelope->fresh()->recipients()->firstOrFail();

    // Q11: no máximo `max_resends` mensagens além do convite, somando reenvio e lembrete.
    expect(remReminderAttempts($recipient))->toBeLessThanOrEqual(1);

    // E, esgotado o limite pelos lembretes, o reenvio manual continua liberado (contadores separados).
    remTravel('2026-09-19 15:00:00');
    expect(app(ResendInvitations::class)->canResend($envelope->fresh(), $recipient->fresh()))->toBeFalse();
});
