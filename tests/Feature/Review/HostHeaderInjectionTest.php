<?php

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Revisão de segurança — injeção de Host nos links enviados por e-mail
|--------------------------------------------------------------------------
| A aplicação nunca chama `$middleware->trustHosts(...)` (bootstrap/app.php) nem
| `URL::forceRootUrl(config('app.url'))`. Como consequência, TODA URL absoluta gerada
| dentro de uma requisição (`route()`, `url()`, e portanto o link de redefinição de
| senha e o de verificação de e-mail) usa o host que veio na requisição:
|
|  - o cabeçalho `Host` bruto quando não há proxy confiável;
|  - o cabeçalho `X-Forwarded-Host` sempre que `TRUSTED_PROXIES` estiver definido
|    (AppServiceProvider::configureTrustedProxies() inclui HEADER_X_FORWARDED_HOST),
|    que é justamente o que docs/configuracao.md §7 item 2 manda configurar em produção.
|
| Resultado: um atacante que consiga forjar o Host faz o e-mail de "esqueci minha senha"
| da VÍTIMA apontar para o servidor dele, com o token de redefinição na URL.
|
| O comportamento esperado é o do próprio `.env.example`/docs: os links vivem em
| `APP_URL` (docs/configuracao.md §7 item 1 — "APP_URL com HTTPS").
*/

beforeEach(function () {
    $this->withoutVite();
    TrustProxies::flushState();
});

afterEach(fn () => TrustProxies::flushState());

function resetPasswordActionUrl(User $user): string
{
    $url = null;

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user, &$url): bool {
        $url = (string) $notification->toMail($user)->actionUrl;

        return true;
    });

    return (string) $url;
}

test('o link de redefinição de senha não pode ser construído a partir do Host da requisição', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'alvo@example.com']);

    $this->post('http://atacante.example/forgot-password', ['email' => 'alvo@example.com'])
        ->assertRedirect();

    $appHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

    expect(parse_url(resetPasswordActionUrl($user), PHP_URL_HOST))->toBe($appHost);
});

test('com TRUSTED_PROXIES configurado, X-Forwarded-Host não pode redirecionar o link de redefinição de senha', function () {
    config(['assinavelox.trusted_proxies' => '*']);
    (new AppServiceProvider(app()))->boot();

    Notification::fake();
    $user = User::factory()->create(['email' => 'alvo2@example.com']);

    $this->post('/forgot-password', ['email' => 'alvo2@example.com'], [
        'X-Forwarded-Host' => 'atacante.example',
        'X-Forwarded-Proto' => 'https',
    ])->assertRedirect();

    $appHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

    expect(parse_url(resetPasswordActionUrl($user), PHP_URL_HOST))->toBe($appHost);
});
