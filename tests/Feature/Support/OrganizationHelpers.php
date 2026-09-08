<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes de organização/autorização (B2)
|--------------------------------------------------------------------------
| Incluído com require_once pelos arquivos de teste. Não contém testes.
*/

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Http\Middleware\EnsureCurrentOrganization;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organizations\CreateOrganization;
use Illuminate\Testing\TestResponse;

if (! function_exists('createOrganizationWithOwner')) {
    /**
     * Organization + Membership(owner) + Subscription(free) via o mesmo serviço do cadastro.
     *
     * @return array{organization: Organization, owner: User, membership: Membership}
     */
    function createOrganizationWithOwner(array $attributes = [], ?User $owner = null): array
    {
        $owner ??= User::factory()->create();

        $organization = app(CreateOrganization::class)->handle($owner, [
            'name' => $attributes['name'] ?? fake()->company(),
            'legal_name' => $attributes['legal_name'] ?? null,
            'tax_id' => $attributes['tax_id'] ?? null,
        ]);

        $membership = Membership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $owner->id)
            ->firstOrFail();

        return ['organization' => $organization, 'owner' => $owner->fresh(), 'membership' => $membership];
    }
}

if (! function_exists('attachMember')) {
    function attachMember(Organization $organization, MembershipRole $role = MembershipRole::Member, MembershipStatus $status = MembershipStatus::Active, ?User $user = null): User
    {
        $user ??= User::factory()->create();

        Membership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => $status,
        ]);

        return $user;
    }
}

if (! function_exists('actingAsMember')) {
    /**
     * Autentica o usuário com a organização informada na sessão (como após o switch).
     */
    function actingAsMember(User $user, Organization $organization): void
    {
        test()->actingAs($user)->withSession([EnsureCurrentOrganization::SESSION_KEY => $organization->id]);
    }
}

if (! function_exists('confirmedPasswordSession')) {
    /**
     * Sessão com senha confirmada recentemente (middleware password.confirm).
     *
     * @return array<string, mixed>
     */
    function confirmedPasswordSession(Organization $organization): array
    {
        return [
            EnsureCurrentOrganization::SESSION_KEY => $organization->id,
            'auth.password_confirmed_at' => time(),
        ];
    }
}

if (! function_exists('assertInertiaComponent')) {
    function assertInertiaComponent(TestResponse $response, string $component): void
    {
        $response->assertOk();
        expect($response->viewData('page')['component'] ?? null)->toBe($component);
    }
}
