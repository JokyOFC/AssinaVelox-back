<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Membership;
use App\Models\MembershipInvitation;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Organizations\Invitations;
use Laravel\Fortify\Features;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
    $this->withoutVite();
});

function validRegistration(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ana Ribeiro',
        'email' => 'ana@exemplo.com.br',
        'organization_name' => 'Imobiliária Exemplo',
        'organization_tax_id' => '',
        'password' => 'Senha@Forte123',
        'password_confirmation' => 'Senha@Forte123',
        'terms' => true,
    ], $overrides);
}

test('a tela de cadastro é renderizada com a versão dos termos', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
    expect($response->viewData('page')['props']['termsVersion'])->toBe(config('assinavelox.terms_version'));
});

test('o cadastro cria usuário, organização, membership owner ativa e assinatura free ativa', function () {
    $response = $this->post(route('register.store'), validRegistration());

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('email', 'ana@exemplo.com.br')->firstOrFail();
    expect($user->terms_accepted_at)->not->toBeNull()
        ->and($user->terms_version)->toBe(config('assinavelox.terms_version'));

    $organization = Organization::query()->where('name', 'Imobiliária Exemplo')->firstOrFail();
    expect($organization->created_by_user_id)->toBe($user->id)
        ->and($user->current_organization_id)->toBe($organization->id);

    $membership = Membership::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->firstOrFail();
    expect($membership->role)->toBe(MembershipRole::Owner)
        ->and($membership->status)->toBe(MembershipStatus::Active);

    $subscription = Subscription::withoutOrganizationScope()->where('organization_id', $organization->id)->with('plan')->firstOrFail();
    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->plan->code)->toBe(Plan::CODE_FREE);
});

test('o cadastro exige empresa e aceite dos termos', function () {
    $response = $this->from(route('register'))->post(route('register.store'), validRegistration([
        'organization_name' => '',
        'terms' => false,
    ]));

    $response->assertSessionHasErrors(['organization_name', 'terms']);
    $this->assertGuest();
    expect(Organization::query()->count())->toBe(0);
});

test('o cadastro aceita CNPJ e CPF válidos e rejeita documentos inválidos', function (string $taxId, bool $valid) {
    $response = $this->from(route('register'))->post(route('register.store'), validRegistration([
        'organization_tax_id' => $taxId,
    ]));

    if ($valid) {
        $response->assertSessionDoesntHaveErrors('organization_tax_id');
        $organization = Organization::query()->firstOrFail();
        expect($organization->tax_id)->toBe(preg_replace('/\D/', '', $taxId));
    } else {
        $response->assertSessionHasErrors('organization_tax_id');
        expect(Organization::query()->count())->toBe(0);
    }
})->with([
    'CNPJ válido' => ['12.345.678/0001-95', true],
    'CPF válido' => ['529.982.247-25', true],
    'CNPJ inválido' => ['12.345.678/0001-00', false],
    'CPF inválido' => ['111.111.111-11', false],
    'texto' => ['abc', false],
]);

test('o cadastro via convite não cria organização', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $token = Invitations::generateToken();

    MembershipInvitation::factory()->create([
        'organization_id' => $organization->id,
        'email' => 'convidado@exemplo.com.br',
        'token_digest' => Invitations::digest($token),
        'invited_by_user_id' => $owner->id,
    ]);

    $response = $this->post(route('register.store'), validRegistration([
        'email' => 'convidado@exemplo.com.br',
        'organization_name' => '',
        'invitation' => $token,
    ]));

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    expect(Organization::query()->count())->toBe(1)
        ->and(Membership::query()->where('user_id', User::query()->where('email', 'convidado@exemplo.com.br')->value('id'))->count())->toBe(0);
});
