<?php

use App\Enums\PlanBillingPeriod;
use App\Models\Plan;
use App\Models\PlatformAuditEvent;
use App\Models\User;
use App\Services\AdminLog\PlatformAction;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Billing/Support/BillingHelpers.php';

/*
|--------------------------------------------------------------------------
| Painel interno › Planos (docs/cobranca.md §18)
|--------------------------------------------------------------------------
| O catálogo de planos é editado pela equipe da plataforma, com senha confirmada e trilha.
*/

beforeEach(fn () => $this->withoutVite());

/**
 * @return array<string, mixed>
 */
function planPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Profissional',
        'description' => 'Para profissionais e pequenas equipes',
        'price_cents' => 7_990,
        'billing_period' => 'monthly',
        'envelope_quota' => 500,
        'user_quota' => 10,
        'storage_gb' => 5,
        'features' => ['templates' => true, 'company_signature' => true, 'email_otp' => true],
        'is_active' => true,
        'is_public' => true,
        'is_sandbox' => false,
        'sort_order' => 2,
    ], $overrides);
}

function adminWithConfirmedPassword(): User
{
    $admin = User::factory()->platformAdmin()->create();
    test()->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()]);

    return $admin;
}

test('a tela lista todos os planos, inclusive inativos, com assinaturas, recursos e o catálogo', function () {
    ['organization' => $organization] = createOrganizationWithOwner();
    // createOrganizationWithOwner já cria o plano Grátis (CreateOrganization).
    $free = Plan::free() ?? Plan::factory()->free()->create();
    $paid = Plan::factory()->professional()->create(['features' => ['templates' => true, 'bulk_generation_limits' => ['max_rows' => 100]]]);
    Plan::factory()->inactive()->create(['code' => 'legado', 'name' => 'Legado', 'sort_order' => 9]);
    subscribeOrganization($organization, $paid);

    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)->get(route('admin.plans.index'))->assertInertia(fn (Assert $page) => $page
        ->component('admin/plans/index')
        ->has('plans', 3)
        ->where('plans.0.code', $free->code)
        ->where('plans.0.is_free_plan', true)
        ->where('plans.1.code', 'professional')
        ->where('plans.1.subscriptions.active', 1)
        ->where('plans.1.features.templates', true)
        ->where('plans.1.features.api_integrations', false)
        ->where('plans.1.extra_feature_keys', ['bulk_generation_limits'])
        ->where('plans.2.code', 'legado')
        ->where('plans.2.is_active', false)
        ->has('catalog.groups')
        ->has('catalog.entries')
        ->has('billing_periods', 2)
        ->where('company_signature_offered', false));
});

