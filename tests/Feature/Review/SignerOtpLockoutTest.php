<?php

use Illuminate\Support\Facades\File;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Sign/Support/SignerHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial — quem só tem o link tranca o signatário legítimo
|--------------------------------------------------------------------------
| Os dois limitadores do código por e-mail são chaveados APENAS pelo token do convite
| (app/Providers/AppServiceProvider.php:151-154):
|
|     otp-send   → 3 a cada 10 min por 'otp-send|'.$token
|     otp-verify → 5 a cada 10 min por 'otp-verify|'.$token
|
| O comentário no arquivo justifica tirar o IP da chave para que trocar de IP não reabra a
| força bruta do código. O efeito colateral não foi considerado: como a chave é o token e
| não a origem, os limites são um recurso COMPARTILHADO entre o signatário legítimo e
| qualquer pessoa que tenha a URL do convite.
|
| Quem tem só o link (e não lê o e-mail do destinatário) gasta as 5 tentativas de
| verificação com palpites errados e os 3 envios. A partir daí o signatário legítimo — em
| outro navegador, em outro IP, com o código correto na mão — recebe 429 tanto para
| verificar quanto para pedir outro código. O ataque é repetível a cada 10 minutos, então
| a negação é indefinida, e o signatário não tem nenhuma saída na interface.
|
| Além do bloqueio, as tentativas erradas do atacante entram na trilha do destinatário
| como `challenge.failed`, sujando a evidência do envelope.
|
| O limite por link e por IP que existe em `Challenges::sendLimiters()` não ajuda: ele só
| cobre o ENVIO, e a barreira que trava primeiro é a da rota.
|
| AJUSTE DESTA REVISÃO: o cenário descrito no achado ("o signatário legítimo, em outro
| navegador e outro IP") só é reproduzível se o teste realmente falar de DUAS origens —
| a versão original mandava as duas rodadas do mesmo `REMOTE_ADDR` (127.0.0.1), e nenhum
| limitador consegue distinguir duas pessoas atrás do mesmo endereço. As requisições do
| atacante e as da signatária agora saem de IPs diferentes, que é o que a correção
| (limite por `token|ip` além do limite por token) sabe separar.
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/review-otp-lockout-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('não deixa quem só tem o link impedir o signatário legítimo de confirmar o código', function () {
    $ctx = signerEnvelope();
    $token = $ctx['tokens']['maria@exemplo.test'];

    // --- Atacante: só tem a URL do convite; o código vai para o e-mail da signatária. ---
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7']);

    $this->post(route('sign.otp.send', ['token' => $token]))->assertRedirect();

    $codigoDaSignataria = $this->codes[count($this->codes) - 1];

    // Queima as tentativas de verificação da rota com palpites errados.
    for ($i = 0; $i < 6; $i++) {
        $this->post(route('sign.otp.verify', ['token' => $token]), [
            'code' => str_pad((string) $i, 6, '0', STR_PAD_LEFT),
        ]);
    }

    // Queima os envios restantes.
    for ($i = 0; $i < 3; $i++) {
        $this->post(route('sign.otp.send', ['token' => $token]));
    }

    // --- Signatária legítima: outro navegador, outro IP, com o código do e-mail. ---
    $this->flushSession();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42']);

    $verifica = $this->post(route('sign.otp.verify', ['token' => $token]), [
        'code' => $codigoDaSignataria,
    ]);

    $pedeOutro = $this->post(route('sign.otp.send', ['token' => $token]));

    expect($verifica->status())->not->toBe(429)
        ->and($pedeOutro->status())->not->toBe(429);

    // E ela tem saída: o código antigo morreu com as 5 tentativas erradas
    // (`auth_challenges.max_attempts`, o teto que de fato contém a força bruta), mas
    // pedir outro continua funcionando da origem dela.
    expect($pedeOutro->status())->toBe(302);
});
