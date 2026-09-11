<?php

use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\DeliveryAttempt;
use App\Services\Envelopes\Reminders\ReminderLog;
use App\Services\Envelopes\Reminders\ReminderSender;
use App\Services\Envelopes\Sending\SendEnvelope;
use App\Support\OrganizationSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/ReminderHelpers.php';

/*
| Cadência dos lembretes automáticos (Fase 2 §2.5): intervalo, janela de horário, máximo,
| idempotência e flag. Relógio congelado: o envio acontece numa segunda-feira às 10:00 em
| São Paulo (13:00 UTC), dentro da janela padrão 8h–20h.
*/

beforeEach(function (): void {
    $this->withoutVite();
    $this->provider = fakeEmailProvider();
    remTravel('2026-09-14 13:00:00');
});

afterEach(fn () => Carbon::setTestNow());

test('o primeiro lembrete sai ao vencer first_after_days e o seguinte conta do último contato', function () {
    $envelope = remSentEnvelope();
    $recipient = $envelope->recipients()->firstOrFail();

    remTravel('2026-09-15 13:00:00');
    remRunReminders();
    expect(remReminderAttempts())->toBe(0);

    remTravel('2026-09-16 12:59:00');
    remRunReminders();
    expect(remReminderAttempts())->toBe(0);

    remTravel('2026-09-16 13:00:00');
    remRunReminders();
    expect(remReminderAttempts($recipient))->toBe(1);

    $email = remProvider()->lastTo('maria@exemplo.com');
    expect($email?->subject)->toStartWith('Lembrete:')
        ->and($email?->textBody)->toContain('lembrete automático');

    $event = remLastAudit($envelope, AuditEventType::ReminderSent->value);
    expect($event)->not->toBeNull()
        ->and((int) $event->recipient_id)->toBe((int) $recipient->id)
        ->and($event->payload['sequence'])->toBe(1)
        ->and($event->payload['to'])->not->toContain('maria@');

    $attempt = DeliveryAttempt::withoutOrganizationScope()->where('purpose', 'reminder')->firstOrFail();
    expect($attempt->to_address)->toBe('maria@exemplo.com')
        ->and($attempt->correlation_id)->toBe($event->correlation_id);

    // Segundo lembrete: dois dias depois do PRIMEIRO lembrete, não do envio.
    remTravel('2026-09-18 12:59:00');
    remRunReminders();
    expect(remReminderAttempts())->toBe(1);

    remTravel('2026-09-18 13:00:00');
    remRunReminders();
    expect(remReminderAttempts())->toBe(2);
});

test('só sai dentro da janela de horário, no fuso da organização', function () {
    remSentEnvelope();

    // Venceu na quarta às 10:00 local, mas estas execuções estão fora das 8h–20h.
    remTravel('2026-09-16 23:30:00'); // 20:30 em São Paulo
    remRunReminders();
    expect(remReminderAttempts())->toBe(0);

    remTravel('2026-09-17 10:59:00'); // 07:59
    remRunReminders();
    expect(remReminderAttempts())->toBe(0);

    remTravel('2026-09-17 11:00:00'); // 08:00
    remRunReminders();
    expect(remReminderAttempts())->toBe(1);
});

test('a janela é configurável por organização', function () {
    remSentEnvelope(cadence: ['window_start_hour' => 14, 'window_end_hour' => 16]);

    remTravel('2026-09-16 13:00:00'); // 10:00 local — venceu, mas antes das 14h
    remRunReminders();
    expect(remReminderAttempts())->toBe(0);

    remTravel('2026-09-16 17:00:00'); // 14:00 local
    remRunReminders();
    expect(remReminderAttempts())->toBe(1);
});

test('para no máximo configurado', function () {
    remSentEnvelope(cadence: ['first_after_days' => 1, 'interval_days' => 1, 'max_count' => 2]);

    foreach (range(1, 6) as $day) {
        remTravel(Carbon::parse('2026-09-14 13:00:00')->addDays($day));
        remRunReminders();
    }

    expect(remReminderAttempts())->toBe(2)
        ->and(DB::table(ReminderLog::TABLE)->where('status', ReminderLog::SENT)->count())->toBe(2);
});

