<?php

use App\Integrations\Contracts\IdentityVerificationProvider;
use App\Integrations\Dto\IdentityVerificationImage;
use App\Integrations\Identity\DisabledIdentityVerificationProvider;
use App\Integrations\Identity\FakeIdentityVerificationProvider;
use App\Integrations\Identity\Verifiky\VerifikyIdentityVerificationProvider;
use App\Integrations\Identity\Verifiky\VerifikyRequestSigner;
use App\Integrations\Identity\Verifiky\VerifikyResultMapper;
use App\Integrations\Identity\Verifiky\VerifikyWebhook;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Fase 4 §4.1 — adaptador da Verifiky (docs/integracoes/verifiky.md)
|--------------------------------------------------------------------------
| O contrato veio da integração do metta-bank: envio multipart em /api/verifiky/processar com
| Bearer, webhook assinado por HMAC e leitura assinada de /verificacoes/{id}. Aqui se prende:
|
|  - o pedido que sai (rota, cabeçalho, nomes dos campos do multipart) — sem tocar na rede;
|  - a leitura das três formas de resposta, com a regra "rosto ausente não é rosto diferente";
|  - T5: rede, tempo esgotado, 4xx e 5xx viram `inconclusive`, nunca aprovação;
|  - a chave e as imagens nunca aparecem no que é devolvido.
*/

const VERIFIKY_TEST_KEY = 'vk_teste_chave_que_nao_pode_vazar';

/**
 * @return array{reference: string, document_type: string, images: array<string, IdentityVerificationImage>}
 */
function verifikySubmission(bool $withBack = true): array
{
    $images = [
        'selfie' => new IdentityVerificationImage('bytes-da-selfie', 'image/jpeg', 'selfie.jpg'),
        'document_front' => new IdentityVerificationImage('bytes-da-frente', 'image/jpeg', 'documento-frente.jpg'),
    ];

    if ($withBack) {
        $images['document_back'] = new IdentityVerificationImage('bytes-do-verso', 'image/jpeg', 'documento-verso.jpg');
    }

    return ['reference' => '01JTESTEREFERENCIA00000000', 'document_type' => 'cnh', 'images' => $images];
}

function verifikyProvider(): VerifikyIdentityVerificationProvider
{
    return app(VerifikyIdentityVerificationProvider::class);
}

beforeEach(function () {
    Http::preventStrayRequests();

    config()->set('assinavelox.identity_verification.driver', 'verifiky');
    config()->set('assinavelox.identity_verification.verifiky', [
        'api_url' => 'https://app.verifiky.test/',
        'api_key' => VERIFIKY_TEST_KEY,
        'webhook_secret' => 'segredo-do-webhook',
        'hmac_secret' => 'segredo-das-leituras',
        'timeout' => 180,
        'verify_ssl' => true,
    ]);
});

