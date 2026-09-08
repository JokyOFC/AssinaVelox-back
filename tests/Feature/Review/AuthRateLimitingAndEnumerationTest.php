<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Revisão de segurança — autenticação: rate limiting e enumeração
|--------------------------------------------------------------------------
| Estes testes documentam o comportamento ATUAL dos endpoints do Fortify que
| não recebem limitador (config/fortify.php `limiters` só cobre login,
| two-factor e passkeys). ROUTES_AND_PAGES §1.1 pede `throttle:6,1` em
| password.email. Quando a proteção for adicionada, inverta as expectativas.
*/

test('POST /forgot-password revela se o e-mail existe (enumeração de usuários)', function () {
    Notification::fake();
    User::factory()->create(['email' => 'conhecido@example.com']);

    $unknown = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'desconhecido@example.com']);
    $unknown->assertRedirect('/forgot-password');
    $unknown->assertSessionHasErrors(['email' => __('passwords.user')]);

    $known = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'conhecido@example.com']);
    $known->assertRedirect('/forgot-password');
    $known->assertSessionHasNoErrors();
    $known->assertSessionHas('status');

    // As duas respostas são distinguíveis: a mensagem abaixo confirma a inexistência da conta.
    expect(__('passwords.user'))->toBe('Não encontramos um usuário com este endereço de e-mail.');
});

test('POST /forgot-password não tem rate limiting por IP (spec pede throttle:6,1)', function () {
    Notification::fake();

    $statuses = collect(range(1, 20))
        ->map(fn (int $i) => $this->post('/forgot-password', ['email' => "alvo{$i}@example.com"])->getStatusCode())
        ->unique()
        ->all();

    // 20 requisições seguidas do mesmo IP, nenhuma bloqueada.
    expect($statuses)->toBe([302]);
});

test('POST /register não tem rate limiting', function () {
    $statuses = collect(range(1, 20))
        ->map(fn () => $this->post('/register', [])->getStatusCode())
        ->unique()
        ->all();

    // 20 tentativas do mesmo IP, todas respondidas com redirect de validação — nenhuma 429.
    expect($statuses)->toBe([302]);
});

test('POST /user/confirm-password permite força bruta da senha da sessão autenticada (sem throttle)', function () {
    $user = User::factory()->create();

    $statuses = collect(range(1, 30))
        ->map(fn (int $i) => $this->actingAs($user)
            ->from('/user/confirm-password')
            ->post('/user/confirm-password', ['password' => "errada-{$i}"])
            ->getStatusCode())
        ->unique()
        ->all();

    expect($statuses)->toBe([302]);
    expect(session('auth.password_confirmed_at'))->toBeNull();
});

test('POST /login com e-mail em formato de array devolve 500 no limitador (Str::lower em array)', function () {
    $response = $this->post('/login', ['email' => ['a@example.com'], 'password' => 'qualquer']);

    // O limitador `login` roda antes da validação do Fortify e chama Str::lower() no input bruto.
    $response->assertStatus(500);
});