test('rodar o comando de novo, ou repetir o job, não duplica o lembrete', function () {
    $envelope = remSentEnvelope();
    $recipient = $envelope->recipients()->firstOrFail();

    remTravel('2026-09-16 13:00:00');
    remRunReminders();
    remRunReminders();

    expect(remReminderAttempts())->toBe(1)
        ->and(DB::table(ReminderLog::TABLE)->count())->toBe(1)
        ->and(remAuditCount($envelope, 'reminder.sent'))->toBe(1);

    // O mesmo (destinatário, número) de novo: nada acontece.
    expect(app(ReminderSender::class)->send((int) $recipient->id, 1))
        ->toBe(['outcome' => 'noop', 'reason' => 'already_processed']);

    // O número seguinte, antes da hora: também nada.
    expect(app(ReminderSender::class)->send((int) $recipient->id, 2))
        ->toBe(['outcome' => 'noop', 'reason' => 'not_due']);

    expect(remReminderAttempts())->toBe(1);
});

test('lembrete não altera o limite de reenvios manuais (notification_count)', function () {
    $envelope = remSentEnvelope();
    $recipient = $envelope->recipients()->firstOrFail();
    $before = (int) $recipient->notification_count;

    remTravel('2026-09-16 13:00:00');
    remRunReminders();

    $recipient->refresh();
    expect((int) $recipient->notification_count)->toBe($before)
        ->and($recipient->last_notified_at?->toDateTimeString())->toBe('2026-09-16 13:00:00');
});

test('com o interruptor global desligado nada é enviado', function () {
    remSentEnvelope();

    config(['assinavelox.features.reminders' => false]);

    remTravel('2026-09-17 13:00:00');
    remRunReminders();

    expect(remReminderAttempts())->toBe(0)
        ->and(DB::table(ReminderLog::TABLE)->count())->toBe(0);
});

test('com o plano sem o recurso nada é enviado', function () {
    remSentEnvelope();

    $plan = subscriptionFor(test()->organization)->plan;
    $plan->forceFill(['features' => array_merge($plan->features ?? [], ['reminders' => false])])->save();

    remTravel('2026-09-17 13:00:00');
    remRunReminders();

    expect(remReminderAttempts())->toBe(0);
});

test('com a flag desligada o envio grava o envelope como na Fase 1 (sem settings.reminders)', function () {
    ['organization' => $organization, 'owner' => $owner] = remOrganization(enable: false);
    $envelope = readyEnvelope($organization, $owner);

    app(SendEnvelope::class)->handle($envelope);

    expect($envelope->fresh()->settings ?? [])->not->toHaveKey('reminders');

    remTravel('2026-09-17 13:00:00');
    remRunReminders();
    expect(remReminderAttempts())->toBe(0);
});

test('o envio congela o padrão da organização; mudar o padrão depois não afeta o envelope', function () {
    $envelope = remSentEnvelope(cadence: ['interval_days' => 3]);

    expect($envelope->setting('reminders'))->toBe([
        'enabled' => true,
        'first_after_days' => 2,
        'interval_days' => 3,
        'max_count' => 3,
    ]);

    OrganizationSettings::of(test()->organization)->put(['reminders' => ['enabled' => false]]);

    remTravel('2026-09-16 13:00:00');
    remRunReminders();

    expect(remReminderAttempts())->toBe(1);
});

test('a cadência gravada no envelope (wizard) prevalece sobre o padrão', function () {
    ['organization' => $organization, 'owner' => $owner] = remOrganization();
    $envelope = readyEnvelope($organization, $owner);

    $envelope->forceFill(['settings' => array_merge($envelope->settings ?? [], [
        'reminders' => ['enabled' => false, 'first_after_days' => 2, 'interval_days' => 2, 'max_count' => 3],
    ])])->save();

    app(SendEnvelope::class)->handle($envelope->fresh());

    expect($envelope->fresh()->setting('reminders')['enabled'])->toBeFalse();

    remTravel('2026-09-17 13:00:00');
    remRunReminders();
    expect(remReminderAttempts())->toBe(0);
});

test('a trilha e as tentativas de entrega nunca guardam o token do link', function () {
    remSentEnvelope();

    remTravel('2026-09-16 13:00:00');
    remRunReminders();

    $token = remTokenFrom(remProvider()->lastTo('maria@exemplo.com'));
    expect($token)->not->toBeNull();

    $payloads = json_encode(AuditEvent::withoutOrganizationScope()->pluck('payload')->all());
    $meta = json_encode(DeliveryAttempt::withoutOrganizationScope()->pluck('meta')->all());

    expect($payloads)->not->toContain($token)
        ->and($meta)->not->toContain($token);
});
