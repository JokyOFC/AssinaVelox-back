<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial (isolamento/autorização) — R5: cadastro com token de convite alheio
|--------------------------------------------------------------------------
| CreateNewUser aceita `invitation={token}` sem comparar o e-mail do cadastro com o do
| convite e grava users.current_organization_id = organização do convite. O usuário fica
| sem organização própria e apontando para uma organização da qual não participa.
| O acesso continua bloqueado (o middleware `org` só considera memberships), mas o dado
| cruzado não deveria existir.
*/

use App\Http\Middleware\EnsureCurrentOrganization;
use App\Models\Membership;
use App\Models\MembershipInvitation;
use App\Models\User;
use App\Services\Organizations\Invitations;
use Laravel\Fortify\Features;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
    $this->withoutVite();
});

test('[R5] cadastro com token de convite emitido para OUTRO e-mail não vincula o usuário à organização do convite', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $token = Invitations::generateToken();

    MembershipInvitation::factory()->create([
        'organization_id' => $organization->id,
        'email' => 'alvo@exemplo.com.br',
        'token_digest' => Invitations::digest($token),
        'invited_by_user_id' => $owner->id,
    ]);

    $this->post(route('register.store'), [
        'name' => 'Intruso Curioso',
        'email' => 'intruso@exemplo.com.br',
        'organization_name' => '',
        'organization_tax_id' => '',
        'password' => 'Senha@Forte123',
        'password_confirmation' => 'Senha@Forte123',
        'terms' => true,
        'invitation' => $token,
    ]);

    $intruder = User::query()->where('email', 'intruso@exemplo.com.br')->firstOrFail();

    // Sem membership e sem acesso — isto já vale hoje.
    expect(Membership::query()->where('user_id', $intruder->id)->count())->toBe(0);

    // ROUTES_AND_PAGES §0.2: o grupo `auth` inclui `verified`, então o recém-cadastrado
    // primeiro esbarra na verificação de e-mail (ver EmailVerificationEnforcementTest).
    $this->actingAs($intruder)
        ->withSession([EnsureCurrentOrganization::SESSION_KEY => $organization->id])
        ->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));

    // E, mesmo com o e-mail verificado, não há acesso à organização do convite:
    // o usuário é levado a criar a própria organização.
    $intruder->forceFill(['email_verified_at' => now()])->save();

    $this->actingAs($intruder->fresh())
        ->withSession([EnsureCurrentOrganization::SESSION_KEY => $organization->id])
        ->get(route('dashboard'))
        ->assertRedirect(route('organizations.create'));

    // Dado cruzado: o usuário não deveria "apontar" para uma organização alheia.
    expect($intruder->current_organization_id)->toBeNull();
});
