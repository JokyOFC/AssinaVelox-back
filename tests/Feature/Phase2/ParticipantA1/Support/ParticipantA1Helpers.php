<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes do A1 do participante (K-A1, docs/fase-2/a1-do-participante.md)
|--------------------------------------------------------------------------
| Incluído com require_once. Não contém testes. Tudo roda contra o pdftool REAL, com
| certificados de TESTE gerados por `gen-test-participant-cert` (AC de teste descartável,
| CN com "TESTE") — nunca ICP-Brasil.
*/

use App\Enums\AccessLinkPurpose;
use App\Enums\DocumentVersionKind;
use App\Enums\FieldType;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\RecipientAccessLink;
use App\Services\Envelopes\Finalization\Support\TestCertificate;
use App\Services\Signing\Certificates\ParticipantCertificateConsent;
use App\Services\Signing\Certificates\ParticipantCertificateTool;
use App\Services\Signing\SignerDownloadGrants;
use App\Services\Signing\SignerTokens;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../../Finalization/Support/FinalizationHelpers.php';

if (! defined('PARTICIPANT_A1_OPERATOR_PASS_ENV')) {
    define('PARTICIPANT_A1_OPERATOR_PASS_ENV', 'PARTICIPANT_A1_OPERATOR_TEST_PASS');
}

