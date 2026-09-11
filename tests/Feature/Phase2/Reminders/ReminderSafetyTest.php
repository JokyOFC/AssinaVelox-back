<?php

use App\Enums\RecipientStatus;
use App\Enums\SigningOrder;
use App\Models\Recipient;
use App\Models\RecipientAccessLink;
use App\Services\Envelopes\Reminders\ReminderPlanner;
use App\Services\Envelopes\Reminders\ReminderSender;
use App\Services\Envelopes\Sending\AccessLinks;
use App\Services\Envelopes\Sending\CancelEnvelope;
use App\Services\Envelopes\Sending\ResendInvitations;
use App\Services\Organizations\OrganizationPurge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/ReminderHelpers.php';

/*
| Quem NÃO recebe lembrete (Fase 2 §2.5): estado terminal — inclusive quando o envelope muda
| entre a seleção e o envio —, quem não está na vez, visualizador, quem está com a página
| aberta. E o link: o lembrete sempre emite um novo e revoga o anterior.
*/

beforeEach(function (): void {
    $this->withoutVite();
    $this->provider = fakeEmailProvider();
    remTravel('2026-09-14 13:00:00'); // segunda, 10:00 em São Paulo
});

afterEach(fn () => Carbon::setTestNow());

$maria = ['name' => 'Maria Alves', 'email' => 'maria@exemplo.com'];
$joao = ['name' => 'João Lima', 'email' => 'joao@exemplo.com'];

test('nunca lembra envelope fora de in_progress', function (string $status) {
    $envelope = remSentEnvelope();
    $envelope->forceFill(['status' => $status])->save();

    remTravel('2026-09-17 13:00:00');
    remRunReminders();

    expect(remReminderAttempts())->toBe(0)
        ->and(remProvider()->count())->toBe(1); // só o convite inicial
})->with(['completed', 'refused', 'expired', 'canceled', 'finalizing']);

test('nunca lembra depois do prazo, mesmo antes da varredura de expiração', function () {
    $envelope = remSentEnvelope();
    $envelope->forceFill(['expires_at' => Carbon::parse('2026-09-16 00:00:00')])->save();

    remTravel('2026-09-16 13:00:00');
    remRunReminders();

    expect(remReminderAttempts())->toBe(0);
});

test('se o envelope é cancelado entre a seleção e o envio, o lembrete não sai e o motivo fica na trilha uma vez', function () {
    $envelope = remSentEnvelope();

    remTravel('2026-09-16 13:00:00');
    $due = app(ReminderPlanner::class)->due();
    expect($due)->toHaveCount(1);

    app(CancelEnvelope::class)->handle($envelope->fresh(), 'Desistiu');

    $result = app(ReminderSender::class)->send($due[0]['recipient_id'], $due[0]['sequence']);

    expect($result)->toBe(['outcome' => 'skipped', 'reason' => 'envelope_canceled'])
        ->and(remReminderAttempts())->toBe(0)
        ->and(remAuditCount($envelope, 'reminder.skipped'))->toBe(1);

    // Reprocessar o mesmo job não repete o registro.
    expect(app(ReminderSender::class)->send($due[0]['recipient_id'], $due[0]['sequence'])['outcome'])->toBe('noop')
        ->and(remAuditCount($envelope, 'reminder.skipped'))->toBe(1);
});

test('se o destinatário assina entre a seleção e o envio, o lembrete não sai', function () {
    $envelope = remSentEnvelope();
    $recipient = $envelope->recipients()->firstOrFail();

    remTravel('2026-09-16 13:00:00');
    $due = app(ReminderPlanner::class)->due();

    $recipient->forceFill(['status' => RecipientStatus::Signed, 'signed_at' => Carbon::now()])->save();

    expect(app(ReminderSender::class)->send($due[0]['recipient_id'], $due[0]['sequence']))
        ->toBe(['outcome' => 'skipped', 'reason' => 'recipient_signed'])
        ->and(remReminderAttempts())->toBe(0);
});

test('no sequencial, só quem está na vez é lembrado', function () use ($maria, $joao) {
    $envelope = remSentEnvelope([$maria, $joao], SigningOrder::Sequential);
    $second = $envelope->recipients()->where('email', 'joao@exemplo.com')->firstOrFail();

    // Mesmo que o segundo estivesse marcado como notificado, não é a vez dele.
    $second->forceFill([
        'status' => RecipientStatus::Notified,
        'last_notified_at' => Carbon::parse('2026-09-14 13:00:00'),
    ])->save();

    remTravel('2026-09-16 13:00:00');
    remRunReminders();

    expect(remProvider()->to('maria@exemplo.com'))->toHaveCount(2) // convite + lembrete
        ->and(remProvider()->to('joao@exemplo.com'))->toBe([]);

    expect(app(ReminderSender::class)->send((int) $second->id, 1))
        ->toBe(['outcome' => 'skipped', 'reason' => 'not_their_turn']);
});

