<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes da Fase 4 §4.1 — verificação facial com documento
|--------------------------------------------------------------------------
| Incluído com require_once pelos arquivos de teste. Não contém testes.
|
| O provedor padrão destes testes é o SIMULADOR identificado (`driver=fake` com
| `channels.allow_simulated`): nenhuma imagem é analisada; `simulate()` força o resultado.
| O adaptador real da Verifiky tem testes próprios (VerifikyProviderTest); aqui, quando é
| preciso um provedor de verdade, entra um dublê ligado no contêiner.
*/

use App\Enums\FieldType;
use App\Integrations\Contracts\IdentityVerificationProvider;
use App\Integrations\Identity\FakeIdentityVerificationProvider;
use App\Services\Identity\IdentityVerifications;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

if (! function_exists('verificationEnvelope')) {
    /**
     * Envelope enviado, flags `identity_capture` + `identity_verification` ligadas, simulador
     * ativo e verificação exigida da primeira pessoa (o que também exige as três fotos).
     *
     * @return array<string, mixed>
     */
    function verificationEnvelope(string $driver = 'fake'): array
    {
        $ctx = signerEnvelope([
            ['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'fields' => [FieldType::Signature]],
            ['name' => 'Henrique Dias', 'email' => 'henrique@exemplo.test', 'fields' => [FieldType::Signature], 'order' => 1],
        ]);

        verificationEnable($ctx['organization'], $driver);

        app(IdentityVerifications::class)->setRequirement($ctx['envelope'], $ctx['recipients']['maria@exemplo.test'], true, $ctx['owner']);

        return $ctx + ['token' => $ctx['tokens']['maria@exemplo.test']];
    }
}

if (! function_exists('verificationEnable')) {
    /**
     * Liga as duas flags (global E plano) e escolhe o adaptador.
     */
    function verificationEnable(mixed $organization, string $driver = 'fake'): void
    {
        identityEnableFlags($organization, ['identity_capture', 'identity_verification']);
        config()->set('assinavelox.channels.allow_simulated', true);
        config()->set('assinavelox.identity_verification.driver', $driver);
    }
}

if (! function_exists('verificationFake')) {
    function verificationFake(): FakeIdentityVerificationProvider
    {
        return app(FakeIdentityVerificationProvider::class);
    }
}

if (! function_exists('verificationUploadPhotos')) {
    /**
     * As três fotos desta sessão (rosto, frente e verso), todas aceitas.
     */
    function verificationUploadPhotos(object $test, string $token): void
    {
        identityPostCapture($test, $token, 'selfie', identityPng(320, 240))->assertCreated();
        identityPostCapture($test, $token, 'document_front', identityPng(400, 260), 'frente.png')->assertCreated();
        identityPostCapture($test, $token, 'document_back', identityPng(400, 250), 'verso.png')->assertCreated();
    }
}

if (! function_exists('verificationPost')) {
    /**
     * @param  array<string, mixed>  $payload
     */
    function verificationPost(object $test, string $token, array $payload = []): TestResponse
    {
        return $test->postJson(
            route('sign.identity_verification.store', ['token' => $token]),
            $payload + ['document_type' => 'cnh', 'consent' => true],
        );
    }
}

if (! function_exists('verificationShow')) {
    function verificationShow(object $test, string $token): TestResponse
    {
        return $test->getJson(route('sign.identity_verification.show', ['token' => $token]));
    }
}

if (! function_exists('verifikyWebhookPost')) {
    /**
     * POST do webhook com o corpo cru e a assinatura HMAC-SHA256 (hexadecimal) no cabeçalho.
     *
     * @param  array<string, mixed>  $payload
     */
    function verifikyWebhookPost(object $test, array $payload, ?string $secret = 'segredo-do-webhook-de-teste', ?string $rawBody = null): TestResponse
    {
        $body = $rawBody ?? (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if ($secret !== null) {
            $server['HTTP_X_VERIFIKY_SIGNATURE'] = hash_hmac('sha256', $body, $secret);
        }

        return $test->call('POST', route('webhooks.verifiky'), [], [], [], $server, $body);
    }
}

if (! function_exists('verificationDouble')) {
    /**
     * Dublê de provedor ligado no contêiner: responde sempre o mesmo resultado, sem rede.
     *
     * @param  'pending'|'approved'|'rejected'|'inconclusive'|'expired'  $status
     * @param  array<string, mixed>  $details
     */
    function verificationDouble(string $status, array $details = [], ?string $verificationId = 'dbl-1'): IdentityVerificationProvider
    {
        $double = new class($status, $details, $verificationId) implements IdentityVerificationProvider
        {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            /**
             * @param  array<string, mixed>  $details
             */
            public function __construct(
                private readonly string $status,
                private readonly array $details,
                private readonly ?string $verificationId,
            ) {}

            public function start(string $recipientUlid, array $options = [], ?string $correlationId = null): array
            {
                $this->calls[] = ['method' => 'start', 'recipient' => $recipientUlid, 'options' => $options, 'correlation_id' => $correlationId];

                return $this->answer();
            }

            public function result(string $verificationId, ?string $correlationId = null): array
            {
                $this->calls[] = ['method' => 'result', 'verification_id' => $verificationId, 'correlation_id' => $correlationId];

                return $this->answer();
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function name(): string
            {
                return 'verifiky';
            }

            public function label(): string
            {
                return 'Verifiky';
            }

            public function isSimulated(): bool
            {
                return false;
            }

            /**
             * @return array{verification_id: string|null, provider: string, status: 'pending'|'approved'|'rejected'|'inconclusive'|'expired', checked_at: string, details: array<string, mixed>}
             */
            private function answer(): array
            {
                /** @var 'pending'|'approved'|'rejected'|'inconclusive'|'expired' $status */
                $status = $this->status;

                return [
                    'verification_id' => $this->verificationId,
                    'provider' => 'verifiky',
                    'status' => $status,
                    'checked_at' => Carbon::now()->toIso8601String(),
                    'details' => $this->details,
                ];
            }
        };

        app()->instance(IdentityVerificationProvider::class, $double);

        return $double;
    }
}
