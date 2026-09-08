<?php

use App\Models\Envelope;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão de segurança — exclusão da conta do usuário (settings/profile)
|--------------------------------------------------------------------------
| ProfileController@destroy chama Auth::logout() e depois $user->delete() sem
| tratar as FKs (envelopes.created_by_user_id RESTRICT; memberships CASCADE).
*/

test('excluir a conta de quem criou documentos falha com erro 500 depois de já ter deslogado o usuário', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    Envelope::factory()->forOrganization($organization, $owner)->create();

    actingAsMember($owner, $organization);

    $response = $this->delete('/settings/profile', ['password' => 'password']);

    // A FK RESTRICT de envelopes.created_by_user_id estoura a exclusão.
    $response->assertStatus(500);

    // Usuário continua existindo, mas a sessão já foi deslogada antes do erro.
    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
    $this->assertGuest();
});

test('excluir a conta do único proprietário deixa a organização sem nenhum membro (órfã) e ativa', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    actingAsMember($owner, $organization);

    $this->delete('/settings/profile', ['password' => 'password'])->assertRedirect('/');

    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse();
    expect(Organization::query()->whereKey($organization->id)->exists())->toBeTrue();
    expect(Organization::query()->whereKey($organization->id)->first()?->deleted_at)->toBeNull();
    expect(Membership::query()->where('organization_id', $organization->id)->count())->toBe(0);
});