test('no paralelo, todos os pendentes são lembrados — e quem assinou não', function () use ($maria, $joao) {
    $envelope = remSentEnvelope([$maria, $joao], SigningOrder::Parallel);

    remTravel('2026-09-16 13:00:00');
    remRunReminders();
    expect(remReminderAttempts())->toBe(2);

    $envelope->recipients()->where('email', 'maria@exemplo.com')->firstOrFail()
        ->forceFill(['status' => RecipientStatus::Signed, 'signed_at' => Carbon::now()])->save();

    remTravel('2026-09-18 13:00:00');
    remRunReminders();

    expect(remReminderAttempts())->toBe(3)
        ->and(remProvider()->to('maria@exemplo.com'))->toHaveCount(2)
        ->and(remProvider()->to('joao@exemplo.com'))->toHaveCount(3);
});

test('visualizador nunca recebe lembrete', function () use ($maria) {
    $envelope = remSentEnvelope([$maria], SigningOrder::Parallel);

    $viewer = Recipient::factory()->forEnvelope($envelope, 0)->create([
        'name' => 'Vera Costa',
        'email' => 'vera@exemplo.com',
        'role' => 'viewer',
        'status' => RecipientStatus::Notified,
        'notification_count' => 1,
        'last_notified_at' => Carbon::parse('2026-09-14 13:00:00'),
    ]);

    remTravel('2026-09-16 13:00:00');
    remRunReminders();

    expect(remReminderAttempts())->toBe(1)
        ->and(remProvider()->to('vera@exemplo.com'))->toBe([]);

    expect(app(ReminderSender::class)->send((int) $viewer->id, 1))
        ->toBe(['outcome' => 'skipped', 'reason' => 'viewer']);
});

test('link revogado: o lembrete emite um link novo, e é ele que funciona', function () {
    $envelope = remSentEnvelope();
    $recipient = $envelope->recipients()->firstOrFail();
    $links = app(AccessLinks::class);

    $old = $links->activeFor($recipient);
    $links->revokeFor($recipient);

    remTravel('2026-09-16 13:00:00');
    remRunReminders();

    $new = $links->activeFor($recipient->fresh());

    expect($new)->not->toBeNull()
        ->and($new->id)->not->toBe($old?->id)
        ->and($new->isUsable())->toBeTrue();

    $token = remTokenFrom(remProvider()->lastTo('maria@exemplo.com'));
    expect($links->resolve((string) $token)?->id)->toBe($new->id);
});

test('link ainda válido: o lembrete o substitui e o anterior deixa de funcionar', function () {
    $envelope = remSentEnvelope();
    $recipient = $envelope->recipients()->firstOrFail();
    $old = app(AccessLinks::class)->activeFor($recipient);

    remTravel('2026-09-16 13:00:00');
    remRunReminders();

    expect($old?->fresh()?->isRevoked())->toBeTrue()
        ->and(RecipientAccessLink::withoutOrganizationScope()
            ->where('recipient_id', $recipient->id)->whereNull('revoked_at')->count())->toBe(1);
});

test('um reenvio manual reinicia a contagem do lembrete', function () {
    $envelope = remSentEnvelope();
    $recipient = $envelope->recipients()->firstOrFail();

    remTravel('2026-09-15 13:00:00');
    app(ResendInvitations::class)->one($envelope->fresh(), $recipient->fresh());

    remTravel('2026-09-16 13:00:00');
    remRunReminders();
    expect(remReminderAttempts())->toBe(0);

    remTravel('2026-09-17 13:00:00');
    remRunReminders();
    expect(remReminderAttempts())->toBe(1);
});

test('não lembra quem está com a página de assinatura aberta', function () {
    $envelope = remSentEnvelope();
    $recipient = $envelope->recipients()->firstOrFail();

    remTravel('2026-09-16 13:00:00');
    RecipientAccessLink::withoutOrganizationScope()
        ->where('recipient_id', $recipient->id)
        ->whereNull('revoked_at')
        ->update(['last_used_at' => Carbon::now()->subMinutes(10)]);

    remRunReminders();
    expect(remReminderAttempts())->toBe(0);

    remTravel('2026-09-16 14:01:00');
    remRunReminders();
    expect(remReminderAttempts())->toBe(1);
});

test('a exclusão da organização leva junto o registro de lembretes', function () {
    remSentEnvelope();

    remTravel('2026-09-16 13:00:00');
    remRunReminders();
    expect(DB::table('envelope_reminders')->count())->toBe(1);

    app(OrganizationPurge::class)->purge(test()->organization);

    expect(DB::table('envelope_reminders')->count())->toBe(0);
});
