<?php

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| Revisão de segurança — IP do cliente com TRUSTED_PROXIES = "*"
|--------------------------------------------------------------------------
| `.env.example` e docs/configuracao.md §2.1 apresentam `TRUSTED_PROXIES=*` como o valor
| normal "atrás de balanceador próprio", e o §7 (checklist de produção) manda configurá-lo.
| Com `*`, o Symfony confia em TODA a cadeia de X-Forwarded-For e devolve o endereço mais à
| esquerda — ou seja, `request()->ip()` passa a ser escolhido pelo cliente.
|
| Isso quebra de uma vez:
|  - todo limitador com chave por IP (AppServiceProvider: `public`, `signer`, `otp-send`,
|    `otp-verify`, `webhook`; e o limitador `login` do Fortify, cuja chave é `email|ip`);
|  - o IP registrado como evidência (docs/arquitetura.md: `signature_acceptances.ip_address`
|    e `audit_events.ip_address` — "o IP registrado nos aceites depende desta configuração",
|    config/assinavelox.php), que passa a ser forjável pelo signatário.
|
| Comportamento esperado: o limitador precisa continuar valendo mesmo quando o cliente troca
| o X-Forwarded-For a cada requisição (confiar só no salto imediatamente anterior, ou usar
| uma chave que o cliente não controla).
*/

beforeEach(function () {
    $this->withoutVite();
    TrustProxies::flushState();
    RateLimiter::clear('public');
});

afterEach(fn () => TrustProxies::flushState());

test('o limitador público não pode ser zerado trocando o X-Forwarded-For a cada requisição', function () {
    config(['assinavelox.trusted_proxies' => '*']);
    (new AppServiceProvider(app()))->boot();

    // O limitador `public` é 60/min por IP (AppServiceProvider::configureRateLimiting).
    $statuses = [];

    for ($i = 1; $i <= 70; $i++) {
        $statuses[] = $this->get(route('legal.terms'), [
            'X-Forwarded-For' => '203.0.113.'.($i % 250 + 1),
        ])->getStatusCode();
    }

    expect($statuses)->toContain(429);
});
