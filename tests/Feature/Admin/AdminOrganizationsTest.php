<?php

use App\Enums\MembershipRole;
use App\Models\Envelope;
use App\Models\User;
use App\Support\CurrentOrganization;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

beforeEach(fn () => $this->withoutVite());

test('a lista de clientes cruza organizações sem organização corrente e traz KPIs, abas e paginação', function () {
    $a = createOrganizationWithOwner(['name' => 'Alpha Imóveis']);
    $b = createOrganizationWithOwner(['name' => 'Beta Consultoria']);
    Envelope::factory()->forOrganization($a['organization'], $a['owner'])->completed()->count(2)->create();
    Envelope::factory()->forOrganization($b['organization'], $b['owner'])->inProgress()->create();

    $admin = User::factory()->platformAdmin()->create();

    $response = $this->actingAs($admin)->get(route('admin.organizations.index'));

    expect(CurrentOrganization::instance()->has())->toBeFalse();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('admin/organizations/index')
        ->where('filters.status', 'all')
        ->where('tabs.all', 2)
        ->where('tabs.active', 2)
        ->where('tabs.canceled', 0)
        ->where('kpis.active_accounts.value', 2)
        ->has('kpis.mrr_cents.value')
        ->has('kpis.envelopes_today.value')
        ->has('kpis.trials_expiring_7d.value')
        ->has('kpis.past_due.value')
        ->has('customers.data', 2)
        ->has('customers.meta.total')
        ->has('customers.links')
        ->where('customers.data.0.plan.key', 'free')
        ->where('customers.data.0.subscription_status', 'active')
        ->has('customers.data.0.owner.email')
        ->has('customers.data.0.public_id'));

    $this->actingAs($admin)->get(route('admin.organizations.index', ['q' => 'Beta']))
        ->assertInertia(fn (Assert $page) => $page->has('customers.data', 1)->where('customers.data.0.name', 'Beta Consultoria'));

    $this->actingAs($admin)->get(route('admin.organizations.index', ['plan' => 'free', 'status' => 'past_due']))
        ->assertInertia(fn (Assert $page) => $page->has('customers.data', 0));
});

test('o detalhe do cliente é somente leitura e usa consultas fora do escopo', function () {
    $a = createOrganizationWithOwner(['name' => 'Alpha Imóveis']);
    attachMember($a['organization'], MembershipRole::Member);
    Envelope::factory()->forOrganization($a['organization'], $a['owner'])->completed()->create();
    Envelope::factory()->forOrganization($a['organization'], $a['owner'])->inProgress()->create();

    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)->get(route('admin.organizations.show', $a['organization']))->assertInertia(fn (Assert $page) => $page
        ->component('admin/organizations/show')
        ->where('customer.id', $a['organization']->ulid)
        ->where('customer.name', 'Alpha Imóveis')
        ->where('kpis.envelopes_total', 2)
        ->where('kpis.envelopes_completed', 1)
        ->where('kpis.envelopes_in_progress', 1)
        ->where('kpis.members_active', 2)
        ->where('subscription.plan.key', 'free')
        ->where('subscription.status', 'active')
        ->where('usage.members.used', 2)
        ->has('members', 2)
        ->has('payments'));
});

test('a exportação de clientes devolve CSV', function () {
    createOrganizationWithOwner(['name' => 'Alpha Imóveis']);
    $admin = User::factory()->platformAdmin()->create();

    $response = $this->actingAs($admin)->get(route('admin.organizations.export'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and($response->streamedContent())->toContain('Alpha Imóveis');
});

test('usuário comum e visitante não acessam o painel interno', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $this->get(route('admin.organizations.index'))->assertRedirect(route('login'));

    actingAsMember($owner, $organization);
    $this->get(route('admin.organizations.index'))->assertForbidden();
    $this->get(route('admin.organizations.show', $organization))->assertForbidden();
    $this->get(route('admin.organizations.export'))->assertForbidden();
});
