<?php

/*
| Revisão adversarial da Fase 2, onda A — "acessar como".
|
| 1. Escopo: a sessão de suporte é aberta para UMA organização. Se a membership do alvo
|    nessa organização deixa de estar ativa no meio da sessão, EnsureCurrentOrganization cai
|    no fallback "primeira membership ativa" e o suporte passa a navegar OUTRA organização
|    do alvo, sem motivo registrado nem trilha nela (as visitas continuam indo para a
|    organização original). EnforceImpersonationReadOnly::valid() não confere a membership.
|
| 2. Somente leitura de verdade: o middleware `org` roda ANTES da guarda de impersonation
|    (prioridade de middleware: `org` vem antes de SubstituteBindings) e grava
|    `users.current_organization_id` do ALVO — exatamente o que a guarda diz evitar.
|
| 3. `POST /logout` durante a sessão chama Auth::logout() com o alvo autenticado, que troca
|    o `remember_token` do cliente: todos os "lembrar de mim" dele caem por uma ação do
|    suporte.
*/

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

require_once __DIR__.'/../../Phase2/Org/Support/OrgHelpers.php';

beforeEach(function () {
    $this->withoutVite();
    platformEnableTools(['impersonation']);
});

/**
 * Alvo que participa de duas organizações (X e Y); o suporte abre a sessão em X.
 *
 * @return array{x: Organization, y: Organization, target: User, admin: User}
 */
function reviewImpersonationTwoOrgs(): array
{
    ['organization' => $x] = createOrganizationWithOwner(['name' => 'Cliente X']);
    ['organization' => $y] = createOrganizationWithOwner(['name' => 'Cliente Y']);

    $target = attachMember($x, MembershipRole::Member);
    attachMember($y, MembershipRole::Member, user: $target);

    $admin = User::factory()->platformAdmin()->create(['password' => 'senha-do-admin']);

    return compact('x', 'y', 'target', 'admin');
}

function reviewStartImpersonation(object $test, User $admin, Organization $organization, User $target): void
{
    $test->actingAs($admin)
        ->post(route('admin.organizations.impersonate', $organization->ulid), [
            'user' => $target->id,
            'reason' => 'Chamado #777 — conferir documentos do cliente X',
            'password' => 'senha-do-admin',
        ])
        ->assertRedirect(route('dashboard'));
}

test('a sessão de suporte aberta na organização X nunca mostra outra organização do alvo', function () {
    ['x' => $x, 'y' => $y, 'target' => $target, 'admin' => $admin] = reviewImpersonationTwoOrgs();

    reviewStartImpersonation($this, $admin, $x, $target);

    // O cliente X suspende o usuário enquanto o suporte ainda está na sessão.
    Membership::query()
        ->where('organization_id', $x->id)
        ->where('user_id', $target->id)
        ->update(['status' => MembershipStatus::Suspended->value]);

    $response = $this->get(route('dashboard'));

    $shown = $response->status() === 200
        ? ($response->viewData('page')['props']['organization']['id'] ?? null)
        : null;

    // Hoje: 200 com a organização Y (fallback "primeira membership ativa").
    expect($shown)->not->toBe($y->ulid);
});

test('navegar como o alvo não altera a organização preferida gravada na conta do cliente', function () {
    ['x' => $x, 'y' => $y, 'target' => $target, 'admin' => $admin] = reviewImpersonationTwoOrgs();

    // O cliente trabalha normalmente na organização Y.
    $target->forceFill(['current_organization_id' => $y->id])->save();

    reviewStartImpersonation($this, $admin, $x, $target);

    $this->get(route('dashboard'))->assertOk();

    expect($target->fresh()->current_organization_id)->toBe($y->id);
});

test('logout durante "acessar como" não derruba o "lembrar de mim" do cliente', function () {
    ['x' => $x, 'target' => $target, 'admin' => $admin] = reviewImpersonationTwoOrgs();

    $target->forceFill(['remember_token' => 'token-lembrar-de-mim-do-cliente'])->save();

    reviewStartImpersonation($this, $admin, $x, $target);

    $this->post(route('logout'))->assertRedirect(route('login'));

    expect($target->fresh()->remember_token)->toBe('token-lembrar-de-mim-do-cliente');
});
