<?php

use App\Models\User;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Destino do administrador da plataforma sem organização
|--------------------------------------------------------------------------
|
| A conta que só opera o painel interno não participa de organização nenhuma. Antes, o login
| terminava em /dashboard, que exige organização, e o middleware `org` a mandava para "Criar
| nova organização". Agora ela vai ao painel (App\Support\LandingRoute); quem também é membro
| de uma organização continua no dashboard, e o usuário comum sem organização continua indo
| criar a sua.
*/

beforeEach(fn () => $this->withoutVite());

test('o login do administrador da plataforma sem organização termina no painel interno', function () {
    $admin = User::factory()->platformAdmin()->create();

    $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])
        ->assertRedirect(route('admin.organizations.index', absolute: false));

    $this->assertAuthenticatedAs($admin);
});

test('o dashboard redireciona o administrador sem organização ao painel, não a "Criar organização"', function () {
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)->get(route('dashboard'))
        ->assertRedirect(route('admin.organizations.index', absolute: false));

    $this->actingAs($admin)->get(route('envelopes.index'))
        ->assertRedirect(route('admin.organizations.index', absolute: false));
});

test('"Criar organização" continua acessível ao administrador pelo endereço direto', function () {
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)->get(route('organizations.create'))->assertOk();
});

test('o administrador que também é membro de uma organização continua no dashboard', function () {
    ['owner' => $owner] = createOrganizationWithOwner();
    $owner->forceFill(['is_platform_admin' => true])->save();

    $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->actingAs($owner)->get(route('dashboard'))->assertOk();
});

test('o usuário comum sem organização continua indo criar a sua', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->actingAs($user)->get(route('dashboard'))
        ->assertRedirect(route('organizations.create', absolute: false));
});

test('a página que o administrador tentou abrir antes de entrar continua valendo', function () {
    $admin = User::factory()->platformAdmin()->create();

    $this->get(route('admin.billing.index'))->assertRedirect(route('login', absolute: false));

    $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])
        ->assertRedirect(route('admin.billing.index', absolute: false));
});
