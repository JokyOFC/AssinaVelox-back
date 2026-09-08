<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Http\Middleware\EnsureCurrentOrganization;
use App\Models\Organization;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

beforeEach(fn () => $this->withoutVite());

test('sem organização na sessão usa a primeira membership ativa e grava na sessão e no usuário', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $owner->forceFill(['current_organization_id' => null])->save();

    $response = $this->actingAs($owner)->get(route('dashboard'));

    $response->assertOk()->assertSessionHas(EnsureCurrentOrganization::SESSION_KEY, $organization->id);
    expect($owner->fresh()->current_organization_id)->toBe($organization->id);
});

test('membership suspensa não é usada como organização corrente', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $user = attachMember($organization, MembershipRole::Member, MembershipStatus::Suspended);

    $this->actingAs($user)
        ->withSession([EnsureCurrentOrganization::SESSION_KEY => $organization->id])
        ->get(route('dashboard'))
        ->assertRedirect(route('organizations.create'));
});

test('o switch troca a organização corrente apenas para membership ativa', function () {
    $first = createOrganizationWithOwner(['name' => 'Primeira']);
    $second = createOrganizationWithOwner(['name' => 'Segunda']);
    $third = createOrganizationWithOwner(['name' => 'Terceira']);
    $user = $first['owner'];

    attachMember($second['organization'], MembershipRole::Member, MembershipStatus::Active, $user);
    attachMember($third['organization'], MembershipRole::Member, MembershipStatus::Suspended, $user);

    actingAsMember($user, $first['organization']);

    $this->post(route('organizations.switch', $second['organization']))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas(EnsureCurrentOrganization::SESSION_KEY, $second['organization']->id);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('organization.name', 'Segunda')
        ->where('organization.role', 'member'));

    $this->post(route('organizations.switch', $third['organization']))->assertForbidden();

    $stranger = createOrganizationWithOwner(['name' => 'Estranha']);
    $this->post(route('organizations.switch', $stranger['organization']))->assertForbidden();
});

test('a página de criar organização é acessível sem organização e o POST cria owner + free', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('organizations.create'))->assertOk();

    $response = $this->actingAs($user)->post(route('organizations.store'), [
        'name' => 'Nova Org',
        'legal_name' => 'Nova Org Ltda.',
        'tax_id' => '12.345.678/0001-95',
    ]);

    $response->assertRedirect(route('dashboard'));

    $organization = Organization::query()->where('name', 'Nova Org')->firstOrFail();
    expect($user->fresh()->roleIn($organization))->toBe(MembershipRole::Owner)
        ->and($organization->currentSubscription()->with('plan')->first()->plan->code)->toBe('free');

    $this->actingAs($user)->post(route('organizations.store'), ['name' => 'X', 'tax_id' => '000'])
        ->assertSessionHasErrors(['name', 'tax_id']);
});

test('org.role bloqueia member em rotas de administração e platform-admin exige a flag', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $admin = attachMember($organization, MembershipRole::Admin);
    $member = attachMember($organization, MembershipRole::Member);

    actingAsMember($member, $organization);
    $this->get(route('members.index'))->assertForbidden();
    $this->get(route('settings.general'))->assertForbidden();
    $this->get(route('billing.index'))->assertForbidden();
    $this->get(route('plans.index'))->assertForbidden();
    $this->get(route('settings.notifications'))->assertOk();
    $this->get(route('admin.organizations.index'))->assertForbidden();

    actingAsMember($admin, $organization);
    $this->get(route('members.index'))->assertOk();
    $this->get(route('settings.general'))->assertOk();
    $this->post(route('members.transfer_ownership', $owner->membershipFor($organization)))->assertForbidden();
    $this->get(route('admin.organizations.index'))->assertForbidden();

    actingAsMember($owner, $organization);
    $this->get(route('members.index'))->assertOk();

    $platformAdmin = User::factory()->platformAdmin()->create();
    $this->actingAs($platformAdmin)->get(route('admin.organizations.index'))->assertOk();
});

test('quando a organização exige 2FA, membros sem TOTP são redirecionados para a segurança da conta', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $organization->forceFill(['settings' => array_merge($organization->settings ?? [], ['require_two_factor' => true])])->save();

    actingAsMember($owner, $organization);
    $this->get(route('dashboard'))->assertRedirect(route('security.edit'));

    $withTotp = attachMember($organization, MembershipRole::Admin, MembershipStatus::Active, User::factory()->withTwoFactor()->create());
    actingAsMember($withTotp, $organization);
    $this->get(route('dashboard'))->assertOk();
});
