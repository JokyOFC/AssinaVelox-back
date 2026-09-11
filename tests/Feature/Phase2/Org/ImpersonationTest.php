<?php

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\MembershipRole;
use App\Http\Middleware\EnforceImpersonationReadOnly;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Impersonation;
use App\Models\Organization;
use App\Models\PlatformAuditEvent;
use App\Models\User;
use App\Services\AdminLog\PlatformAction;
use App\Services\Impersonation\ImpersonationManager;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/OrgHelpers.php';

beforeEach(function () {
    $this->withoutVite();
    platformEnableTools(['impersonation']);
});

/**
 * @return array{organization: Organization, owner: User, admin: User, member: User, envelope: Envelope}
 */
function impersonationScenario(): array
{
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Cliente Suporte']);
    $member = attachMember($organization, MembershipRole::Member);
    $envelope = orgEnvelope($organization, $owner, EnvelopeStatus::Draft);
    $admin = User::factory()->platformAdmin()->create(['password' => 'senha-do-admin']);

    return compact('organization', 'owner', 'admin', 'member', 'envelope');
}

function startImpersonation(object $test, array $s, ?User $target = null): void
{
    $test->actingAs($s['admin'])
        ->post(route('admin.organizations.impersonate', $s['organization']->ulid), [
            'user' => ($target ?? $s['owner'])->id,
            'reason' => 'Chamado #123 — cliente não encontra documento',
            'password' => 'senha-do-admin',
        ])
        ->assertRedirect(route('dashboard'));
}

test('com a flag desligada "acessar como" não existe', function () {
    platformEnableTools(['impersonation'], false);
    $s = impersonationScenario();

    $this->actingAs($s['admin'])->post(route('admin.organizations.impersonate', $s['organization']->ulid), [
        'user' => $s['owner']->id, 'reason' => 'Motivo suficiente aqui', 'password' => 'senha-do-admin',
    ])->assertNotFound();

    expect(Impersonation::query()->count())->toBe(0);
});

test('exige platform admin, senha correta e motivo', function () {
    $s = impersonationScenario();

    // Usuário comum (mesmo owner) não chega nem ao controller.
    $this->actingAs($s['owner'])->post(route('admin.organizations.impersonate', $s['organization']->ulid), [
        'user' => $s['member']->id, 'reason' => 'Motivo suficiente aqui', 'password' => 'password',
    ])->assertForbidden();

    $this->actingAs($s['admin'])->post(route('admin.organizations.impersonate', $s['organization']->ulid), [
        'user' => $s['owner']->id, 'reason' => 'Motivo suficiente aqui', 'password' => 'errada',
    ])->assertSessionHasErrors('password');

    $this->actingAs($s['admin'])->post(route('admin.organizations.impersonate', $s['organization']->ulid), [
        'user' => $s['owner']->id, 'reason' => '', 'password' => 'senha-do-admin',
    ])->assertSessionHasErrors('reason');

    $this->actingAs($s['admin'])->post(route('admin.organizations.impersonate', $s['organization']->ulid), [
        'user' => $s['owner']->id, 'reason' => 'curto', 'password' => 'senha-do-admin',
    ])->assertSessionHasErrors('reason');

    expect(Impersonation::query()->count())->toBe(0)
        ->and(Auth::user()->is($s['admin']))->toBeTrue();
});

test('nunca permite impersonar outro platform admin, alguém de fora da organização ou conta bloqueada', function () {
    $s = impersonationScenario();
    $otherAdmin = User::factory()->platformAdmin()->create();
    attachMember($s['organization'], MembershipRole::Admin, user: $otherAdmin);
    $outsider = User::factory()->create();
    $blocked = attachMember($s['organization'], MembershipRole::Member);
    $blocked->forceFill(['blocked_at' => now(), 'blocked_reason' => 'fraude'])->save();

    foreach ([$otherAdmin, $outsider, $blocked, $s['admin']] as $target) {
        $this->actingAs($s['admin'])->post(route('admin.organizations.impersonate', $s['organization']->ulid), [
            'user' => $target->id, 'reason' => 'Motivo suficiente aqui', 'password' => 'senha-do-admin',
        ])->assertSessionHasErrors('user');
    }

    expect(Impersonation::query()->count())->toBe(0);
});