describe('envio', function () {
    it('manda o multipart que a Verifiky espera, com Bearer, e devolve o protocolo', function () {
        Http::fake(['app.verifiky.test/api/verifiky/processar' => Http::response([
            'success' => true,
            'verification_id' => 4821,
            'status' => 'pending',
        ])]);

        $result = verifikyProvider()->start('01JRECIPIENTE0000000000000', verifikySubmission(), 'corr-1');

        expect($result['status'])->toBe('pending')
            ->and($result['verification_id'])->toBe('4821')
            ->and($result['provider'])->toBe('verifiky');

        Http::assertSent(function (Request $request): bool {
            $parts = collect($request->data())->keyBy('name');

            return $request->url() === 'https://app.verifiky.test/api/verifiky/processar'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer '.VERIFIKY_TEST_KEY)
                && $request->isMultipart()
                && $parts->keys()->sort()->values()->all() === ['documento', 'documento_verso', 'foto_ao_vivo', 'tipo_documento', 'user_reference']
                && $parts['user_reference']['contents'] === '01JTESTEREFERENCIA00000000'
                && $parts['tipo_documento']['contents'] === 'cnh'
                && $parts['foto_ao_vivo']['contents'] === 'bytes-da-selfie'
                && $parts['documento']['contents'] === 'bytes-da-frente'
                && $parts['documento_verso']['contents'] === 'bytes-do-verso';
        });
    });

    it('aceita o resultado final quando ele já vem na resposta do envio', function () {
        Http::fake(['*' => Http::response([
            'success' => true,
            'verificacao_id' => 'vk-77',
            'status' => 'approved',
            'verificado' => true,
            'face_match' => ['verified' => true, 'approved' => true, 'similarity' => 0.93127],
            'data' => ['dados_extraidos' => ['nome' => 'NOME QUE NAO PODE FICAR', 'cpf' => '19119119100']],
        ])]);

        $result = verifikyProvider()->start('01JRECIPIENTE0000000000000', verifikySubmission());

        expect($result['status'])->toBe('approved')
            ->and($result['verification_id'])->toBe('vk-77')
            ->and($result['details'])->toMatchArray(['face_match' => true, 'face_match_approved' => true, 'face_score' => 0.9313]);

        // Nada do que o provedor LEU do documento, nem a chave, entra no resultado guardado.
        expect(json_encode($result))->not->toContain('NOME QUE NAO PODE FICAR')
            ->not->toContain('19119119100')
            ->not->toContain(VERIFIKY_TEST_KEY);
    });

    it('sem chave não chama ninguém e diz o que falta', function () {
        config()->set('assinavelox.identity_verification.verifiky.api_key', '');
        Http::fake();

        $result = verifikyProvider()->start('01JRECIPIENTE0000000000000', verifikySubmission());

        expect(verifikyProvider()->isConfigured())->toBeFalse()
            ->and($result['status'])->toBe('inconclusive')
            ->and($result['details']['reason_code'])->toBe('not_configured')
            ->and($result['details']['missing'])->toBe(VerifikyIdentityVerificationProvider::MISSING);

        Http::assertNothingSent();
    });

    it('falha do provedor nunca vira aprovação (T5)', function (int $status, array $body, string $reasonCode) {
        Http::fake(['*' => Http::response($body, $status)]);

        $result = verifikyProvider()->start('01JRECIPIENTE0000000000000', verifikySubmission());

        expect($result['status'])->toBe('inconclusive')
            ->and($result['details']['reason_code'])->toBe($reasonCode)
            ->and($result['details']['http_status'])->toBe($status);
    })->with([
        'sem créditos (402)' => [402, ['message' => 'Créditos insuficientes'], 'insufficient_credits'],
        'crédito citado no texto' => [400, ['error' => 'saldo de credito esgotado'], 'insufficient_credits'],
        'chave recusada (401)' => [401, ['message' => 'Unauthorized'], 'provider_auth_failed'],
        'plano inativo (403)' => [403, ['message' => 'Forbidden'], 'plan_inactive'],
        'plano vencido citado no texto' => [500, ['message' => 'Seu plano expirou'], 'plan_inactive'],
        'limite de uso (429)' => [429, [], 'rate_limited'],
        'imagens recusadas (422)' => [422, ['message' => 'Rosto não encontrado na imagem'], 'provider_refused'],
        'erro interno (500)' => [500, ['message' => 'Internal Server Error'], 'provider_error'],
    ]);

    it('rede caída ou tempo esgotado é "inconclusivo", com aviso de que não é reprovação', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $result = verifikyProvider()->start('01JRECIPIENTE0000000000000', verifikySubmission());

        expect($result['status'])->toBe('inconclusive')
            ->and($result['details']['reason_code'])->toBe('provider_unavailable')
            ->and($result['details']['message'])->toContain('não é uma reprovação');
    });

    it('resposta 200 que não é JSON é "inconclusivo"', function () {
        Http::fake(['*' => Http::response('<html>manutenção</html>', 200)]);

        expect(verifikyProvider()->start('01JRECIPIENTE0000000000000', verifikySubmission())['details']['reason_code'])
            ->toBe('unreadable_result');
    });
});

