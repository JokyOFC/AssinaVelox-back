<?php

use App\Enums\MembershipRole;
use App\Models\Membership;
use App\Models\User;
use App\Notifications\Organizations\DailyDigestNotification;
use App\Notifications\Organizations\InvitationAcceptedNotification;
use App\Notifications\Organizations\ProductNewsNotification;
use App\Services\Envelopes\Sending\SendEnvelope;
use App\Services\Organizations\DailyDigest;
use App\Services\Organizations\Invitations;
use App\Services\Organizations\NotificationPreferences;
use App\Support\CurrentOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Sending/Support/SendingHelpers.php';

/*
|--------------------------------------------------------------------------
| Produtores dos eventos da tela de Notificações (ROUTES §2.14)
|--------------------------------------------------------------------------
| Quatro dos sete eventos do catálogo eram interruptores sem nada do outro lado:
| `recipient_signed`, `daily_digest`, `invitation_accepted` e `product_news`. Estes casos
| cobrem os três que não são cobertos pelo fluxo do signatário.
*/

beforeEach(function (): void {
    $this->withoutVite();
});

it('envia o resumo diário a quem manteve a preferência ligada, com os documentos pendentes', function () {
    fakeEmailProvider();
    Notification::fake();

    // Segunda-feira, dia útil.
    $this->travelTo(Carbon::parse('2026-09-14 08:00:00'));

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);

    actingAsMember($owner, $organization);
    app(SendEnvelope::class)->handle($envelope);

    CurrentOrganization::instance()->clear();

    $result = app(DailyDigest::class)->run();

    expect($result['sent'])->toBe(1);

    Notification::assertSentTo($owner, DailyDigestNotification::class, function (DailyDigestNotification $notification): bool {
        return count($notification->items) === 1
            && $notification->items[0]['pending'] >= 1;
    });

    // Idempotente: a segunda execução no mesmo dia não manda de novo.
    expect(app(DailyDigest::class)->run()['sent'])->toBe(0);
});

it('não envia o resumo diário no fim de semana', function () {
    Notification::fake();

    // Sábado.
    $this->travelTo(Carbon::parse('2026-09-12 08:00:00'));

    createOrganizationWithOwner();

    expect(app(DailyDigest::class)->run()['sent'])->toBe(0);

    Notification::assertNothingSent();
});

it('avisa os administradores quando um convite de usuário é aceito', function () {
    Notification::fake();

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $invitations = app(Invitations::class);
    $invitation = $invitations->invite($organization, $owner, 'novo@exemplo.com', MembershipRole::Member);

    $newUser = User::factory()->create(['email' => 'novo@exemplo.com']);

    $invitations->accept($invitation, $newUser);

    Notification::assertSentTo($owner, InvitationAcceptedNotification::class);
    Notification::assertNotSentTo($newUser, InvitationAcceptedNotification::class);
});

it('não avisa quem desligou o evento invitation_accepted', function () {
    Notification::fake();

    ['organization' => $organization, 'owner' => $owner, 'membership' => $membership] = createOrganizationWithOwner();

    app(NotificationPreferences::class)->save($membership, ['invitation_accepted' => []]);

    $invitations = app(Invitations::class);
    $invitation = $invitations->invite($organization, $owner, 'outro@exemplo.com', MembershipRole::Member);

    $invitations->accept($invitation, User::factory()->create(['email' => 'outro@exemplo.com']));

    Notification::assertNotSentTo($owner, InvitationAcceptedNotification::class);
});

it('anuncia novidades do produto apenas por comando e apenas a quem quer', function () {
    Notification::fake();

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $silencioso = attachMember($organization, MembershipRole::Member);

    $membership = Membership::query()
        ->where('organization_id', $organization->getKey())
        ->where('user_id', $silencioso->getKey())
        ->firstOrFail();

    app(NotificationPreferences::class)->save($membership, ['product_news' => []]);

    $this->artisan('notifications:product-news', [
        'title' => 'Novidade',
        'body' => 'Agora o resumo diário existe de verdade.',
    ])->assertExitCode(0);

    Notification::assertSentTo($owner, ProductNewsNotification::class);
    Notification::assertNotSentTo($silencioso, ProductNewsNotification::class);
});
