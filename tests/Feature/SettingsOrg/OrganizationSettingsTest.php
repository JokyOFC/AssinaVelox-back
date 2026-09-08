<?php

use App\Enums\MembershipRole;
use App\Services\Organizations\NotificationPreferences;
use App\Support\OrganizationSettings;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

beforeEach(fn () => $this->withoutVite());

test('geral: renderiza e salva dados da empresa (CPF/CNPJ validado)', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Horizonte']);
    actingAsMember($owner, $organization);

    $this->get(route('settings.general'))->assertInertia(fn (Assert $page) => $page
        ->component('settings/general')
        ->where('organization.name', 'Horizonte')
        ->where('organization.logo_url', null)
        ->where('security.require_two_factor', false)
        ->where('security.sso_enabled', false)
        ->where('deletion.requested_at', null)
        ->where('can.delete_organization', true));

    $this->patch(route('settings.organization.update'), [
        'name' => 'Imobiliária Horizonte',
        'legal_name' => 'Horizonte Negócios Ltda.',
        'tax_id' => '12.345.678/0001-95',
        'contact_email' => 'Contato@horizonte.com.br',
    ])->assertRedirect()->assertSessionHas('success', 'Alterações salvas.');

    $organization->refresh();
    expect($organization->name)->toBe('Imobiliária Horizonte')
        ->and($organization->legal_name)->toBe('Horizonte Negócios Ltda.')
        ->and($organization->tax_id)->toBe('12345678000195')
        ->and(OrganizationSettings::of($organization)->contactEmail())->toBe('contato@horizonte.com.br');

    $this->patch(route('settings.organization.update'), ['name' => 'X', 'tax_id' => '123'])
        ->assertSessionHasErrors(['name', 'tax_id']);
});

test('segurança: exigir 2FA e sessão de 12 h são persistidos em settings', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    actingAsMember($owner, $organization);

    $this->patch(route('settings.security.update'), ['require_two_factor' => false, 'session_idle_hours' => 8])
        ->assertSessionHasErrors('session_idle_hours');

    $this->patch(route('settings.security.update'), ['require_two_factor' => true, 'session_idle_hours' => 12])->assertRedirect();

    $settings = OrganizationSettings::of($organization->fresh());
    expect($settings->requireTwoFactor())->toBeTrue()->and($settings->sessionIdleHours())->toBe(12);

    // A partir daqui a organização exige 2FA: o owner sem TOTP é redirecionado para a segurança da conta.
    $this->get(route('settings.general'))->assertRedirect(route('security.edit'));
});

test('exclusão da organização exige owner com senha confirmada; DELETE cancela', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $admin = attachMember($organization, MembershipRole::Admin);

    // Sem senha confirmada: exige confirmação antes de qualquer coisa.
    actingAsMember($owner, $organization);
    $this->post(route('settings.organization.destroy'))->assertRedirect(route('password.confirm'));

    // Admin (mesmo com senha confirmada) não pode: só owner.
    $this->flushSession();
    $this->actingAs($admin)->withSession(confirmedPasswordSession($organization))
        ->post(route('settings.organization.destroy'))->assertForbidden();

    $this->flushSession();

    $this->actingAs($owner)->withSession(confirmedPasswordSession($organization))
        ->post(route('settings.organization.destroy'))->assertRedirect()->assertSessionHas('warning');
    expect(OrganizationSettings::of($organization->fresh())->deletionRequestedAt())->not->toBeNull();

    $this->actingAs($owner)->withSession(confirmedPasswordSession($organization))
        ->get(route('settings.general'))->assertInertia(fn (Assert $page) => $page->has('deletion.scheduled_for'));

    $this->actingAs($owner)->withSession(confirmedPasswordSession($organization))
        ->delete(route('settings.organization.destroy'))->assertRedirect()->assertSessionHas('success');
    expect(OrganizationSettings::of($organization->fresh())->deletionRequestedAt())->toBeNull();
});

