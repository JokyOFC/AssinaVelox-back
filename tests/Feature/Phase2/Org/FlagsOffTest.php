<?php

use App\Enums\MembershipRole;
use App\Http\Middleware\EnforceImpersonationReadOnly;
use App\Http\Middleware\EnsureAccountNotBlocked;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/OrgHelpers.php';

/*
| Com TODAS as flags desligadas (padrão), as rotas GET novas desta área se comportam como
| os placeholders da Fase 1: 200 para qualquer papel da organização, sem sombrear as props
| compartilhadas; as rotas do painel interno seguem o placeholder. É o mesmo contrato que
| tests/Feature/Smoke/AllGetRoutesTest verifica para todas as rotas.
*/

beforeEach(fn () => $this->withoutVite());

test('GETs novos respondem 200 com o estado Fase 2 para owner, admin e operador', function (MembershipRole $role) {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $user = $role === MembershipRole::Owner ? $owner : attachMember($organization, $role);
    actingAsMember($user, $organization);

    foreach (['settings.tags' => 'settings/tags', 'settings.audit' => 'settings/audit', 'reports.index' => 'reports/index', 'reports.export' => 'reports/index'] as $route => $component) {
        $this->get(route($route))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('enabled', false)
            ->has('organization.permissions')
            ->has('features')
            ->has('auth.user'));
    }
})->with([
    'owner' => MembershipRole::Owner,
    'admin' => MembershipRole::Admin,
    'operador' => MembershipRole::Member,
]);

test('ações novas não fazem nada com as flags desligadas', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    actingAsMember($owner, $organization);

    $this->post(route('tags.store'), ['name' => 'X', 'color' => 'blue'])->assertForbidden();
    $this->post(route('envelopes.tags.apply'), ['tag' => str_repeat('A', 26), 'ids' => [str_repeat('B', 26)]])->assertForbidden();

    $admin = User::factory()->platformAdmin()->create();
    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('admin.users.block', $owner), ['reason' => 'Motivo qualquer'])->assertNotFound();

    expect(DB::table('tags')->count())->toBe(0)
        ->and(DB::table('platform_audit_events')->count())->toBe(0)
        ->and($owner->fresh()->getAttribute('blocked_at'))->toBeNull();
});

test('middlewares novos não alteram requisições comuns nem gravam último acesso sem a flag', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    actingAsMember($owner, $organization);

    $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->missing('impersonation'));

    expect($owner->fresh()->getAttribute('last_seen_at'))->toBeNull()
        ->and(EnsureAccountNotBlocked::class)->not->toBeEmpty()
        ->and(EnforceImpersonationReadOnly::installed())->toBeTrue();
});
