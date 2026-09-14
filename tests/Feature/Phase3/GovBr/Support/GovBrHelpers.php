<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes da devolução gov.br (P3-GOV, docs/fase-3/gov-br.md)
|--------------------------------------------------------------------------
| Incluído com require_once. Não contém testes. pdftool REAL; os arquivos "devolvidos" vêm de
| `Support/portal_simulator.py`, um SIMULADOR de teste do que um portal de assinatura faria
| (certificado de TESTE, AC de teste descartável) — não é o portal gov.br nem um modelo dele.
*/

use App\Enums\AccessLinkPurpose;
use App\Enums\DocumentVersionKind;
use App\Enums\FieldType;
use App\Jobs\Envelopes\FinalizeEnvelope;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\RecipientAccessLink;
use App\Models\SignatureAcceptance;
use App\Models\SigningField;
use App\Models\SigningFieldValue;
use App\Services\Envelopes\Finalization\FinalizationArtifacts;
use App\Services\Signing\SignerTokens;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Process\Process;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../../../Phase2/ParticipantA1/Support/ParticipantA1Helpers.php';

if (! defined('GOVBR_SIM_PASS_ENV')) {
    define('GOVBR_SIM_PASS_ENV', 'GOVBR_PORTAL_SIM_PASS');
}

if (! function_exists('govbrBoot')) {
    function govbrBoot(object $test): void
    {
        if (! PdfFixtures::available()) {
            $test::markTestSkipped(PdfFixtures::skipMessage());
        }

        Notification::fake();
        // A finalização real ainda não conhece a devolução (trava `finalizer_integration`,
        // docs/fase-3/gov-br.md §8): o teste confere que ela é RETOMADA, sem executá-la.
        Bus::fake([FinalizeEnvelope::class]);

        $test->work = PdfFixtures::workspace();
        config()->set('pdftool.tmp_path', $test->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
        config()->set('app.url', 'https://assinavelox.test');
        config()->set('assinavelox.govbr.trust_roots', []);
        config()->set('assinavelox.govbr.trust_root_fingerprints', []);
        config()->set('assinavelox.govbr.accept_test_certificates', true);
        finalizationDisk($test->work.DIRECTORY_SEPARATOR.'disco-documents');
    }
}

if (! function_exists('govbrTeardown')) {
    function govbrTeardown(object $test): void
    {
        PdfFixtures::cleanup($test->work ?? null);
    }
}

if (! function_exists('govbrEnable')) {
    /**
     * Liga a flag `govbr_return`: interruptor global, trava de integração e item do plano.
     */
    function govbrEnable(Organization $organization, bool $global = true, bool $integration = true, bool $plan = true): void
    {
        config()->set('assinavelox.govbr.return_enabled', $global);
        config()->set('assinavelox.govbr.finalizer_integration', $integration);

        $current = $organization->currentSubscription()->with('plan')->first()?->plan;

        if ($current === null) {
            return;
        }

        $features = (array) ($current->features ?? []);
        $features['govbr_return'] = $plan;
        $current->forceFill(['features' => $features])->save();
    }
}

if (! function_exists('govbrScenario')) {
    /**
     * Envelope em `finalizing` (dois aceites gravados, PDF real), a flag ligada, um link de
     * convite por participante e a BASE congelada (`pre_signature`) como a finalização deixaria.
     *
     * @return array<string, mixed>
     */
    function govbrScenario(string $work, bool $enable = true): array
    {
        $scenario = finalizationEnvelope($work, [
            ['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'accepted' => true, 'fields' => [
                ['key' => 'maria.name', 'type' => FieldType::Name, 'page' => 1, 'x' => 0.10, 'y' => 0.70, 'width' => 0.40, 'height' => 0.04],
            ]],
            ['name' => 'Joao Lima', 'email' => 'joao@exemplo.test', 'accepted' => true, 'fields' => [
                ['key' => 'joao.name', 'type' => FieldType::Name, 'page' => 1, 'x' => 0.55, 'y' => 0.70, 'width' => 0.30, 'height' => 0.04],
            ]],
        ]);

        if ($enable) {
            govbrEnable($scenario['organization']);
        }

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

        // Base congelada: o que a finalização grava antes das assinaturas criptográficas.
        $baseFile = $work.DIRECTORY_SEPARATOR.'base-congelada.pdf';
        copy($scenario['source'], $baseFile);
        $scenario['base'] = app(FinalizationArtifacts::class)->store(
            $scenario['envelope'],
            $scenario['document'],
            DocumentVersionKind::PreSignature,
            $baseFile,
        );

        return $scenario;
    }
}