test('padrões de assinatura: renderiza e salva', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    actingAsMember($owner, $organization);

    $this->get(route('settings.signing'))->assertInertia(fn (Assert $page) => $page
        ->component('settings/signing')
        ->where('defaults.expires_in_days', 30)
        ->where('defaults.signing_order', 'sequential')
        ->where('defaults.allow_drawn_signature', true)
        ->where('phase2.auth_methods', ['email_otp']));

    $this->patch(route('settings.signing.update'), [
        'expires_in_days' => 15,
        'signing_order' => 'parallel',
        'initials_on_all_pages' => true,
        'allow_typed_signature' => false,
        'allow_uploaded_signature' => true,
    ])->assertRedirect()->assertSessionHas('success');

    $settings = OrganizationSettings::of($organization->fresh());
    expect($settings->defaultExpirationDays())->toBe(15)
        ->and($settings->defaultSigningOrder()->value)->toBe('parallel')
        ->and($settings->initialsOnAllPages())->toBeTrue()
        ->and($settings->allowTypedSignature())->toBeFalse();

    $this->patch(route('settings.signing.update'), [
        'expires_in_days' => 120,
        'signing_order' => 'parallel',
        'initials_on_all_pages' => true,
        'allow_typed_signature' => false,
        'allow_uploaded_signature' => true,
    ])->assertSessionHasErrors('expires_in_days');
});

test('notificações: qualquer membro vê e salva as próprias preferências (canais travados são ignorados)', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $member = attachMember($organization, MembershipRole::Member);
    actingAsMember($member, $organization);

    $this->get(route('settings.notifications'))->assertInertia(fn (Assert $page) => $page
        ->component('settings/notifications')
        ->has('events', 7)
        ->where('events.0.key', 'recipient_signed')
        ->where('events.0.channels.mail', true)
        ->where('events.4.key', 'daily_digest')
        ->where('events.4.locked.database', true)
        ->where('digest_time_label', '08:00 (America/Sao_Paulo)'));

    $preferences = array_fill_keys(NotificationPreferences::events(), []);
    $preferences['recipient_signed'] = ['database'];
    $preferences['daily_digest'] = ['mail', 'database'];
    $preferences['product_news'] = ['mail'];

    $this->patch(route('settings.notifications.update'), ['preferences' => $preferences])->assertRedirect()->assertSessionHas('success');

    $effective = app(NotificationPreferences::class)->for($member->membershipFor($organization)->fresh());
    expect($effective['recipient_signed'])->toBe(['database'])
        ->and($effective['daily_digest'])->toBe(['mail'])
        ->and($effective['product_news'])->toBe([])
        ->and($effective['envelope_completed'])->toBe([]);

    // As preferências do owner permanecem nos padrões.
    $ownerEffective = app(NotificationPreferences::class)->for($owner->membershipFor($organization));
    expect($ownerEffective['recipient_signed'])->toBe(['mail', 'database']);

    $this->patch(route('settings.notifications.update'), ['preferences' => ['recipient_signed' => ['sms']]])
        ->assertSessionHasErrors();
});

test('placeholders de Fase 2 e páginas de plano/cobrança respondem com o componente correto', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    actingAsMember($owner, $organization);

    $this->get(route('templates.index'))->assertInertia(fn (Assert $page) => $page->component('templates/index')->where('feature', 'templates'));
    $this->get(route('integrations.index'))->assertInertia(fn (Assert $page) => $page->component('integrations/index'));
    $this->get(route('integrations.keys'))->assertRedirect(route('integrations.index'));
    $this->get(route('integrations.logs'))->assertRedirect(route('integrations.index'));
    $this->get(route('recipients.index'))->assertInertia(fn (Assert $page) => $page->component('recipients/index')->has('recipients.data', 0)->has('recipients.meta.total'));
    $this->get(route('billing.index'))->assertInertia(fn (Assert $page) => $page->component('settings/billing')->where('subscription.plan.key', 'free')->has('payments.data'));
    $this->get(route('plans.index'))->assertInertia(fn (Assert $page) => $page->component('settings/plans')->where('current_plan', 'free')->has('plans'));
    $this->get(route('billing.return', ['outcome' => 'pending']))->assertRedirect(route('billing.index'));
});
