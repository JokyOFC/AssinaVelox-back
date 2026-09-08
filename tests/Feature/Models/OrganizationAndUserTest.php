<?php

use App\Enums\InvitationStatus;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\MembershipInvitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('criptografa tax_id em repouso e expõe iniciais', function () {
    $organization = Organization::factory()->create(['name' => 'Imobiliária Horizonte', 'tax_id' => '12.345.678/0001-90']);

    $raw = DB::table('organizations')->where('id', $organization->id)->value('tax_id');

    expect($raw)->not->toBe('12.345.678/0001-90')
        ->and($organization->fresh()->tax_id)->toBe('12.345.678/0001-90')
        ->and($organization->initials)->toBe('IH')
        ->and(Organization::initialsFor('Vega'))->toBe('VE')
        ->and($organization->setting('default_expiration_days'))->toBe(30)
        ->and($organization->setting('refusal_policy'))->toBe('close_envelope');
});

it('aplica settings da organização sobre os padrões', function () {
    $organization = Organization::factory()->create(['settings' => ['default_expiration_days' => 7]]);

    expect($organization->effectiveSettings()['default_expiration_days'])->toBe(7)
        ->and($organization->effectiveSettings()['otp_required'])->toBeTrue();
});

it('relaciona usuários e organizações via memberships com role e status', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->withCurrentOrganization($organization)->create();
    $member = User::factory()->create();

    Membership::factory()->owner()->create(['organization_id' => $organization->id, 'user_id' => $owner->id]);
    Membership::factory()->member()->suspended()->create(['organization_id' => $organization->id, 'user_id' => $member->id]);

    $organization = $organization->fresh();

    expect($organization->users)->toHaveCount(2)
        ->and($organization->memberships()->count())->toBe(2)
        ->and($owner->roleIn($organization))->toBe(MembershipRole::Owner)
        ->and($member->roleIn($organization))->toBe(MembershipRole::Member)
        ->and($member->membershipFor($organization)->status)->toBe(MembershipStatus::Suspended)
        ->and($owner->organizations()->first()->is($organization))->toBeTrue()
        ->and($owner->organizations()->first()->pivot->role)->toBe(MembershipRole::Owner)
        ->and($owner->currentOrganization->is($organization))->toBeTrue()
        ->and($owner->belongsToOrganization($organization))->toBeTrue()
        ->and(User::factory()->create()->belongsToOrganization($organization))->toBeFalse();
});

it('impede membership duplicada para o mesmo usuário e organização', function () {
    $membership = Membership::factory()->create();

    expect(fn () => Membership::factory()->create([
        'organization_id' => $membership->organization_id,
        'user_id' => $membership->user_id,
    ]))->toThrow(QueryException::class);
});

it('deriva o status do convite a partir das datas', function () {
    expect(MembershipInvitation::factory()->create()->status)->toBe(InvitationStatus::Pending)
        ->and(MembershipInvitation::factory()->accepted()->create()->status)->toBe(InvitationStatus::Accepted)
        ->and(MembershipInvitation::factory()->revoked()->create()->status)->toBe(InvitationStatus::Revoked)
        ->and(MembershipInvitation::factory()->expired()->create()->status)->toBe(InvitationStatus::Expired)
        ->and(MembershipInvitation::factory()->expired()->create()->isPending())->toBeFalse();
});

it('expõe colunas da plataforma no usuário', function () {
    $user = User::factory()->platformAdmin()->create(['name' => 'Marina Castelo Horizonte']);

    expect($user->fresh()->is_platform_admin)->toBeTrue()
        ->and($user->initials)->toBe('MH')
        ->and($user->locale)->toBe('pt_BR')
        ->and($user->terms_accepted_at)->not->toBeNull()
        ->and($user->hasTwoFactorEnabled())->toBeFalse()
        ->and(User::factory()->create()->is_platform_admin)->toBeFalse();

    // is_platform_admin não é mass-assignable.
    $other = User::query()->create(['name' => 'X', 'email' => 'x@exemplo.com', 'password' => 'password', 'is_platform_admin' => true]);
    expect($other->fresh()->is_platform_admin)->toBeFalse();
});

it('anula current_organization_id ao excluir a organização', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->withCurrentOrganization($organization)->create();

    $organization->forceDelete();

    expect($user->fresh()->current_organization_id)->toBeNull();
});
