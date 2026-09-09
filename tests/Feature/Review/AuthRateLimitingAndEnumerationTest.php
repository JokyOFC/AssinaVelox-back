<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Revisão de segurança — autenticação: rate limiting e enumeração
|--------------------------------------------------------------------------
| Estes testes documentavam a AUSÊNCIA de limitador nos endpoints do Fortify
| (config/fortify.php `limiters` só cobre login, two-factor e passkeys) e
| pediam, no próprio cabeçalho, que as expectativas fossem invertidas quando a
| proteção chegasse.
|
| Ela chegou (H-SEC): o middleware App\Http\Middleware\ThrottleSensitiveRoutes
| pendura limitadores nomeados pelo NOME da rota, segundo o mapa
| `assinavelox.rate_limit_routes` — que é como se protege uma rota registrada
| por um pacote, sem editar vendor/. As expectativas abaixo estão invertidas.
| A tabela completa de limites está em docs/seguranca-operacional.md §2.
|
| A enumeração de usuários em /forgot-password segue sendo comportamento
| atual (primeiro teste) — é decisão de produto do Fortify, não deste agente.
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

test('POST /forgot-password é limitado por origem (varredura de muitos endereços)', function () {
    Notification::fake();

    $statuses = collect(range(1, 20))
        ->map(fn (int $i) => $this->post('/forgot-password', ['email' => "alvo{$i}@example.com"])->getStatusCode())
        ->unique()
        ->all();

    // 20 endereços diferentes do mesmo IP: o balde por origem (15/h) fecha a varredura.
    expect($statuses)->toContain(429);
});

test('POST /register é limitado por origem', function () {
    $statuses = collect(range(1, 20))
        ->map(fn () => $this->post('/register', [])->getStatusCode())
        ->unique()
        ->all();

    expect($statuses)->toContain(429);
});

test('POST /user/confirm-password não permite mais força bruta da senha da sessão', function () {
    $user = User::factory()->create();

    $statuses = collect(range(1, 30))
        ->map(fn (int $i) => $this->actingAs($user)
            ->from('/user/confirm-password')
            ->post('/user/confirm-password', ['password' => "errada-{$i}"])
            ->getStatusCode())
        ->unique()
        ->all();

    expect($statuses)->toContain(429);
    expect(session('auth.password_confirmed_at'))->toBeNull();
});

test('POST /login com e-mail em formato de array não derruba o limitador', function () {
    /*
     * O limitador `login` roda ANTES da validação do Fortify, sobre o input cru. Enquanto
     * ele fazia `Str::lower()` direto no valor, `email[]=a@b.c` produzia 500 — um erro de
     * servidor ao alcance de qualquer visitante. Agora entrada que não é string vira balde
     * vazio e o pedido segue para a validação, que o recusa.
     */
    $this->post('/login', ['email' => ['a@example.com'], 'password' => 'qualquer'])
        ->assertStatus(302)
        ->assertSessionHasErrors('email');
});
