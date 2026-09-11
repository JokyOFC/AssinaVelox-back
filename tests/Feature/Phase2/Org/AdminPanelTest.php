<?php

use App\Enums\MembershipRole;
use App\Models\PlatformAuditEvent;
use App\Models\User;
use App\Services\AdminLog\PlatformAction;
use App\Services\AdminLog\PlatformTrail;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/OrgHelpers.php';

beforeEach(fn () => $this->withoutVite());

test('com as flags desligadas admin.users e admin.audit continuam o placeholder da Fase 1', function () {
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)->get(route('admin.users.index'))->assertInertia(fn (Assert $page) => $page
        ->component('admin/placeholder')
        ->where('feature', 'admin_users'));

    $this->actingAs($admin)->get(route('admin.audit.index'))->assertInertia(fn (Assert $page) => $page
        ->component('admin/placeholder')
        ->where('feature', 'admin_audit'));
});

test('admin.users e admin.audit só para platform admin', function () {
    platformEnableTools();
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    actingAsMember($owner, $organization);

    $this->get(route('admin.users.index'))->assertForbidden();
    $this->get(route('admin.audit.index'))->assertForbidden();
    $this->post(route('admin.users.block', $owner), ['reason' => 'tentativa'])->assertForbidden();

    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)->get(route('admin.users.index'))->assertInertia(fn (Assert $page) => $page
        ->component('admin/users/index')
        ->has('users.data', 2));

    $this->actingAs($admin)->get(route('admin.audit.index'))->assertInertia(fn (Assert $page) => $page
        ->component('admin/audit/index')
        ->has('events.data', 0));
});

test('busca de usuários traz organizações, 2FA e status', function () {
    platformEnableTools();
    ['organization' => $organization] = createOrganizationWithOwner(['name' => 'Horizonte']);
    $maria = attachMember($organization, MembershipRole::Admin, user: User::factory()->withTwoFactor()->create(['name' => 'Maria Busca', 'email' => 'maria@busca.test']));
    User::factory()->create(['name' => 'Outro Nome']);
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)->get(route('admin.users.index', ['q' => 'busca']))->assertInertia(fn (Assert $page) => $page
        ->has('users.data', 1)
        ->where('users.data.0.id', (string) $maria->id)
        ->where('users.data.0.two_factor_enabled', true)
        ->where('users.data.0.organizations.0.name', 'Horizonte')
        ->where('users.data.0.organizations.0.role_label', 'Administrador')
        ->where('users.data.0.blocked', false)
        ->where('users.data.0.can.block', true));
});

test('bloqueio exige motivo e senha confirmada, derruba a conta e fica na trilha', function () {
    platformEnableTools();
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $admin = User::factory()->platformAdmin()->create();

    // Sem senha confirmada: o middleware desvia para a confirmação.
    $this->actingAs($admin)->post(route('admin.users.block', $owner), ['reason' => 'Uso indevido'])
        ->assertRedirect();
    expect($owner->fresh()->getAttribute('blocked_at'))->toBeNull();

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('admin.users.block', $owner), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('admin.users.block', $owner), ['reason' => 'Uso indevido reportado'])
        ->assertSessionHas('success');

    $blocked = $owner->fresh();
    expect($blocked->getAttribute('blocked_at'))->not->toBeNull()
        ->and($blocked->getAttribute('blocked_reason'))->toBe('Uso indevido reportado')
        ->and((int) $blocked->getAttribute('blocked_by_user_id'))->toBe($admin->id);

    $event = PlatformAuditEvent::query()->firstOrFail();
    expect($event->action)->toBe(PlatformAction::UserBlocked)
        ->and($event->actor_user_id)->toBe($admin->id)
        ->and($event->target_id)->toBe($owner->id)
        ->and($event->payload['reason'])->toBe('Uso indevido reportado');

    // A conta bloqueada é deslogada na próxima requisição.
    actingAsMember($blocked, $organization);
    $this->get(route('dashboard'))->assertRedirect(route('login'))->assertSessionHasErrors('email');
    expect(Auth::check())->toBeFalse();

    // E não consegue entrar de novo.
    $this->post(route('login.store'), ['email' => $blocked->email, 'password' => 'password'])
        ->assertRedirect(route('login'));
    expect(Auth::check())->toBeFalse();

    // Desbloqueio também vai para a trilha.
    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('admin.users.unblock', $owner))
        ->assertSessionHas('success');
    expect($owner->fresh()->getAttribute('blocked_at'))->toBeNull()
        ->and(PlatformAuditEvent::query()->count())->toBe(2);

    $this->actingAs($admin)->get(route('admin.audit.index', ['action' => 'user.blocked']))->assertInertia(fn (Assert $page) => $page
        ->has('events.data', 1)
        ->where('events.data.0.label', 'Conta bloqueada')
        ->where('events.data.0.details.1', ['label' => 'Motivo', 'value' => 'Uso indevido reportado']));
});

test('não bloqueia a si mesmo nem outro platform admin', function () {
    platformEnableTools();
    $admin = User::factory()->platformAdmin()->create();
    $other = User::factory()->platformAdmin()->create();

    foreach ([$admin, $other] as $target) {
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.users.block', $target), ['reason' => 'Motivo qualquer'])
            ->assertSessionHasErrors('reason');
    }

    expect(PlatformAuditEvent::query()->count())->toBe(0);
});

test('platform_audit_events é append-only', function () {
    $admin = User::factory()->platformAdmin()->create();
    $this->actingAs($admin);

    $event = PlatformTrail::record(PlatformAction::UserUnblocked, $admin);

    expect(fn () => $event->update(['payload' => ['x' => 1]]))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);
});
