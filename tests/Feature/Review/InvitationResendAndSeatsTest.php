<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial (isolamento/autorização) — R3/R4: reenvio de convite e assentos
|--------------------------------------------------------------------------
| Invitations::resend() zera revoked_at e renova expires_at sem validar o estado do
| convite nem os assentos (SeatUsage só é consultado em InvitationController::store);
| MembershipController::updateStatus reativa membros sem consultar SeatUsage.
*/

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\MembershipInvitation;
use App\Models\Plan;
use App\Services\Organizations\SeatUsage;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

beforeEach(function () {
    $this->withoutVite();
    Notification::fake();
});

test('[R3] reenviar não ressuscita um convite revogado', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    Plan::free()->update(['user_quota' => 10]);

    $invitation = MembershipInvitation::factory()->revoked()->create([
        'organization_id' => $organization->id,
        'invited_by_user_id' => $owner->id,
    ]);

    actingAsMember($owner, $organization);

    $this->post(route('invitations.resend', $invitation))->assertRedirect();

    $fresh = $invitation->fresh();

    expect($fresh->revoked_at)->not->toBeNull()
        ->and($fresh->isPending())->toBeFalse();
});

test('[R3] reenviar um convite expirado respeita o limite de assentos do plano', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    // Plano free: 1 assento, já ocupado pelo owner → nenhum convite pendente cabe.
    expect(SeatUsage::for($organization)['available'])->toBe(0);

    $invitation = MembershipInvitation::factory()->expired()->create([
        'organization_id' => $organization->id,
        'invited_by_user_id' => $owner->id,
    ]);

    actingAsMember($owner, $organization);

    $this->post(route('invitations.resend', $invitation))->assertRedirect();

    $seats = SeatUsage::for($organization);

    expect($invitation->fresh()->isPending())->toBeFalse()
        ->and($seats['used'] + $seats['pending_invitations'])->toBeLessThanOrEqual($seats['limit']);
});

test('[R4] reativar um membro suspenso respeita o limite de assentos do plano', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $suspended = attachMember($organization, MembershipRole::Member, MembershipStatus::Suspended);
    $membership = $suspended->membershipFor($organization);

    expect(SeatUsage::for($organization)['available'])->toBe(0);

    actingAsMember($owner, $organization);

    $this->patch(route('members.status', $membership), ['status' => 'active'])->assertRedirect();

    $seats = SeatUsage::for($organization);

    expect($seats['used'])->toBeLessThanOrEqual($seats['limit'])
        ->and($membership->fresh()->status)->toBe(MembershipStatus::Suspended);
});
