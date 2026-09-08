<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial (isolamento/autorização) — R2: exclusão da conta × último owner
|--------------------------------------------------------------------------
| arquitetura.md §3.1: "Pelo menos um owner por organização (invariante de serviço)".
| MembershipPolicy protege a invariante nas rotas de membros, mas
| Settings\ProfileController::destroy (starter kit) apaga o usuário diretamente:
| memberships caem em cascata e a organização fica sem proprietário. Além disso,
| envelopes.created_by_user_id é FK restrictOnDelete → quem criou envelopes recebe
| erro interno ao excluir a conta.
*/

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\User;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

beforeEach(fn () => $this->withoutVite());

test('[R2] o único owner não consegue excluir a própria conta deixando a organização sem proprietário', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    attachMember($organization, MembershipRole::Admin);

    $response = $this->actingAs($owner)->delete(route('profile.destroy'), ['password' => 'password']);

    expect($response->status())->toBeLessThan(500);

    $hasActiveOwner = Membership::query()
        ->where('organization_id', $organization->id)
        ->where('role', MembershipRole::Owner->value)
        ->where('status', MembershipStatus::Active->value)
        ->exists();

    expect($hasActiveOwner)->toBeTrue()
        ->and(User::query()->whereKey($owner->id)->exists())->toBeTrue();
});

test('[R2] excluir a conta de um usuário que criou envelopes não pode terminar em erro interno', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    attachMember($organization, MembershipRole::Owner); // segundo owner: a invariante não é o problema aqui
    Envelope::factory()->forOrganization($organization, $owner)->create();

    $response = $this->actingAs($owner)->delete(route('profile.destroy'), ['password' => 'password']);

    expect($response->status())->toBeLessThan(500);
});