if (! function_exists('govbrInformCpf')) {
    /**
     * O participante informou um CPF neste envelope (campo `cpf` com valor gravado no aceite).
     */
    function govbrInformCpf(array $scenario, string $email, string $cpf): void
    {
        $recipient = $scenario['recipients'][$email];

        $field = SigningField::factory()->create([
            'envelope_id' => $scenario['envelope']->getKey(),
            'document_version_id' => $scenario['version']->getKey(),
            'recipient_id' => $recipient->getKey(),
            'organization_id' => $scenario['organization']->getKey(),
            'type' => FieldType::Cpf,
            'page' => 2,
            'x' => 0.10,
            'y' => 0.10,
            'width' => 0.30,
            'height' => 0.04,
            'required' => true,
            'sort_order' => 9,
        ]);

        SigningFieldValue::query()->create([
            'signing_field_id' => $field->getKey(),
            'recipient_id' => $recipient->getKey(),
            'signature_acceptance_id' => SignatureAcceptance::query()->where('recipient_id', $recipient->getKey())->value('id'),
            'envelope_id' => $scenario['envelope']->getKey(),
            'organization_id' => $scenario['organization']->getKey(),
            'value_text' => substr($cpf, 0, 3).'.'.substr($cpf, 3, 3).'.'.substr($cpf, 6, 3).'-'.substr($cpf, 9, 2),
        ]);
    }
}

if (! function_exists('govbrCertificate')) {
    /**
     * @return array{pfx: string, ca: string, password: string, fingerprint: string, raw: array<string, mixed>}
     */
    function govbrCertificate(string $work, string $name, ?string $cpf = null): array
    {
        return participantA1Certificate($work.DIRECTORY_SEPARATOR.'certs', $name, 'senha-do-teste-'.substr(md5($name), 0, 6).'!', $cpf);
    }
}

if (! function_exists('govbrSimulate')) {
    /**
     * Produz o "arquivo devolvido" a partir de `$input` com o simulador de portal.
     *
     * @param  array{pfx: string, password: string}  $certificate
     * @param  array{pfx: string, password: string}|null  $second
     */
    function govbrSimulate(string $mode, string $input, string $output, array $certificate, ?array $second = null): string
    {
        $args = [
            PdfFixtures::pythonBinary(),
            base_path('tests/Feature/Phase3/GovBr/Support/portal_simulator.py'),
            '--mode', $mode,
            '--in', $input,
            '--out', $output,
            '--pfx', $certificate['pfx'],
            '--pass-env', GOVBR_SIM_PASS_ENV,
        ];

        $env = [
            'PYTHONUTF8' => '1',
            'PYTHONIOENCODING' => 'utf-8',
            'PYTHONPATH' => base_path('tools/pdftool'),
            GOVBR_SIM_PASS_ENV => $certificate['password'],
        ];

        if ($second !== null) {
            $args[] = '--second-pfx';
            $args[] = $second['pfx'];
            $args[] = '--second-pass-env';
            $args[] = GOVBR_SIM_PASS_ENV.'_2';
            $env[GOVBR_SIM_PASS_ENV.'_2'] = $second['password'];
        }

        $process = new Process($args, base_path('tools/pdftool'), $env, null, 180);
        $process->run();

        $decoded = json_decode(trim($process->getOutput()), true);

        if (! is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            throw new RuntimeException('portal_simulator falhou: '.trim($process->getOutput()).' '.mb_substr($process->getErrorOutput(), 0, 800));
        }

        return $output;
    }
}

if (! function_exists('govbrBaseFile')) {
    /**
     * Cópia local dos bytes de uma versão (a revisão reservada, como o participante baixaria).
     */
    function govbrBaseFile(DocumentVersion $version, string $path): string
    {
        file_put_contents($path, (string) Storage::disk('documents')->get($version->storage_path));

        return $path;
    }
}

if (! function_exists('govbrFile')) {
    function govbrFile(string $pdf): UploadedFile
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'av-govbr-');
        copy($pdf, $tmp);

        return new UploadedFile($tmp, 'documento-assinado.pdf', 'application/pdf', null, true);
    }
}

if (! function_exists('govbrCall')) {
    /**
     * Requisição autenticada deste participante (janela de download deste navegador).
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $data
     */
    function govbrCall(object $test, array $scenario, string $email, string $method, string $route, array $params = [], array $data = [], bool $authenticated = true): TestResponse
    {
        $recipient = $scenario['recipients'][$email];
        // Sem autenticação: sessão limpa (a de chamadas anteriores do mesmo teste não vale).
        $test = $authenticated ? $test->withSession(participantA1Grant($scenario['envelope'], $recipient)) : $test->flushSession();
        $url = route($route, ['token' => $scenario['tokens'][$email]] + $params);

        return match ($method) {
            'get' => $test->getJson($url),
            'download' => $test->get($url),
            'upload' => $test->post($url, $data, ['Accept' => 'application/json']),
            default => $test->postJson($url, $data),
        };
    }
}

if (! function_exists('govbrReserve')) {
    /**
     * Intenção + reserva. Devolve o ULID do pedido do primeiro documento.
     */
    function govbrReserve(object $test, array $scenario, string $email): string
    {
        govbrCall($test, $scenario, $email, 'post', 'sign.govbr.intent')->assertCreated();
        $state = govbrCall($test, $scenario, $email, 'post', 'sign.govbr.reserve')->assertOk()->json();

        return (string) $state['documents'][0]['request']['id'];
    }
}

if (! function_exists('govbrUpload')) {
    function govbrUpload(object $test, array $scenario, string $email, string $requestUlid, string $pdf): TestResponse
    {
        return govbrCall($test, $scenario, $email, 'upload', 'sign.govbr.upload', ['pedido' => $requestUlid], ['file' => govbrFile($pdf)]);
    }
}
