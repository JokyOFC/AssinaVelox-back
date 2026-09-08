<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\Plan;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

beforeEach(fn () => $this->withoutVite());

test('a página de usuários traz membros, convites, assentos, papéis e matriz', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    attachMember($organization, MembershipRole::Admin);
    attachMember($organization, MembershipRole::Member, MembershipStatus::Suspended);

    actingAsMember($owner, $organization);

    $this->get(route('members.index'))->assertInertia(fn (Assert $page) => $page
        ->component('members/index')
        ->where('tab', 'members')
        ->where('seats.used', 2)
        ->where('seats.limit', 1)
        ->where('seats.pending_invitations', 0)
        ->where('seats.plan_name', 'Grátis')
        ->has('members', 3)
        ->where('members.0.role', 'owner')
        ->where('members.0.is_me', true)
        ->where('members.0.can.change_role', false)
        ->where('members.0.can.remove', false)
        ->where('members.1.role', 'admin')
        ->where('members.1.can.change_role', true)
        ->where('members.1.can.transfer_ownership', true)
        ->where('members.2.status', 'suspended')
        ->where('members.2.status_label', 'Inativo')
        ->has('invitations', 0)
        ->has('roles', 3)
        ->has('permission_matrix')
        ->has('folders'));
});

test('admin altera função de member e não pode alterar owner', function () {
    ['organization' => $organization, 'owner' => $owner, 'membership' => $ownerMembership] = createOrganizationWithOwner();
    $admin = attachMember($organization, MembershipRole::Admin);
    $member = attachMember($organization, MembershipRole::Member);
    $memberMembership = $member->membershipFor($organization);

    actingAsMember($admin, $organization);

    $this->patch(route('members.update', $memberMembership), ['role' => 'admin'])->assertRedirect();
    expect($memberMembership->fresh()->role)->toBe(MembershipRole::Admin);

    $this->patch(route('members.update', $ownerMembership), ['role' => 'member'])->assertForbidden();
    expect($ownerMembership->fresh()->role)->toBe(MembershipRole::Owner);

    $this->patch(route('members.update', $memberMembership), ['role' => 'owner'])->assertSessionHasErrors('role');
});

test('não é possível remover ou suspender o último owner nem a si mesmo', function () {
    ['organization' => $organization, 'owner' => $owner, 'membership' => $ownerMembership] = createOrganizationWithOwner();
    $admin = attachMember($organization, MembershipRole::Admin);

    actingAsMember($owner, $organization);
    $this->delete(route('members.destroy', $ownerMembership))->assertForbidden();
    $this->patch(route('members.status', $ownerMembership), ['status' => 'suspended'])->assertForbidden();

    actingAsMember($admin, $organization);
    $this->delete(route('members.destroy', $ownerMembership))->assertForbidden();
    expect(Membership::query()->find($ownerMembership->id))->not->toBeNull();
});

test('suspender e reativar um membro, e remover mantém os envelopes criados', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    // Reativar consome assento (SeatUsage): o plano precisa comportar owner + membro.
    Plan::free()->update(['user_quota' => 10]);
    $member = attachMember($organization, MembershipRole::Member);
    $membership = $member->membershipFor($organization);
    $envelope = Envelope::factory()->forOrganization($organization, $member)->create();

    actingAsMember($owner, $organization);

    $this->patch(route('members.status', $membership), ['status' => 'suspended'])->assertRedirect();
    expect($membership->fresh()->status)->toBe(MembershipStatus::Suspended);

    $this->patch(route('members.status', $membership), ['status' => 'active'])->assertRedirect();
    expect($membership->fresh()->status)->toBe(MembershipStatus::Active);

    $this->delete(route('members.destroy', $membership))->assertRedirect();
    expect(Membership::query()->find($membership->id))->toBeNull()
        ->and($envelope->fresh()->created_by_user_id)->toBe($member->id);
});

test('owner transfere a propriedade e passa a admin', function () {
    ['organization' => $organization, 'owner' => $owner, 'membership' => $ownerMembership] = createOrganizationWithOwner();
    $admin = attachMember($organization, MembershipRole::Admin);
    $adminMembership = $admin->membershipFor($organization);

    actingAsMember($owner, $organization);

    $this->post(route('members.transfer_ownership', $adminMembership))->assertRedirect();

    expect($adminMembership->fresh()->role)->toBe(MembershipRole::Owner)
        ->and($ownerMembership->fresh()->role)->toBe(MembershipRole::Admin);

    // O antigo owner (agora admin) não pode mais transferir.
    $this->post(route('members.transfer_ownership', $adminMembership))->assertForbidden();
});

test('a lista pode ser filtrada por busca, função e status', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $admin = attachMember($organization, MembershipRole::Admin, MembershipStatus::Active, User::factory()->create(['name' => 'Rafael Duarte']));
    attachMember($organization, MembershipRole::Member, MembershipStatus::Suspended);

    actingAsMember($owner, $organization);

    $this->get(route('members.index', ['q' => 'Rafael']))->assertInertia(fn (Assert $page) => $page
        ->has('members', 1)
        ->where('members.0.user.name', 'Rafael Duarte')
        ->where('filters.q', 'Rafael'));

    $this->get(route('members.index', ['status' => 'suspended']))->assertInertia(fn (Assert $page) => $page->has('members', 1));
    $this->get(route('members.index', ['role' => 'admin']))->assertInertia(fn (Assert $page) => $page->has('members', 1));
});