test('só a equipe da plataforma vê o catálogo', function () {
    ['owner' => $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get(route('admin.plans.index'))->assertForbidden();
});

test('criar um plano grava colunas, recursos, armazenamento e a trilha', function () {
    $admin = adminWithConfirmedPassword();

    $this->from(route('admin.plans.index'))
        ->post(route('admin.plans.store'), planPayload(['code' => 'profissional_anual', 'billing_period' => 'yearly']))
        ->assertRedirect(route('admin.plans.index'))
        ->assertSessionHas('success');

    $plan = Plan::query()->where('code', 'profissional_anual')->firstOrFail();

    expect($plan->price_cents)->toBe(7_990)
        ->and($plan->billing_period)->toBe(PlanBillingPeriod::Yearly)
        ->and($plan->envelope_quota)->toBe(500)
        ->and($plan->user_quota)->toBe(10)
        ->and($plan->is_public)->toBeTrue()
        ->and($plan->is_sandbox)->toBeFalse()
        ->and($plan->features['templates'])->toBeTrue()
        ->and($plan->features['api_integrations'])->toBeFalse()
        ->and($plan->features['storage_bytes'])->toBe(5 * 1024 * 1024 * 1024);

    $event = PlatformAuditEvent::query()->where('action', PlatformAction::PlanCreated->value)->firstOrFail();

    expect($event->actor_user_id)->toBe($admin->getKey())
        ->and($event->payload['plan'])->toBe('profissional_anual')
        ->and($event->payload['changes'])->toContain('Preço: R$ 79,90')
        ->and($event->payload['changes'])->toContain('Período: yearly')
        ->and($event->payload['changes'])->toContain('Modelos: sim');
});

test('criar e editar exigem senha confirmada', function () {
    $admin = User::factory()->platformAdmin()->create();
    Plan::factory()->professional()->create();

    $this->actingAs($admin)->post(route('admin.plans.store'), planPayload(['code' => 'novo']))
        ->assertRedirect(route('password.confirm'));

    $this->actingAs($admin)->patch(route('admin.plans.update', 'professional'), planPayload())
        ->assertRedirect(route('password.confirm'));

    expect(Plan::query()->where('code', 'novo')->exists())->toBeFalse();
});

test('editar altera preço, cotas e recursos, preserva chaves fora do catálogo e registra o que mudou', function () {
    $admin = adminWithConfirmedPassword();
    $plan = Plan::factory()->professional()->create([
        'features' => ['templates' => false, 'company_signature' => true, 'bulk_generation_limits' => ['max_rows' => 100]],
    ]);

    $this->patch(route('admin.plans.update', $plan), planPayload([
        'code' => 'tentativa_de_troca',
        'price_cents' => 9_900,
        'envelope_quota' => null,
        'storage_gb' => null,
        'features' => ['templates' => true, 'company_signature' => false],
        'is_public' => true,
        'is_sandbox' => false,
    ]))->assertRedirect()->assertSessionHas('success');

    $plan->refresh();

    expect($plan->code)->toBe('professional')
        ->and($plan->price_cents)->toBe(9_900)
        ->and($plan->envelope_quota)->toBeNull()
        ->and($plan->is_public)->toBeTrue()
        ->and($plan->is_sandbox)->toBeFalse()
        ->and($plan->features['templates'])->toBeTrue()
        ->and($plan->features['company_signature'])->toBeFalse()
        ->and($plan->features['bulk_generation_limits'])->toBe(['max_rows' => 100])
        ->and(array_key_exists('storage_bytes', $plan->features))->toBeFalse();

    $event = PlatformAuditEvent::query()->where('action', PlatformAction::PlanUpdated->value)->firstOrFail();

    expect($event->actor_user_id)->toBe($admin->getKey())
        ->and($event->payload['plan'])->toBe('professional')
        ->and($event->payload['changes'])->toContain('Preço: R$ 49,00 → R$ 99,00')
        ->and($event->payload['changes'])->toContain('Documentos/mês: 500 → ilimitado')
        ->and($event->payload['changes'])->toContain('Modelos: não → sim')
        ->and($event->payload['changes'])->toContain('Sandbox: sim → não');
});

test('salvar sem mudar nada não gera evento de trilha', function () {
    adminWithConfirmedPassword();
    $plan = Plan::factory()->professional()->create(['features' => ['templates' => true]]);

    $this->patch(route('admin.plans.update', $plan), planPayload([
        'name' => $plan->name,
        'description' => $plan->description,
        'price_cents' => 4_900,
        'storage_gb' => null,
        'features' => ['templates' => true],
        'is_public' => false,
        'is_sandbox' => true,
    ]))->assertRedirect();

    expect(PlatformAuditEvent::query()->where('action', PlatformAction::PlanUpdated->value)->exists())->toBeFalse();
});

test('o plano Grátis não pode ser desativado nem deixar de ser gratuito', function () {
    adminWithConfirmedPassword();
    $free = Plan::factory()->free()->create();

    $this->patch(route('admin.plans.update', $free), planPayload(['name' => 'Grátis', 'price_cents' => 0, 'is_active' => false]))
        ->assertSessionHasErrors('is_active');

    $this->patch(route('admin.plans.update', $free), planPayload(['name' => 'Grátis', 'price_cents' => 1_000]))
        ->assertSessionHasErrors('price_cents');

    expect($free->refresh()->is_active)->toBeTrue()
        ->and($free->price_cents)->toBe(0);
});

test('o código é validado na criação e recursos desconhecidos são recusados', function () {
    adminWithConfirmedPassword();
    Plan::factory()->free()->create();

    $this->post(route('admin.plans.store'), planPayload(['code' => 'Inválido!']))
        ->assertSessionHasErrors('code');

    $this->post(route('admin.plans.store'), planPayload(['code' => 'free']))
        ->assertSessionHasErrors('code');

    $this->post(route('admin.plans.store'), planPayload(['code' => 'novo', 'features' => ['invento' => true]]))
        ->assertSessionHasErrors('features');
});
