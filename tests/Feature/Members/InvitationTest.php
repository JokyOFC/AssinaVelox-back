<?php

use App\Enums\MembershipRole;
use App\Http\Middleware\EnsureCurrentOrganization;
use App\Models\Membership;
use App\Models\MembershipInvitation;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\MembershipInvitationNotification;
use App\Services\Organizations\Invitations;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

beforeEach(function () {
    $this->withoutVite();
    Notification::fake();
});

function invitationWithToken(array $attributes = []): array
{
    $token = Invitations::generateToken();
    $invitation = MembershipInvitation::factory()->create(array_merge(['token_digest' => Invitations::digest($token)], $attributes));

    return [$invitation, $token];
}

test('owner convida por e-mail: grava digest, expiração e envia a notificação com o link', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    Plan::free()->update(['user_quota' => 10]);

    actingAsMember($owner, $organization);

    $response = $this->post(route('invitations.store'), [
        'emails' => 'novo@exemplo.com.br, outro@exemplo.com.br',
        'role' => 'member',
    ]);

    $response->assertRedirect()->assertSessionHas('success');

    $invitations = MembershipInvitation::query()->where('organization_id', $organization->id)->get();
    expect($invitations)->toHaveCount(2)
        ->and($invitations->first()->token_digest)->toHaveLength(64)
        ->and($invitations->first()->role)->toBe(MembershipRole::Member)
        ->and((int) now()->diffInDays($invitations->first()->expires_at, false))->toBeGreaterThanOrEqual(6);

    Notification::assertSentOnDemand(MembershipInvitationNotification::class, function ($notification, $channels, $notifiable) use ($invitations) {
        $url = $notification->acceptUrl();

        return in_array('mail', $channels, true)
            && str_contains($url, '/convites/')
            && MembershipInvitation::query()->where('token_digest', Invitations::digest($notification->token))->exists()
            && in_array($notifiable->routes['mail'], $invitations->pluck('email')->all(), true);
    });
});

test('convite rejeita e-mail já membro, convite pendente duplicado e falta de assentos', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $member = attachMember($organization, MembershipRole::Member);
    MembershipInvitation::factory()->create(['organization_id' => $organization->id, 'email' => 'pendente@exemplo.com.br', 'invited_by_user_id' => $owner->id]);

    actingAsMember($owner, $organization);

    $this->post(route('invitations.store'), ['emails' => [$member->email], 'role' => 'member'])
        ->assertSessionHasErrors('emails.0');

    $this->post(route('invitations.store'), ['emails' => ['pendente@exemplo.com.br'], 'role' => 'member'])
        ->assertSessionHasErrors('emails.0');

    // Plano free: 1 assento, já usado pelo owner (+ member) → sem assentos.
    $this->post(route('invitations.store'), ['emails' => ['livre@exemplo.com.br'], 'role' => 'member'])
        ->assertSessionHasErrors('seats');

    $this->post(route('invitations.store'), ['emails' => ['livre@exemplo.com.br'], 'role' => 'owner'])
        ->assertSessionHasErrors('role');
});

test('reenviar gera novo token e revogar invalida o convite', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    [$invitation, $token] = invitationWithToken(['organization_id' => $organization->id, 'invited_by_user_id' => $owner->id]);

    actingAsMember($owner, $organization);

    $this->post(route('invitations.resend', $invitation))->assertRedirect()->assertSessionHas('success');
    expect($invitation->fresh()->token_digest)->not->toBe(Invitations::digest($token));
    Notification::assertSentOnDemandTimes(MembershipInvitationNotification::class, 1);

    $this->delete(route('invitations.destroy', $invitation))->assertRedirect();
    expect($invitation->fresh()->revoked_at)->not->toBeNull();

    $this->get(route('invitations.accept', ['token' => $token]))
        ->assertInertia(fn (Assert $page) => $page->where('state', 'revoked')->where('auth_state', 'other_user'));
});