if (! function_exists('participantA1Boot')) {
    /**
     * Área de trabalho, disco, diretórios temporário e selado exclusivos do teste; opcionalmente
     * o certificado de TESTE da operadora (assinatura final).
     */
    function participantA1Boot(object $test, bool $operator = true): void
    {
        if (! PdfFixtures::available()) {
            $test::markTestSkipped(PdfFixtures::skipMessage());
        }

        Notification::fake();

        $test->work = PdfFixtures::workspace();
        config()->set('pdftool.tmp_path', $test->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
        config()->set('assinavelox.participant_a1.sealed_path', $test->work.DIRECTORY_SEPARATOR.'selado');
        config()->set('app.url', 'https://assinavelox.test');
        finalizationDisk($test->work.DIRECTORY_SEPARATOR.'disco-documents');

        $test->operator = null;

        if ($operator) {
            putenv(PARTICIPANT_A1_OPERATOR_PASS_ENV.'=senha-da-operadora-Zq81!');
            $test->operator = TestCertificate::generate(
                $test->work.DIRECTORY_SEPARATOR.'certs-operadora',
                PARTICIPANT_A1_OPERATOR_PASS_ENV,
                TestCertificate::SUBJECT,
                2,
            );
            TestCertificate::configure($test->operator);
            TestCertificate::register($test->operator);
        }
    }
}

if (! function_exists('participantA1Teardown')) {
    function participantA1Teardown(object $test): void
    {
        putenv(PARTICIPANT_A1_OPERATOR_PASS_ENV);
        PdfFixtures::cleanup($test->work ?? null);
    }
}

if (! function_exists('participantA1Enable')) {
    /**
     * Liga a flag `participant_a1`: interruptor global E item do plano da organização.
     */
    function participantA1Enable(Organization $organization, bool $plan = true): void
    {
        config()->set('assinavelox.participant_a1.enabled', true);

        $current = $organization->currentSubscription()->with('plan')->first()?->plan;

        if ($current === null) {
            return;
        }

        $features = (array) ($current->features ?? []);
        $features['participant_a1'] = $plan;
        $current->forceFill(['features' => $features])->save();
    }
}

if (! function_exists('participantA1Scenario')) {
    /**
     * Envelope em `finalizing` (dois aceites já gravados, PDF real) com a flag ligada e um
     * link de convite por participante.
     *
     * @param  list<array<string, mixed>>|null  $recipients
     * @return array<string, mixed>
     */
    function participantA1Scenario(string $work, ?array $recipients = null): array
    {
        $recipients ??= [
            [
                'name' => 'Maria Alves Souza',
                'email' => 'maria@exemplo.test',
                'accepted' => true,
                'fields' => [
                    ['key' => 'maria.signature', 'type' => FieldType::Signature, 'page' => 1, 'x' => 0.10, 'y' => 0.60, 'width' => 0.30, 'height' => 0.08],
                    ['key' => 'maria.name', 'type' => FieldType::Name, 'page' => 1, 'x' => 0.10, 'y' => 0.70, 'width' => 0.40, 'height' => 0.04],
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
        participantA1Enable($scenario['organization']);

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

if (! function_exists('participantA1Certificate')) {
    /**
     * Certificado de TESTE de participante (AC de teste descartável). A senha só existe aqui,
     * no teste; o pdftool a recebe pelo ambiente do processo filho.
     *
     * @return array{pfx: string, ca: string, password: string, fingerprint: string, raw: array<string, mixed>}
     */
    function participantA1Certificate(
        string $directory,
        string $name,
        string $password,
        ?string $cpf = null,
        int $validFromDays = 0,
        int $days = 30,
        bool $noKey = false,
        string $keyUsage = 'signing',
    ): array {
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $slug = Str::slug($name).'-'.Str::lower(Str::random(6));
        $pfx = $directory.DIRECTORY_SEPARATOR.$slug.'.pfx';
        $ca = $directory.DIRECTORY_SEPARATOR.$slug.'-ac.pem';

        $raw = app(ParticipantCertificateTool::class)->generateTestCertificate($pfx, $password, $name, $cpf, $days, $validFromDays, $noKey, $keyUsage, $ca);

        return ['pfx' => $pfx, 'ca' => $ca, 'password' => $password, 'fingerprint' => (string) $raw['cert_fingerprint_sha256'], 'raw' => $raw];
    }
}

if (! function_exists('participantA1File')) {
    /**
     * Cópia do PFX num arquivo temporário, como o PHP faria com o upload (o serviço apaga).
     */
    function participantA1File(string $pfx): UploadedFile
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'av-pfx-');
        copy($pfx, $tmp);

        return new UploadedFile($tmp, 'certificado.pfx', 'application/x-pkcs12', null, true);
    }
}

if (! function_exists('participantA1Grant')) {
    /**
     * Janela deste navegador para quem já aceitou — o mesmo que `SignerDownloadGrants::issue`
     * grava depois do aceite ou de um novo código. Devolve os dados de sessão.
     *
     * @return array<string, string>
     */
    function participantA1Grant(Envelope $envelope, Recipient $recipient): array
    {
        $raw = SignerTokens::generate();

        RecipientAccessLink::query()->create([
            'recipient_id' => $recipient->getKey(),
            'envelope_id' => $envelope->getKey(),
            'document_version_id' => $envelope->sent_document_version_id,
            'organization_id' => $envelope->organization_id,
            'token_digest' => SignerTokens::digest($raw),
            'purpose' => AccessLinkPurpose::Download,
            'expires_at' => now()->addMinutes(30),
        ]);

        return [SignerDownloadGrants::sessionKey($recipient->ulid) => $raw];
    }
}

if (! function_exists('participantA1Intent')) {
    function participantA1Intent(object $test, array $scenario, string $email): TestResponse
    {
        return $test->withSession(participantA1Grant($scenario['envelope'], $scenario['recipients'][$email]))
            ->postJson(route('sign.certificate.intent', ['token' => $scenario['tokens'][$email]]));
    }
}

if (! function_exists('participantA1Show')) {
    function participantA1Show(object $test, array $scenario, string $email): TestResponse
    {
        return $test->withSession(participantA1Grant($scenario['envelope'], $scenario['recipients'][$email]))
            ->getJson(route('sign.certificate.show', ['token' => $scenario['tokens'][$email]]));
    }
}

if (! function_exists('participantA1Preview')) {
    /**
     * @param  array{pfx: string, password: string}  $certificate
     */
    function participantA1Preview(object $test, array $scenario, string $email, array $certificate, ?UploadedFile $file = null): TestResponse
    {
        return $test->withSession(participantA1Grant($scenario['envelope'], $scenario['recipients'][$email]))
            ->post(route('sign.certificate.inspect', ['token' => $scenario['tokens'][$email]]), [
                'certificate' => $file ?? participantA1File($certificate['pfx']),
                'password' => $certificate['password'],
            ], ['Accept' => 'application/json']);
    }
}

if (! function_exists('participantA1Submit')) {
    /**
     * @param  array{pfx: string, password: string}  $certificate
     * @param  array<string, mixed>  $overrides
     */
    function participantA1Submit(object $test, array $scenario, string $email, array $certificate, array $overrides = [], ?UploadedFile $file = null): TestResponse
    {
        return $test->withSession(participantA1Grant($scenario['envelope'], $scenario['recipients'][$email]))
            ->post(route('sign.certificate.store', ['token' => $scenario['tokens'][$email]]), array_merge([
                'certificate' => $file ?? participantA1File($certificate['pfx']),
                'password' => $certificate['password'],
                'consent' => '1',
                'consent_version' => ParticipantCertificateConsent::VERSION,
            ], $overrides), ['Accept' => 'application/json']);
    }
}

if (! function_exists('participantA1Versions')) {
    /**
     * @return list<DocumentVersion>
     */
    function participantA1Versions(Document $document, DocumentVersionKind $kind): array
    {
        return DocumentVersion::withoutOrganizationScope()
            ->where('document_id', $document->getKey())
            ->where('kind', $kind->value)
            ->orderBy('version_number')
            ->get()
            ->all();
    }
}

if (! function_exists('participantA1Leftovers')) {
    /**
     * Arquivos que sobraram no diretório temporário do pdftool e no diretório selado — depois
     * de qualquer operação, a lista precisa ser vazia.
     *
     * @return list<string>
     */
    function participantA1Leftovers(object $test): array
    {
        $found = [];

        foreach ([$test->work.DIRECTORY_SEPARATOR.'pdftool-tmp', $test->work.DIRECTORY_SEPARATOR.'selado'] as $root) {
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
