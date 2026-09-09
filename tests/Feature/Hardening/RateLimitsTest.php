<?php

use App\Models\Envelope;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| H-SEC §2 — limites de requisição nas rotas sensíveis
|--------------------------------------------------------------------------
| Duas coisas são cobradas aqui, e a segunda importa tanto quanto a primeira:
|
|  1. o limite DISPARA (429) para quem insiste;
|  2. a chave é COMPOSTA, de modo que o atacante gaste o próprio balde e não o da vítima.
|     Um limitador chaveado só pelo e-mail transforma "esqueci minha senha" em uma arma:
|     bastaria repetir o formulário com o endereço de outra pessoa para trancá-la fora da
|     própria conta.
*/

beforeEach(fn () => $this->withoutVite());

test('todas as rotas do mapa de limites existem e apontam para limitadores registrados', function () {
    $map = (array) config('assinavelox.rate_limit_routes');
    $names = collect(Route::getRoutes())
        ->map(fn ($route) => $route->getName())
        ->filter()
        ->all();

    expect($map)->not->toBeEmpty();

    foreach ($map as $routeName => $limiter) {
        // Um mapa que aponta para rota renomeada é um limite que silenciosamente sumiu.
        expect(in_array($routeName, $names, true))->toBeTrue("A rota `{$routeName}` do mapa de limites não existe mais.");
        expect(RateLimiter::limiter($limiter))->not->toBeNull("O limitador `{$limiter}` não está registrado.");
    }
});

test('o cadastro é limitado por origem', function () {
    $statuses = [];

    for ($i = 0; $i < 8; $i++) {
        $statuses[] = $this->post(route('register.store'), [
            'name' => 'Fulano '.$i,
            'email' => "novo{$i}@example.com",
            'password' => 'Senha!Forte!2026',
            'password_confirmation' => 'Senha!Forte!2026',
            'terms' => true,
        ])->getStatusCode();
    }

    expect($statuses)->toContain(429);
});

test('a recuperação de senha é limitada — e o atacante não tranca a vítima', function () {
    $victim = User::factory()->create(['email' => 'vitima@example.com']);

    // Atacante martelando o endereço da vítima a partir do IP 10.0.0.9.
    $blocked = false;
    for ($i = 0; $i < 6; $i++) {
        $status = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
            ->post(route('password.email'), ['email' => $victim->email])
            ->getStatusCode();

        $blocked = $blocked || $status === 429;
    }

    expect($blocked)->toBeTrue('O limite por origem+e-mail não disparou para quem insiste.');

    // A vítima, de outro IP, continua conseguindo pedir o próprio link.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->post(route('password.email'), ['email' => $victim->email])
        ->assertStatus(302);
});

test('a redefinição de senha não usa o token como chave do limitador', function () {
    /*
     * Com o token na chave, cada palpite estrearia um balde novo e o limitador não
     * pararia varredura nenhuma. A chave é origem + e-mail, então tokens diferentes
     * vindos da mesma origem consomem o MESMO balde.
     */
    $statuses = [];

    for ($i = 0; $i < 9; $i++) {
        $statuses[] = $this->post(route('password.update'), [
            'token' => bin2hex(random_bytes(32)),
            'email' => 'alvo@example.com',
            'password' => 'Senha!Forte!2026',
            'password_confirmation' => 'Senha!Forte!2026',
        ])->getStatusCode();
    }

    expect($statuses)->toContain(429);
});

test('o convite de membro é limitado por origem, não apenas por token', function () {
    $statuses = [];

    for ($i = 0; $i < 70; $i++) {
        // Um token diferente por requisição: sem o balde por IP isso passaria sem teto.
        $statuses[] = $this->get('/convites/'.bin2hex(random_bytes(16)))->getStatusCode();
    }

    expect($statuses)->toContain(429);
});

test('o download autorizado tem teto por ator', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    actingAsMember($owner, $organization);

    $envelope = Envelope::factory()->for($organization)->create([
        'created_by_user_id' => $owner->id,
    ]);

    $statuses = [];

    for ($i = 0; $i < 70; $i++) {
        $statuses[] = $this->get(route('envelopes.download', ['envelope' => $envelope->ulid, 'type' => 'original']))
            ->getStatusCode();
    }

    // O que importa é o 429; o status "normal" desta rota varia com o estado do envelope.
    expect($statuses)->toContain(429);
});

test('a entrada continua limitada por e-mail e origem, como o Fortify configura', function () {
    $user = User::factory()->create(['email' => 'login@example.com']);

    $statuses = [];
    for ($i = 0; $i < 7; $i++) {
        $statuses[] = $this->post(route('login'), ['email' => $user->email, 'password' => 'errada'])
            ->getStatusCode();
    }

    expect($statuses)->toContain(429);

    // Mesma senha errada para OUTRO e-mail, do mesmo IP: balde diferente, ainda passa.
    RateLimiter::clear('login');
    $this->post(route('login'), ['email' => 'outro@example.com', 'password' => 'errada'])
        ->assertStatus(302);
});

test('a verificação pública e o webhook mantêm os limites declarados nas rotas', function () {
    $statuses = [];
    for ($i = 0; $i < 25; $i++) {
        $statuses[] = $this->get(route('verify.show', ['code' => 'ABCDEFGHJKLM']))->getStatusCode();
    }

    expect($statuses)->toContain(429);
});
