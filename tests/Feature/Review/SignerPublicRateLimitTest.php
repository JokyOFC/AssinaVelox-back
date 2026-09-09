<?php

use App\Services\Signing\SignerTokens;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Sign/Support/SignerHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial — `/assinar/*` não tem freio por IP
|--------------------------------------------------------------------------
| O grupo público do signatário usa `throttle:signer` (routes/web.php:74), definido em
| app/Providers/AppServiceProvider.php:145 como
|
|     Limit::perMinute(30)->by($request->ip().'|'.$request->route('token'))
|
| O token entra na CHAVE do limitador. Cada palpite é um token diferente, logo cada palpite
| cai em um balde novo: o limitador só freia quem repete o MESMO token. Um cliente anônimo
| pode disparar requisições sem teto contra a superfície pública, cada uma custando o
| SHA-256, uma consulta indexada e a renderização de uma página Inertia de 404.
|
| Toda outra rota pública do projeto tem freio por IP (`throttle:public`, 60/min,
| routes/web.php:53). O grupo do signatário — o único acessível sem conta e o que mais
| toca o banco — não tem nenhum.
|
| A entropia do token (32 bytes) torna a adivinhação inviável; o que este teste cobra é o
| limite de VOLUME por origem, que hoje não existe.
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/review-ratelimit-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('freia por IP quem varre a página do signatário com tokens diferentes', function () {
    $statuses = [];

    // 200 requisições do mesmo cliente, cada uma com um token novo e bem formado.
    for ($i = 0; $i < 200; $i++) {
        $statuses[] = $this->get('/assinar/'.SignerTokens::generate())->getStatusCode();
    }

    expect($statuses)->toContain(429);
});