describe('leitura do resultado', function () {
    it('consulta /verificacoes/{id} com Bearer e assinatura HMAC', function () {
        Http::fake(['app.verifiky.test/api/verifiky/verificacoes/4821' => Http::response([
            'data' => ['verification_id' => 4821, 'status' => 'approved', 'face_match' => ['match' => true, 'approved' => true]],
        ])]);

        $result = verifikyProvider()->result('4821');

        expect($result['status'])->toBe('approved')->and($result['verification_id'])->toBe('4821');

        Http::assertSent(function (Request $request): bool {
            $timestamp = $request->header('X-Verifiky-Timestamp')[0] ?? '';
            $nonce = $request->header('X-Verifiky-Nonce')[0] ?? '';
            $expected = hash_hmac('sha256', VerifikyRequestSigner::canonical('GET', $request->url(), $timestamp, $nonce), 'segredo-das-leituras');

            return $request->method() === 'GET'
                && $request->hasHeader('Authorization', 'Bearer '.VERIFIKY_TEST_KEY)
                && $timestamp !== '' && $nonce !== ''
                && hash_equals($expected, $request->header('X-Verifiky-Signature')[0] ?? '');
        });
    });

    it('sem o segredo das leituras, consulta só com o Bearer', function () {
        config()->set('assinavelox.identity_verification.verifiky.hmac_secret', '');
        Http::fake(['*' => Http::response(['verification_id' => 9, 'status' => 'pending'])]);

        expect(verifikyProvider()->result('9')['status'])->toBe('pending');

        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('X-Verifiky-Signature')
            && $request->hasHeader('Authorization', 'Bearer '.VERIFIKY_TEST_KEY));
    });

    it('o texto canônico da assinatura ordena a query e resume o corpo', function () {
        expect(VerifikyRequestSigner::canonical('get', 'https://app.verifiky.test/api/verifiky/verificacoes?b=2&a=1', '1700000000', 'abc'))
            ->toBe(implode("\n", ['GET', '/api/verifiky/verificacoes', 'a=1&b=2', '1700000000', 'abc', hash('sha256', '')]));
    });
});

describe('leitura das respostas (VerifikyResultMapper)', function () {
    it('lê as formas que a Verifiky usa', function (array $payload, string $status, ?string $reasonCode) {
        $mapped = VerifikyResultMapper::map($payload);

        expect($mapped['status'])->toBe($status)
            ->and($mapped['details']['reason_code'] ?? null)->toBe($reasonCode);
    })->with([
        'webhook aprovado, rosto como objeto' => [['event' => 'verification.completed', 'verification_id' => 1, 'status' => 'approved', 'face_match' => ['match' => true, 'approved' => true]], 'approved', null],
        'webhook aprovado, rosto como booleano' => [['verification_id' => 1, 'status' => 'approved', 'data' => ['face_match' => true]], 'approved', null],
        'aprovado só com "verificado"' => [['verification_id' => 1, 'status' => 'approved', 'verificado' => true], 'approved', null],
        'aprovado, mas o rosto não corresponde' => [['verification_id' => 1, 'status' => 'approved', 'face_match' => ['match' => false]], 'rejected', 'face_mismatch'],
        'aprovado, rosto corresponde, provedor não aprova a comparação' => [['verification_id' => 1, 'status' => 'approved', 'face_match' => ['match' => true, 'approved' => false]], 'rejected', 'face_mismatch'],
        'aprovado sem nenhuma informação do rosto: espera' => [['verification_id' => 1, 'status' => 'approved'], 'pending', null],
        'recusado' => [['verification_id' => 1, 'status' => 'rejected', 'reason' => 'Documento ilegível'], 'rejected', 'provider_rejected'],
        'declined' => [['verification_id' => 1, 'status' => 'declined'], 'rejected', 'provider_rejected'],
        'pendente sem rosto NÃO reprova (no metta-bank reprovava)' => [['verification_id' => 1, 'status' => 'pending'], 'pending', null],
        'pendente com rosto diferente' => [['verification_id' => 1, 'status' => 'pending', 'face_match' => false], 'rejected', 'face_mismatch'],
        'só o protocolo' => [['success' => true, 'verification_id' => 55], 'pending', null],
        'expirado' => [['verification_id' => 1, 'status' => 'expired'], 'expired', null],
        'success=false' => [['success' => false, 'message' => 'Falha no processamento'], 'inconclusive', 'provider_refused'],
        'status que ninguém conhece' => [['status' => 'banana'], 'inconclusive', 'unreadable_result'],
        'vazio' => [[], 'inconclusive', 'unreadable_result'],
    ]);

    it('acha o identificador e a referência onde quer que venham', function () {
        expect(VerifikyResultMapper::map(['verificacao_id' => 'a1'])['verification_id'])->toBe('a1')
            ->and(VerifikyResultMapper::map(['data' => ['verification_id' => 12]])['verification_id'])->toBe('12')
            ->and(VerifikyResultMapper::map(['user_reference' => 'REF-1'])['reference'])->toBe('REF-1')
            ->and(VerifikyResultMapper::map(['data' => ['user_reference' => 'REF-2']])['reference'])->toBe('REF-2');
    });

    it('guarda o motivo só quando há o que explicar, sem HTML e com tamanho limitado', function () {
        $rejected = VerifikyResultMapper::map(['verification_id' => 1, 'status' => 'rejected', 'reason' => '<b>Foto</b> escura '.str_repeat('x', 400)]);
        $approved = VerifikyResultMapper::map(['verification_id' => 1, 'status' => 'approved', 'verificado' => true, 'reason' => 'ok']);

        expect($rejected['details']['reason'])->toStartWith('Foto escura')
            ->and(mb_strlen($rejected['details']['reason']))->toBeLessThanOrEqual(301)
            ->and($approved['details'])->not->toHaveKey('reason');
    });
});

