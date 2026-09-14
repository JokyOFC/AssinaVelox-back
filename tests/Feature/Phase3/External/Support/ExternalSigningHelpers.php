<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes da assinatura externa (P3-EXT, docs/fase-3/assinatura-externa-a3.md)
|--------------------------------------------------------------------------
| Incluído com require_once. Não contém testes. Tudo roda contra o pdftool REAL. O "token"
| é: (a) o SIMULADOR da aplicação (FakeLocalSigner, PKCS#12 de TESTE) ou (b) o dublê
| `external_signer.py`, que guarda a chave no diretório do teste e assina fora do servidor.
| Para exercitar um componente "real" habilitado usa-se um dublê identificado do NexU
| ({@see externalTokenBridge()}) — em produção o NexU está desabilitado.
*/

use App\Enums\AccessLinkPurpose;
use App\Enums\FieldType;
use App\Enums\LocalSignerComponent;
use App\Integrations\LocalSigner\Contracts\LocalSignerBridge;
use App\Integrations\LocalSigner\Dto\LocalSignerCertificate;
use App\Integrations\LocalSigner\Dto\LocalSignerSignature;
use App\Integrations\LocalSigner\Dto\LocalSignerStatus;
use App\Integrations\LocalSigner\Exceptions\LocalSignerUnavailable;
use App\Integrations\LocalSigner\LocalSignerBridges;
use App\Integrations\LocalSigner\NexuLocalSigner;
use App\Models\Organization;
use App\Models\RecipientAccessLink;
use App\Services\Pdf\PdfToolClient;
use App\Services\Signing\Certificates\ParticipantCertificateTool;
use App\Services\Signing\SignerTokens;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../../../Phase2/ParticipantA1/Support/ParticipantA1Helpers.php';

if (! defined('EXTERNAL_SIMULATOR_PASS_ENV')) {
    define('EXTERNAL_SIMULATOR_PASS_ENV', 'EXTERNAL_SIMULATOR_TEST_PASS');
    define('EXTERNAL_SIMULATOR_PASSWORD', 'senha-do-simulador-Tq19!');
}

if (! function_exists('externalBoot')) {
    /**
     * Área de trabalho do teste (a mesma do A1), diretório de reservas exclusivo e o SIMULADOR
     * configurado com um PKCS#12 de TESTE novo.
     */
    function externalBoot(object $test, bool $operator = true): void
    {
        participantA1Boot($test, $operator);

        config()->set('assinavelox.external_signing.pending_path', $test->work.DIRECTORY_SEPARATOR.'externa');
        config()->set('assinavelox.external_signing.trust_anchors', []);

        putenv(EXTERNAL_SIMULATOR_PASS_ENV.'='.EXTERNAL_SIMULATOR_PASSWORD);
        $directory = $test->work.DIRECTORY_SEPARATOR.'simulador';

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $pfx = $directory.DIRECTORY_SEPARATOR.'simulador.pfx';
        $ca = $directory.DIRECTORY_SEPARATOR.'simulador-ac.pem';
        $raw = app(ParticipantCertificateTool::class)->generateTestCertificate($pfx, EXTERNAL_SIMULATOR_PASSWORD, 'Maria Alves Souza', null, 30, 0, false, 'signing', $ca);

        config()->set('assinavelox.external_signing.components.simulated', [
            'enabled' => true,
            'allowed_environments' => ['local', 'testing'],
            'pfx_path' => $pfx,
            'pass_env' => EXTERNAL_SIMULATOR_PASS_ENV,
        ]);

        $test->simulator = ['pfx' => $pfx, 'ca' => $ca, 'password' => EXTERNAL_SIMULATOR_PASSWORD, 'fingerprint' => (string) $raw['cert_fingerprint_sha256']];
    }
}

if (! function_exists('externalTeardown')) {
    function externalTeardown(object $test): void
    {
        putenv(EXTERNAL_SIMULATOR_PASS_ENV);
        participantA1Teardown($test);
    }
}

if (! function_exists('externalEnable')) {
    /**
     * Liga a flag `a3_signing`: interruptor global E item do plano.
     */
    function externalEnable(Organization $organization, bool $plan = true): void
    {
        config()->set('assinavelox.external_signing.enabled', true);

        $current = $organization->currentSubscription()->with('plan')->first()?->plan;

        if ($current === null) {
            return;
        }

        $features = (array) ($current->features ?? []);
        $features['a3_signing'] = $plan;
        $current->forceFill(['features' => $features])->save();
    }
}

if (! function_exists('externalScenario')) {
    /**
     * Envelope em `finalizing` (dois aceites, PDF real) com a flag `a3_signing` ligada e um link
     * de convite por participante. A flag do A1 fica como está (desligada).
     *
     * @return array<string, mixed>
     */
    function externalScenario(string $work): array
    {
        $recipients = [
            [
                'name' => 'Maria Alves Souza',
                'email' => 'maria@exemplo.test',
                'accepted' => true,
                'fields' => [
                    ['key' => 'maria.signature', 'type' => FieldType::Signature, 'page' => 1, 'x' => 0.10, 'y' => 0.60, 'width' => 0.30, 'height' => 0.08],
                ],
            ],
            [
                'name' => 'Joao Lima',
                'email' => 'joao@exemplo.test',
                'accepted' => true,
                'fields' => [
                    ['key' => 'joao.signature', 'type' => FieldType::Signature, 'page' => 1, 'x' => 0.55, 'y' => 0.60, 'width' => 0.30, 'height' => 0.08],
                ],
            ],
        ];

        $scenario = finalizationEnvelope($work, $recipients);
        externalEnable($scenario['organization']);
        $scenario['tokens'] = [];

        foreach ($scenario['recipients'] as $email => $recipient) {
            $raw = SignerTokens::generate();

            RecipientAccessLink::query()->create([
                'recipient_id' => $recipient->getKey(),
                'envelope_id' => $scenario['envelope']->getKey(),
                'document_version_id' => $scenario['version']->getKey(),
                'organization_id' => $scenario['organization']->getKey(),
                'token_digest' => SignerTokens::digest($raw),
                'purpose' => AccessLinkPurpose::Signing,
                'expires_at' => $scenario['envelope']->expires_at,
            ]);

            $scenario['tokens'][$email] = $raw;
        }

        return $scenario;
    }
}

if (! function_exists('externalCall')) {
    /**
     * @param  array<string, mixed>  $body
     */
    function externalCall(object $test, array $scenario, string $email, string $method, string $route, array $body = []): TestResponse
    {
        $test->withSession(participantA1Grant($scenario['envelope'], $scenario['recipients'][$email]));
        $url = route($route, ['token' => $scenario['tokens'][$email]]);

        return $method === 'GET' ? $test->getJson($url) : $test->postJson($url, $body);
    }
}

if (! function_exists('externalShow')) {
    function externalShow(object $test, array $scenario, string $email): TestResponse
    {
        return externalCall($test, $scenario, $email, 'GET', 'sign.external.show');
    }

    function externalIntent(object $test, array $scenario, string $email): TestResponse
    {
        return externalCall($test, $scenario, $email, 'POST', 'sign.external.intent');
    }

    function externalWithdraw(object $test, array $scenario, string $email): TestResponse
    {
        return externalCall($test, $scenario, $email, 'POST', 'sign.external.withdraw');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    function externalPrepare(object $test, array $scenario, string $email, array $body): TestResponse
    {
        return externalCall($test, $scenario, $email, 'POST', 'sign.external.prepare', $body);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    function externalSubmit(object $test, array $scenario, string $email, array $body): TestResponse
    {
        return externalCall($test, $scenario, $email, 'POST', 'sign.external.submit', $body);
    }

    function externalSimulatorCertificate(object $test, array $scenario, string $email): TestResponse
    {
        return externalCall($test, $scenario, $email, 'GET', 'sign.external.simulator.certificate');
    }

    function externalSimulatorSign(object $test, array $scenario, string $email, string $pendingId): TestResponse
    {
        return externalCall($test, $scenario, $email, 'POST', 'sign.external.simulator.sign', ['pending_id' => $pendingId]);
    }
}

if (! function_exists('externalSimulatedPrepare')) {
    /**
     * Pede o certificado ao simulador e prepara o documento com ele.
     *
     * @return array<string, mixed> o `pending` da resposta
     */
    function externalSimulatedPrepare(object $test, array $scenario, string $email): array
    {
        $certificate = externalSimulatorCertificate($test, $scenario, $email)->assertOk()->json();

        return externalPrepare($test, $scenario, $email, [
            'document_id' => $scenario['document']->ulid,
            'component' => 'simulated',
            'mode' => 'raw',
            'certificate' => $certificate['certificate'],
            'chain' => $certificate['certificate_chain'],
        ])->assertCreated()->json('pending');
    }
}

if (! function_exists('externalTool')) {
    /**
     * Roda o dublê do token (`external_signer.py`) com o Python do venv do pdftool.
     *
     * @param  list<string>  $args
     * @return array<string, mixed>
     */
    function externalTool(array $args): array
    {
        $client = app(PdfToolClient::class);
        $process = new Process([$client->pythonBinary(), __DIR__.DIRECTORY_SEPARATOR.'external_signer.py', ...$args], $client->workingDirectory(), null, null, 120);
        $process->mustRun();

        return (array) json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Um "token" de TESTE com chave e certificado próprios (opcionalmente declarando A3).
     *
     * @return array{dir: string, certificate: string, chain: list<string>, fingerprint: string, ca_pem: string, key_pem: string}
     */
    function externalSigner(string $directory, string $name, bool $a3 = false): array
    {
        $out = externalTool(['gen', '--out-dir', $directory, '--name', $name, ...($a3 ? ['--a3'] : [])]);

        return ['dir' => $directory] + $out;
    }

    function externalSignRaw(array $signer, string $digestB64): string
    {
        return (string) externalTool(['sign-raw', '--dir', $signer['dir'], '--digest-b64', $digestB64])['signature'];
    }

    function externalSignCms(array $signer, string $digestB64): string
    {
        return (string) externalTool(['sign-cms', '--dir', $signer['dir'], '--digest-b64', $digestB64])['cms'];
    }
}

if (! function_exists('externalTokenBridge')) {
    /**
     * Dublê IDENTIFICADO de um componente real habilitado (no lugar do NexU, que em produção está
     * desabilitado). Quem assina de fato é o teste, com `external_signer.py`: o servidor só vê o
     * certificado, o digest e a assinatura — exatamente como com o componente real.
     */
    function externalTokenBridge(): void
    {
        $double = new class implements LocalSignerBridge
        {
            public function component(): LocalSignerComponent
            {
                return LocalSignerComponent::Nexu;
            }

            public function detect(): LocalSignerStatus
            {
                return new LocalSignerStatus(LocalSignerComponent::Nexu, true, false, false, 'dublê-de-teste', null);
            }

            public function signingCertificate(): LocalSignerCertificate
            {
                throw new LocalSignerUnavailable('invalid_request', 'Dublê de teste: quem fala com o token é o navegador.');
            }

            public function signDigest(string $keyHandle, string $digest, string $hashFunction): LocalSignerSignature
            {
                throw new LocalSignerUnavailable('invalid_request', 'Dublê de teste: quem fala com o token é o navegador.');
            }

            public function isSimulated(): bool
            {
                return false;
            }

            public function producesTokenSignatures(): bool
            {
                return true;
            }
        };

        app()->instance(LocalSignerBridges::class, new LocalSignerBridges(app(NexuLocalSigner::class), null, $double));
    }
}

if (! function_exists('externalTokenPrepare')) {
    /**
     * @param  array{certificate: string, chain: list<string>}  $signer
     * @return array<string, mixed>
     */
    function externalTokenPrepare(object $test, array $scenario, string $email, array $signer, string $mode = 'raw'): TestResponse
    {
        return externalPrepare($test, $scenario, $email, [
            'document_id' => $scenario['document']->ulid,
            'component' => 'nexu',
            'mode' => $mode,
            'certificate' => $signer['certificate'],
            'chain' => $signer['chain'],
        ]);
    }
}

if (! function_exists('externalLeftovers')) {
    /**
     * Arquivos que sobraram no diretório de reservas e no temporário do pdftool.
     *
     * @return list<string>
     */
    function externalLeftovers(object $test): array
    {
        $found = [];

        foreach ([$test->work.DIRECTORY_SEPARATOR.'externa', $test->work.DIRECTORY_SEPARATOR.'pdftool-tmp'] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

            foreach ($iterator as $item) {
                $found[] = $item->getPathname();
            }
        }

        return $found;
    }
}
