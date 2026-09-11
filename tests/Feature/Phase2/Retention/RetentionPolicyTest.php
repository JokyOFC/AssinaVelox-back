<?php

use App\Enums\MembershipRole;
use App\Models\RetentionEvent;
use App\Models\RetentionPolicy;
use App\Services\Retention\RetentionCategory;
use App\Services\Retention\RetentionPolicies;

require_once __DIR__.'/Support/RetentionHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 2 §2.19 (K-RET) — Configurações › Retenção: mínimos legais, confirmação forte, isolamento
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    config()->set('inertia.ssr.enabled', false);
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    retentionEnable($this->organization);
});

function retentionPayload(array $periods, bool $active = true, ?string $confirmation = null): array
{
    return array_filter([
        'is_active' => $active,
        'periods' => $periods + array_fill_keys(array_map(fn (RetentionCategory $c): string => $c->value, RetentionCategory::cases()), null),
        'confirmation' => $confirmation,
    ], fn ($value) => $value !== null);
}

it('mostra a tela com o que é apagado e o que fica, por categoria, e o mínimo da operadora', function () {
    actingAsMember($this->owner, $this->organization);

    $response = $this->get(route('settings.retention'));

    assertInertiaComponent($response, 'settings/retention');

    $props = $response->viewData('page')['props'];

    expect($props['enabled'])->toBeTrue()
        ->and($props['can'])->toBe(['configure' => true, 'manage_holds' => true])
        ->and(collect($props['categories'])->pluck('key')->all())->toBe(['completed', 'terminal_other', 'draft', 'identity_capture', 'dossier', 'audit_trail'])
        ->and($props['categories'][0]['minimum_days'])->toBe(1825)
        ->and($props['categories'][0]['deletes'])->not->toBeEmpty()
        ->and($props['categories'][0]['preserves'])->not->toBeEmpty()
        ->and($props['categories'][5]['available'])->toBeFalse()
        ->and($props['verification']['mode'])->toBe('notice_with_final_hash')
        ->and($props['confirmation_phrase'])->toBe('REDUZIR PRAZOS');
});

it('operador sem configurações da conta não abre nem grava', function () {
    $member = attachMember($this->organization, MembershipRole::Member);
    actingAsMember($member, $this->organization);

    $this->get(route('settings.retention'))->assertForbidden();
    $this->put(route('settings.retention.update'), retentionPayload(['completed' => 3650], confirmation: 'REDUZIR PRAZOS'))->assertForbidden();
});

it('não aceita prazo abaixo do mínimo legal configurado pela operadora', function () {
    config()->set('assinavelox.retention.minimum_days.completed', 3650);
    actingAsMember($this->owner, $this->organization);

    $this->put(route('settings.retention.update'), retentionPayload(['completed' => 1825], confirmation: 'REDUZIR PRAZOS'))
        ->assertSessionHasErrors(['periods.completed' => 'O prazo mínimo para “Documentos concluídos” é de 3650 dias (definido pela operadora).']);

    expect(RetentionPolicy::withoutOrganizationScope()->count())->toBe(0);
});

it('ativar ou reduzir exige digitar a frase de confirmação; aumentar não', function () {
    actingAsMember($this->owner, $this->organization);

    $this->put(route('settings.retention.update'), retentionPayload(['completed' => 3650]))
        ->assertSessionHasErrors('confirmation');

    $this->put(route('settings.retention.update'), retentionPayload(['completed' => 3650], confirmation: 'reduzir'))
        ->assertSessionHasErrors('confirmation');

    $this->put(route('settings.retention.update'), retentionPayload(['completed' => 3650], confirmation: 'REDUZIR PRAZOS'))
        ->assertSessionHasNoErrors();

    // Reduzir de novo sem a frase: recusado. Aumentar: livre.
    $this->put(route('settings.retention.update'), retentionPayload(['completed' => 2000]))->assertSessionHasErrors('confirmation');
    $this->put(route('settings.retention.update'), retentionPayload(['completed' => 4000]))->assertSessionHasNoErrors();

    $policy = RetentionPolicy::withoutOrganizationScope()->where('organization_id', $this->organization->id)->firstOrFail();

    expect($policy->is_active)->toBeTrue()
        ->and($policy->completed_days)->toBe(4000)
        ->and(RetentionEvent::withoutOrganizationScope()->where('event_type', RetentionEvent::POLICY_UPDATED)->count())->toBe(2);

    $last = RetentionEvent::withoutOrganizationScope()->where('event_type', RetentionEvent::POLICY_UPDATED)->orderByDesc('id')->first();

    expect($last->payload['before']['completed'])->toBe(3650)
        ->and($last->payload['after']['completed'])->toBe(4000)
        ->and($last->actor_user_id)->toBe($this->owner->id);
});

it('trilha de auditoria: bloqueada sem permissão da operadora e nunca antes dos documentos', function () {
    actingAsMember($this->owner, $this->organization);

    $this->put(route('settings.retention.update'), retentionPayload(['audit_trail' => 3650], confirmation: 'REDUZIR PRAZOS'))
        ->assertSessionHasErrors('periods.audit_trail');

    config()->set('assinavelox.retention.allow_audit_trail_deletion', true);

    $this->put(route('settings.retention.update'), retentionPayload(['completed' => 3650, 'audit_trail' => 2000], confirmation: 'REDUZIR PRAZOS'))
        ->assertSessionHasErrors('periods.audit_trail');

    $this->put(route('settings.retention.update'), retentionPayload(['completed' => 3650, 'audit_trail' => 3650], confirmation: 'REDUZIR PRAZOS'))
        ->assertSessionHasNoErrors();
});

it('subir o mínimo depois vale sobre o prazo já gravado', function () {
    $policy = retentionPolicyFor($this->organization, ['completed' => 1825]);

    config()->set('assinavelox.retention.minimum_days.completed', 3000);

    expect(RetentionPolicies::effectiveDays($policy->fresh(), RetentionCategory::Completed))->toBe(3000);
});

it('isola as organizações: gravar numa não muda a outra, e a tela só mostra a própria', function () {
    ['organization' => $other, 'owner' => $otherOwner] = createOrganizationWithOwner();
    retentionEnable($other);
    retentionPolicyFor($other, ['completed' => 9000]);

    actingAsMember($this->owner, $this->organization);
    $this->put(route('settings.retention.update'), retentionPayload(['completed' => 2000], confirmation: 'REDUZIR PRAZOS'))->assertSessionHasNoErrors();

    expect(RetentionPolicy::withoutOrganizationScope()->where('organization_id', $other->id)->value('completed_days'))->toBe(9000);

    $props = $this->get(route('settings.retention'))->viewData('page')['props'];

    expect($props['policy']['periods']['completed'])->toBe(2000);
});
