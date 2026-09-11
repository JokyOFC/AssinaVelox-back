<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Envelope;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

beforeEach(fn () => $this->withoutVite());

test('props compartilhadas do owner são coerentes com o contrato', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Imobiliária Horizonte', 'legal_name' => 'Horizonte Ltda.']);
    Envelope::factory()->forOrganization($organization, $owner)->inProgress()->count(2)->create();

    actingAsMember($owner, $organization);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.user.id', $owner->id)
        ->where('auth.user.email', $owner->email)
        ->where('auth.user.initials', $owner->initials)
        ->where('auth.user.avatar_url', null)
        ->where('auth.user.two_factor_enabled', false)
        ->where('auth.user.is_platform_admin', false)
        ->where('auth.user.locale', 'pt_BR')
        ->where('auth.user.timezone', 'America/Sao_Paulo')
        ->where('organization.id', $organization->ulid)
        ->where('organization.name', 'Imobiliária Horizonte')
        ->where('organization.legal_name', 'Horizonte Ltda.')
        ->where('organization.initials', 'IH')
        ->where('organization.logo_url', null)
        ->where('organization.role', 'owner')
        ->where('organization.plan.code', 'free')
        ->where('organization.plan.name', 'Grátis')
        ->where('organization.plan.status', 'active')
        ->where('organization.permissions', [
            'manage_members' => true,
            'manage_settings' => true,
            'manage_billing' => true,
            'delete_organization' => true,
            'cancel_any_envelope' => true,
            'view_all_envelopes' => true,
            'manage_folders' => true,
        ])
        ->has('organizations', 1, fn (Assert $org) => $org
            ->where('id', $organization->ulid)
            ->where('is_current', true)
            ->where('role', 'owner')
            ->where('plan_name', 'Grátis')
            ->etc())
        ->where('counts.pending_envelopes', 2)
        ->where('counts.unread_notifications', 0)
        ->where('features', [
            'templates' => false,
            'api_integrations' => false,
            'reminders' => false,
            'sms_whatsapp' => false,
            'branding' => false,
            'multi_document' => false,
            'certificate_login' => false,
            // Fase 2, onda A: chaves novas, todas desligadas por padrão (roadmap §1 T8).
            'participant_roles' => false,
            'custom_roles' => false,
            'tags' => false,
            'reports' => false,
            'audit_log' => false,
            'admin_users' => false,
            'admin_audit' => false,
            'impersonation' => false,
        ])
        ->has('flash')
        ->has('sidebarOpen')
    );
});

test('permissões de admin e member seguem o papel', function () {
    ['organization' => $organization] = createOrganizationWithOwner();
    $admin = attachMember($organization, MembershipRole::Admin);
    $member = attachMember($organization, MembershipRole::Member);

    actingAsMember($admin, $organization);
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('organization.role', 'admin')
        ->where('organization.permissions.manage_members', true)
        ->where('organization.permissions.delete_organization', false));

    actingAsMember($member, $organization);
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('organization.role', 'member')
        ->where('organization.permissions.manage_members', false)
        ->where('organization.permissions.view_all_envelopes', false)
        ->where('organization.permissions.manage_folders', false));
});

test('o switcher lista só memberships ativas e marca a corrente', function () {
    $first = createOrganizationWithOwner(['name' => 'Alpha']);
    $second = createOrganizationWithOwner(['name' => 'Beta']);
    $third = createOrganizationWithOwner(['name' => 'Gama']);
    $user = $first['owner'];
    attachMember($second['organization'], MembershipRole::Member, MembershipStatus::Active, $user);
    attachMember($third['organization'], MembershipRole::Member, MembershipStatus::Suspended, $user);

    actingAsMember($user, $second['organization']);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->has('organizations', 2)
        ->where('organizations.0.name', 'Alpha')
        ->where('organizations.0.is_current', false)
        ->where('organizations.1.name', 'Beta')
        ->where('organizations.1.is_current', true));
});

test('rotas admin não têm organização corrente mas mantêm auth.user', function () {
    ['organization' => $organization] = createOrganizationWithOwner();
    $platformAdmin = User::factory()->platformAdmin()->create();
    attachMember($organization, MembershipRole::Member, MembershipStatus::Active, $platformAdmin);

    // A listagem paginada do painel chama-se `customers` para NÃO sombrear a prop compartilhada
    // `organizations` (lista do switcher) — ver docs/frontend.md e tests/Feature/Smoke.
    $this->actingAs($platformAdmin)->get(route('admin.organizations.index'))->assertInertia(fn (Assert $page) => $page
        ->component('admin/organizations/index')
        ->where('organization', null)
        ->where('auth.user.is_platform_admin', true)
        ->has('customers.data')
        ->has('organizations', 1)
        ->where('counts.pending_envelopes', 0));

    $this->actingAs($platformAdmin)->get(route('admin.billing.index'))->assertInertia(fn (Assert $page) => $page
        ->component('admin/placeholder')
        ->where('organization', null)
        ->has('organizations', 1));
});

test('contagens da sidebar respeitam a visibilidade do member e são cacheadas por usuário', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $member = attachMember($organization, MembershipRole::Member);
    Envelope::factory()->forOrganization($organization, $owner)->inProgress()->count(2)->create();
    Envelope::factory()->forOrganization($organization, $member)->inProgress()->create();

    actingAsMember($member, $organization);
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('counts.pending_envelopes', 1));

    actingAsMember($owner, $organization);
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('counts.pending_envelopes', 3));
});
