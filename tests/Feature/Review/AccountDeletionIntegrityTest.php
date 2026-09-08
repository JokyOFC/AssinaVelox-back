<?php

use App\Enums\MembershipRole;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão de segurança — exclusão da conta do usuário (`profile.destroy`)
|--------------------------------------------------------------------------
| Versão original deste arquivo documentava o BUG (500 por FK e organização órfã) como
| se fosse o comportamento esperado. Isso contradiz os documentos-fonte:
|
| - docs/arquitetura.md §3.1 (memberships): "Pelo menos um `owner` por organização
|   (invariante de serviço)" — a exclusão do único proprietário não pode deixar a
|   organização sem owner.
| - docs/banco-de-dados.md §4.2: `users` → `envelopes.created_by_user_id` = **RESTRICT**,
|   "Autor é parte da evidência" — o vínculo é intencional, então a exclusão precisa ser
|   RECUSADA com mensagem em PT-BR, nunca estourar em erro interno.
| - docs/design/ROUTES_AND_PAGES.md §1.2: a URL canônica do perfil é `/perfil`.
|
| As asserções abaixo foram reescritas para o comportamento exigido pelos documentos
| (o mesmo verificado por AccountDeletionOwnerInvariantTest).
*/

beforeEach(fn () => $this->withoutVite());

test('excluir a conta de quem criou documentos é recusado com mensagem, sem erro interno e sem deslogar', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    attachMember($organization, MembershipRole::Owner); // a invariante de owner não é o bloqueio aqui
    Envelope::factory()->forOrganization($organization, $owner)->create();

    actingAsMember($owner, $organization);

    $this->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrors('account');

    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
    $this->assertAuthenticatedAs($owner->fresh());
});

test('excluir a conta do único proprietário é recusado e a organização mantém proprietário ativo', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    actingAsMember($owner, $organization);

    $this->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrors('account');

    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
    expect(Organization::query()->whereKey($organization->id)->exists())->toBeTrue();
    expect(Membership::query()->where('organization_id', $organization->id)->count())->toBe(1);
});

test('quem não é o último proprietário e não criou documentos consegue excluir a própria conta', function () {
    ['organization' => $organization] = createOrganizationWithOwner();
    $member = attachMember($organization);

    actingAsMember($member, $organization);

    $this->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/');

    expect(User::query()->whereKey($member->id)->exists())->toBeFalse();
    expect(Membership::query()->where('user_id', $member->id)->count())->toBe(0);
    $this->assertGuest();
});
