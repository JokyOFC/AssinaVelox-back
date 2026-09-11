<?php

use App\Services\Timestamp\Models\OperatorTsaIssuance;
use App\Services\Timestamp\TimestampVerifier;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/Support/TimestampHelpers.php';

/*
|--------------------------------------------------------------------------
| Endpoint interno da TSA: POST /tsa (application/timestamp-query → timestamp-reply)
|--------------------------------------------------------------------------
*/

const KTSA_HTTP_TOKEN_ENV = 'KTSA_TEST_TSA_HTTP_TOKEN';
const KTSA_HTTP_TOKEN = 'token-interno-da-tsa-0123456789abcdef';

beforeEach(function () {
    $this->work = ktsaWorkspace($this);
    putenv(KTSA_HTTP_TOKEN_ENV.'='.KTSA_HTTP_TOKEN);
    config()->set('assinavelox.tsa.http.token_env', KTSA_HTTP_TOKEN_ENV);
    config()->set('assinavelox.tsa.http.allowed_ips', ['127.0.0.1', '::1']);
});

afterEach(function () {
    putenv(KTSA_HTTP_TOKEN_ENV);
    ktsaCleanup($this->work ?? null);
});

if (! function_exists('ktsaPostTsa')) {
    /**
     * @param  array<string, string>  $headers
     */
    function ktsaPostTsa(object $test, string $body, array $headers = []): TestResponse
    {
        $server = [
            'CONTENT_TYPE' => 'application/timestamp-query',
            'HTTP_AUTHORIZATION' => 'Bearer '.KTSA_HTTP_TOKEN,
            'REMOTE_ADDR' => '127.0.0.1',
            ...$headers,
        ];

        return $test->call('POST', route('tsa.timestamp'), [], [], [], $server, $body);
    }
}

it('responde 404 com a flag desligada', function () {
    ktsaConfigureTsa($this->work, enableFlag: false);

    ktsaPostTsa($this, 'x')->assertNotFound();
});

it('exige Bearer correto, origem permitida e o tipo de conteúdo da RFC 3161', function () {
    ktsaConfigureTsa($this->work);
    $body = ktsaBuildRequest(hash('sha256', 'a'));

    ktsaPostTsa($this, $body, ['HTTP_AUTHORIZATION' => 'Bearer errado-errado-errado'])->assertStatus(401)->assertHeader('WWW-Authenticate', 'Bearer');
    ktsaPostTsa($this, $body, ['HTTP_AUTHORIZATION' => ''])->assertStatus(401);
    ktsaPostTsa($this, $body, ['REMOTE_ADDR' => '198.51.100.7'])->assertStatus(403);
    ktsaPostTsa($this, $body, ['CONTENT_TYPE' => 'application/json'])->assertStatus(415);
    ktsaPostTsa($this, str_repeat('A', 9000))->assertStatus(413);

    expect(OperatorTsaIssuance::query()->count())->toBe(0);
});

it('fecha o endpoint (503) quando o token não está configurado no ambiente', function () {
    ktsaConfigureTsa($this->work);
    putenv(KTSA_HTTP_TOKEN_ENV);

    ktsaPostTsa($this, ktsaBuildRequest(hash('sha256', 'a')))->assertStatus(503);
});

it('devolve um TimeStampResp válido, verificável e registrado no livro de seriais', function () {
    ktsaConfigureTsa($this->work);
    $digest = hash('sha256', 'conteúdo carimbado pelo endpoint');

    $response = ktsaPostTsa($this, ktsaBuildRequest($digest, nonce: 987654));

    $response->assertOk()->assertHeader('Content-Type', 'application/timestamp-reply')->assertHeader('X-TSA-Kind', 'operator');

    $verified = app(TimestampVerifier::class)->verify($response->getContent(), $digest);

    expect($verified['valid'])->toBeTrue()
        ->and($verified['trusted'])->toBeTrue()
        ->and($verified['nonce'])->toBe('987654');

    expect(OperatorTsaIssuance::query()->where('purpose', 'http')->where('status', 'granted')->value('serial'))->toBe($verified['serial']);
});

it('pedido com algoritmo não aceito vira resposta RFC 3161 de rejeição, sem token', function () {
    ktsaConfigureTsa($this->work);

    $response = ktsaPostTsa($this, ktsaBuildRequest(hash('sha1', 'a'), 'sha1'));

    $response->assertOk()->assertHeader('Content-Type', 'application/timestamp-reply');

    $verified = app(TimestampVerifier::class)->verify($response->getContent(), hash('sha1', 'a'), 'sha256');

    expect($verified['granted'])->toBeFalse()
        ->and($verified['valid'])->toBeFalse();

    expect(OperatorTsaIssuance::query()->where('status', 'rejected')->value('fail_info'))->toBe('bad_alg');
});