test('a sessão é somente leitura: POST, PATCH, DELETE, download de PDF e cobrança são negados', function () {
    $s = impersonationScenario();
    startImpersonation($this, $s);

    expect(Auth::user()->is($s['owner']))->toBeTrue();

    // Leitura permitida, com banner.
    $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('impersonation.target_name', $s['owner']->name)
        ->where('impersonation.organization_name', 'Cliente Suporte')
        ->has('impersonation.expires_at'));
    $this->get(route('envelopes.index'))->assertOk();
    $this->get(route('envelopes.show', $s['envelope']->ulid))->assertOk();

    $title = $s['envelope']->title;

    // Escrita e ações.
    $this->patch(route('envelopes.update', $s['envelope']->ulid), ['title' => 'Alterado pelo suporte'])->assertForbidden();
    $this->delete(route('envelopes.destroy', $s['envelope']->ulid))->assertForbidden();
    $this->post(route('envelopes.duplicate', $s['envelope']->ulid))->assertForbidden();
    $this->post(route('envelopes.bulk', 'cancel'), ['ids' => [$s['envelope']->ulid]])->assertForbidden();
    $this->post(route('folders.store'), ['name' => 'Pasta do suporte'])->assertForbidden();
    $this->patch(route('settings.organization.update'), ['name' => 'Hack'])->assertForbidden();
    $this->post(route('invitations.store'), ['email' => 'x@y.com', 'role' => 'admin'])->assertForbidden();
    $this->put(route('user-password.update'), ['current_password' => 'x', 'password' => 'y', 'password_confirmation' => 'y'])->assertForbidden();
    // GET que cria rascunho também é bloqueado.
    $this->get(route('envelopes.create'))->assertForbidden();

    // Conteúdo de documento, exportações, cobrança, credenciais e 2FA.
    $this->get(route('envelopes.download', [$s['envelope']->ulid, 'original']))->assertForbidden();
    $this->get(route('envelopes.document.preview', $s['envelope']->ulid))->assertForbidden();
    $this->get(route('envelopes.evidence', $s['envelope']->ulid))->assertForbidden();
    $this->get(route('dashboard.export'))->assertForbidden();
    $this->get(route('billing.index'))->assertForbidden();
    $this->get(route('plans.index'))->assertForbidden();
    $this->post(route('billing.checkout'), ['plan' => 'professional'])->assertForbidden();
    $this->get(route('integrations.keys'))->assertForbidden();
    $this->get(route('profile.edit'))->assertForbidden();
    $this->get('/user/two-factor-recovery-codes')->assertForbidden();

    expect(Envelope::withoutOrganizationScope()->where('title', $title)->count())->toBe(1)
        ->and(Envelope::withoutOrganizationScope()->count())->toBe(1);
});

test('registra início, visitas e fim na trilha da organização alvo e na trilha da plataforma', function () {
    $s = impersonationScenario();
    startImpersonation($this, $s);

    $this->get(route('dashboard'))->assertOk();
    $this->get(route('envelopes.show', $s['envelope']->ulid))->assertOk();
    $this->get(route('billing.index'))->assertForbidden(); // negado: não conta como visita

    $this->post(route('admin.impersonation.stop'))
        ->assertRedirect(route('admin.organizations.show', $s['organization']->ulid));

    expect(Auth::user()->is($s['admin']))->toBeTrue();

    $impersonation = Impersonation::query()->firstOrFail();
    expect($impersonation->ended_at)->not->toBeNull()
        ->and($impersonation->end_reason)->toBe(Impersonation::END_STOPPED)
        ->and($impersonation->pages_viewed)->toBe(2)
        ->and($impersonation->reason)->toBe('Chamado #123 — cliente não encontra documento');

    $trail = AuditEvent::forOrganization($s['organization'])
        ->where('event_type', 'like', 'impersonation.%')
        ->orderBy('id')
        ->get();

    expect($trail->pluck('event_type')->map->value->all())->toBe([
        'impersonation.started',
        'impersonation.page_viewed',
        'impersonation.page_viewed',
        'impersonation.ended',
    ]);

    // O ator é o platform admin, nunca o usuário acessado; tudo com o ULID da sessão.
    foreach ($trail as $event) {
        expect($event->actor_id)->toBe($s['admin']->id)
            ->and($event->envelope_id)->toBeNull()
            ->and($event->payload['impersonation'])->toBe($impersonation->ulid);
    }

    expect($trail[2]->payload['path'])->toBe('/documentos/'.$s['envelope']->ulid);

    expect(PlatformAuditEvent::query()->orderBy('id')->pluck('action')->map->value->all())
        ->toBe([PlatformAction::ImpersonationStarted->value, PlatformAction::ImpersonationEnded->value]);

    // A organização vê o acesso no registro de atividades.
    orgEnableTools($s['organization'], ['audit_log']);
    actingAsMember($s['owner'], $s['organization']);
    $this->get(route('settings.audit', ['category' => 'support']))->assertInertia(fn (Assert $page) => $page
        ->where('events.data.0.type', 'impersonation.ended')
        ->where('events.data.0.actor.kind', 'support'));
});

test('expira em 30 minutos e devolve o login ao admin', function () {
    $s = impersonationScenario();
    startImpersonation($this, $s);

    $this->travel(ImpersonationManager::TTL_MINUTES + 1)->minutes();

    $this->get(route('envelopes.index'))
        ->assertRedirect(route('admin.organizations.show', $s['organization']->ulid));

    expect(Auth::user()->is($s['admin']))->toBeTrue()
        ->and(Impersonation::query()->firstOrFail()->end_reason)->toBe(Impersonation::END_EXPIRED)
        ->and(AuditEvent::forOrganization($s['organization'])->where('event_type', AuditEventType::ImpersonationEnded->value)->count())->toBe(1);

    // Depois de expirar, a sessão não volta a valer.
    $this->get(route('admin.organizations.index'))->assertOk();
});

test('logout durante a sessão encerra tudo; admin rebaixado derruba a sessão', function () {
    $s = impersonationScenario();
    startImpersonation($this, $s);

    $this->post(route('logout'))->assertRedirect(route('login'));
    expect(Auth::check())->toBeFalse()
        ->and(Impersonation::query()->firstOrFail()->end_reason)->toBe(Impersonation::END_LOGOUT);

    // Nova sessão, e o admin perde o perfil no meio dela.
    startImpersonation($this, $s, $s['member']);
    $s['admin']->forceFill(['is_platform_admin' => false])->save();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    expect(Auth::check())->toBeFalse();
});

test('a guarda de somente leitura está instalada no grupo web', function () {
    expect(EnforceImpersonationReadOnly::installed())->toBeTrue();
});