test('a página de aceite mostra os estados inválido, expirado, revogado e aceito', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $this->get(route('invitations.accept', ['token' => 'token-inexistente-com-tamanho-ok']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('invitations/accept')->where('state', 'revoked')->where('invitation', null)->where('auth_state', 'guest'));

    [$expired, $expiredToken] = invitationWithToken(['organization_id' => $organization->id, 'invited_by_user_id' => $owner->id, 'expires_at' => now()->subDay()]);
    $this->get(route('invitations.accept', ['token' => $expiredToken]))->assertInertia(fn (Assert $page) => $page->where('state', 'expired'));

    [$revoked, $revokedToken] = invitationWithToken(['organization_id' => $organization->id, 'invited_by_user_id' => $owner->id, 'revoked_at' => now()]);
    $this->get(route('invitations.accept', ['token' => $revokedToken]))->assertInertia(fn (Assert $page) => $page->where('state', 'revoked'));

    [$accepted, $acceptedToken] = invitationWithToken(['organization_id' => $organization->id, 'invited_by_user_id' => $owner->id, 'accepted_at' => now()]);
    $this->get(route('invitations.accept', ['token' => $acceptedToken]))->assertInertia(fn (Assert $page) => $page->where('state', 'accepted'));

    [$valid, $validToken] = invitationWithToken(['organization_id' => $organization->id, 'invited_by_user_id' => $owner->id, 'email' => 'nova@exemplo.com.br']);
    $this->get(route('invitations.accept', ['token' => $validToken]))->assertInertia(fn (Assert $page) => $page
        ->where('state', 'valid')
        ->where('auth_state', 'guest')
        ->where('invitation.email', 'nova@exemplo.com.br')
        ->where('invitation.organization_name', $organization->name)
        ->where('invitation.role_label', 'Operador'));

    $other = User::factory()->create();
    $this->actingAs($other)->get(route('invitations.accept', ['token' => $validToken]))
        ->assertInertia(fn (Assert $page) => $page->where('auth_state', 'other_user'));
});

test('usuário autenticado com o mesmo e-mail aceita o convite e passa a ter membership ativa na organização', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $invitee = User::factory()->create(['email' => 'convidado@exemplo.com.br']);
    [$invitation, $token] = invitationWithToken(['organization_id' => $organization->id, 'invited_by_user_id' => $owner->id, 'email' => 'Convidado@exemplo.com.br', 'role' => MembershipRole::Admin]);

    $this->actingAs($invitee)->post(route('invitations.accept.store', ['token' => $token]))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas(EnsureCurrentOrganization::SESSION_KEY, $organization->id);

    $membership = Membership::query()->where('organization_id', $organization->id)->where('user_id', $invitee->id)->firstOrFail();
    expect($membership->role)->toBe(MembershipRole::Admin)
        ->and($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and($invitee->fresh()->current_organization_id)->toBe($organization->id);

    // Aceitar de novo é idempotente (já aceito → apenas redireciona para a página do convite).
    $this->actingAs($invitee)->post(route('invitations.accept.store', ['token' => $token]))
        ->assertRedirect(route('invitations.accept', ['token' => $token]));
    expect(Membership::query()->where('organization_id', $organization->id)->where('user_id', $invitee->id)->count())->toBe(1);
});

test('usuário autenticado com outro e-mail não consegue aceitar; visitante é enviado ao cadastro', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    [$invitation, $token] = invitationWithToken(['organization_id' => $organization->id, 'invited_by_user_id' => $owner->id, 'email' => 'alvo@exemplo.com.br']);

    // Visitante: enviado ao cadastro com o convite pré-preenchido.
    $this->post(route('invitations.accept.store', ['token' => $token]))
        ->assertRedirect(route('register', ['invitation' => $token]));

    $this->get(route('register', ['invitation' => $token]))
        ->assertInertia(fn (Assert $page) => $page->where('invitation.email', 'alvo@exemplo.com.br')->where('invitation.organization_name', $organization->name));

    // Autenticado com outro e-mail: erro e nenhuma membership criada.
    $other = User::factory()->create();
    $this->actingAs($other)->from(route('invitations.accept', ['token' => $token]))
        ->post(route('invitations.accept.store', ['token' => $token]))
        ->assertRedirect(route('invitations.accept', ['token' => $token]))
        ->assertSessionHas('error');
    expect(Membership::query()->where('user_id', $other->id)->count())->toBe(0);
});

test('convite expirado ou revogado não pode ser aceito', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $invitee = User::factory()->create(['email' => 'x@exemplo.com.br']);
    [$expired, $expiredToken] = invitationWithToken(['organization_id' => $organization->id, 'invited_by_user_id' => $owner->id, 'email' => 'x@exemplo.com.br', 'expires_at' => now()->subMinute()]);

    $this->actingAs($invitee)->post(route('invitations.accept.store', ['token' => $expiredToken]))
        ->assertRedirect(route('invitations.accept', ['token' => $expiredToken]));

    expect(Membership::query()->where('user_id', $invitee->id)->count())->toBe(0);
});
