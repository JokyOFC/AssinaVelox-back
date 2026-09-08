<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial (isolamento/autorização) — escopo global × props compartilhadas
|--------------------------------------------------------------------------
| HandleInertiaRequests::organizations() carrega `organization.currentSubscription.plan`
| de TODAS as memberships do usuário enquanto a organização corrente está definida.
| Subscription usa BelongsToOrganization: o escopo global filtra as assinaturas das
| outras organizações e o switcher mostra o plano errado para elas.
*/

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Plan;
use App\Models\Subscription;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

beforeEach(fn () => $this->withoutVite());

test('[R6] o switcher mostra o plano real de cada organização, não só o da corrente', function () {
    $current = createOrganizationWithOwner(['name' => 'Corrente']);
    $other = createOrganizationWithOwner(['name' => 'Outra']);
    $user = $current['owner'];
    attachMember($other['organization'], MembershipRole::Admin, MembershipStatus::Active, $user);

    // "Outra" migra para o plano Profissional.
    $professional = Plan::factory()->professional()->create();
    Subscription::withoutOrganizationScope()->where('organization_id', $other['organization']->id)->update(['plan_id' => $professional->id]);

    actingAsMember($user, $current['organization']);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('organization.name', 'Corrente')
        ->where('organization.plan.name', 'Grátis')
        ->has('organizations', 2)
        ->where('organizations.0.name', 'Corrente')
        ->where('organizations.0.plan_name', 'Grátis')
        ->where('organizations.1.name', 'Outra')
        ->where('organizations.1.plan_name', $professional->name));
});