describe('webhook', function () {
    it('só aceita o corpo assinado com o segredo configurado', function () {
        $webhook = app(VerifikyWebhook::class);
        $body = '{"event":"verification.completed","verification_id":1}';
        $signature = hash_hmac('sha256', $body, 'segredo-do-webhook');

        expect($webhook->isAuthentic($body, $signature))->toBeTrue()
            ->and($webhook->isAuthentic($body, 'sha256='.$signature))->toBeTrue()
            ->and($webhook->isAuthentic($body, strtoupper($signature)))->toBeTrue()
            ->and($webhook->isAuthentic($body.' ', $signature))->toBeFalse()
            ->and($webhook->isAuthentic($body, hash_hmac('sha256', $body, 'outro-segredo')))->toBeFalse()
            ->and($webhook->isAuthentic($body, null))->toBeFalse()
            ->and($webhook->isAuthentic($body, ''))->toBeFalse();
    });

    it('sem segredo configurado, NADA é autêntico (no metta-bank tudo era aceito)', function () {
        config()->set('assinavelox.identity_verification.verifiky.webhook_secret', '');

        $webhook = app(VerifikyWebhook::class);
        $body = '{"status":"approved"}';

        expect($webhook->isConfigured())->toBeFalse()
            ->and($webhook->isAuthentic($body, hash_hmac('sha256', $body, '')))->toBeFalse();
    });

    it('reconhece o evento de verificação concluída e ignora o de antecedentes', function () {
        $webhook = app(VerifikyWebhook::class);

        expect($webhook->isVerificationCompleted(['event' => 'verification.completed']))->toBeTrue()
            ->and($webhook->isVerificationCompleted([]))->toBeTrue()
            ->and($webhook->isVerificationCompleted(['event' => 'background_check.completed']))->toBeFalse();
    });
});

describe('escolha do adaptador', function () {
    it('segue o driver; o simulador só existe com o interruptor ligado', function () {
        expect(app(IdentityVerificationProvider::class))->toBeInstanceOf(VerifikyIdentityVerificationProvider::class);

        config()->set('assinavelox.identity_verification.driver', 'disabled');
        expect(app(IdentityVerificationProvider::class))->toBeInstanceOf(DisabledIdentityVerificationProvider::class);

        config()->set('assinavelox.identity_verification.driver', 'fake');
        config()->set('assinavelox.channels.allow_simulated', false);
        expect(app(IdentityVerificationProvider::class))->toBeInstanceOf(DisabledIdentityVerificationProvider::class);

        config()->set('assinavelox.channels.allow_simulated', true);
        expect(app(IdentityVerificationProvider::class))->toBeInstanceOf(FakeIdentityVerificationProvider::class);
    });

    it('o desligado não chama ninguém e nunca aprova', function () {
        Http::fake();

        $result = app(DisabledIdentityVerificationProvider::class)->start('01JRECIPIENTE0000000000000', verifikySubmission());

        expect($result['status'])->toBe('inconclusive')->and($result['details']['reason_code'])->toBe('not_configured');

        Http::assertNothingSent();
    });

    it('o simulador se identifica, aprova por padrão e obedece ao resultado forçado', function () {
        config()->set('assinavelox.channels.allow_simulated', true);

        $fake = app(FakeIdentityVerificationProvider::class);
        $approved = $fake->start('01JRECIPIENTE0000000000000', verifikySubmission());

        expect($fake->isSimulated())->toBeTrue()
            ->and($approved['status'])->toBe('approved')
            ->and($approved['details']['simulated'])->toBeTrue()
            ->and($approved['details']['message'])->toContain('nenhum rosto foi comparado')
            ->and($fake->result((string) $approved['verification_id'])['status'])->toBe('approved');

        $fake->simulate('rejected');
        expect($fake->start('01JRECIPIENTE0000000000000', verifikySubmission())['status'])->toBe('rejected');

        $fake->simulate(null);
        expect($fake->start('01JRECIPIENTE0000000000000', ['reference' => 'x', 'document_type' => 'rg', 'images' => []])['details']['reason_code'])
            ->toBe('missing_images');
    });
});
