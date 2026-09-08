<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial (isolamento/autorização) — R1: middleware `verified`
|--------------------------------------------------------------------------
| As rotas da aplicação e do painel interno usam o alias `verified`
| (Illuminate\Auth\Middleware\EnsureEmailIsVerified). Esse middleware só bloqueia
| usuários cujo model implementa Illuminate\Contracts\Auth\MustVerifyEmail — e
| App\Models\User NÃO implementa a interface (import comentado). O mesmo vale para o
| listener SendEmailVerificationNotification (o e-mail de verificação nunca é enviado
| no cadastro). Estes testes descrevem o comportamento ESPERADO pelo contrato
| (arquitetura §1 "verificação de e-mail"; docs/autorizacao-e-isolamento.md §4).
*/

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

beforeEach(fn () => $this->withoutVite());

test('[R1] usuário com e-mail não verificado é barrado pelo middleware verified nas rotas da aplicação', function () {
    $unverified = User::factory()->unverified()->create();
    ['organization' => $organization] = createOrganizationWithOwner([], $unverified);

    actingAsMember($unverified, $organization);

    $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
    $this->get(route('envelopes.index'))->assertRedirect(route('verification.notice'));
    $this->get(route('members.index'))->assertRedirect(route('verification.notice'));
});

test('[R1] platform admin com e-mail não verificado é barrado no painel interno', function () {
    $admin = User::factory()->platformAdmin()->unverified()->create();

    $this->actingAs($admin)
        ->get(route('admin.organizations.index'))
        ->assertRedirect(route('verification.notice'));
});

test('[R1] o cadastro dispara a notificação de verificação de e-mail', function () {
    Notification::fake();

    $this->post(route('register.store'), [
        'name' => 'Ana Ribeiro',
        'email' => 'ana@exemplo.com.br',
        'organization_name' => 'Imobiliária Exemplo',
        'organization_tax_id' => '',
        'password' => 'Senha@Forte123',
        'password_confirmation' => 'Senha@Forte123',
        'terms' => true,
    ]);

    $user = User::query()->where('email', 'ana@exemplo.com.br')->firstOrFail();

    expect($user->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($user, VerifyEmail::class);
});
