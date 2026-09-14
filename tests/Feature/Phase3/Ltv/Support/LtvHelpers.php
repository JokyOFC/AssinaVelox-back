<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes do P3-LTV (PAdES de longo prazo e re-carimbo)
|--------------------------------------------------------------------------
| Incluído com require_once. Não contém testes. Tudo roda contra o pdftool real, com uma PKI de
| TESTE gerada por `ltv-gen-test-pki` (AC raiz, assinante, TSA com EKU timeStamping crítica, CRL e
| OCSP em arquivo — sem rede). A senha dos PKCS#12 existe só numa variável de ambiente, sob um nome.
*/

use App\Enums\DocumentVersionKind;
use App\Enums\SignatureStatus;
use App\Jobs\Ltv\RefreshArchiveTimestamp;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\VerificationRecord;
use App\Models\VerificationRecordDocument;
use App\Services\Envelopes\Finalization\FinalizationArtifacts;
use App\Services\Envelopes\Finalization\Support\TestCertificate;
use App\Services\Ltv\LtvSigner;
use App\Services\Ltv\LtvState;
use App\Services\Timestamp\TsaToolRunner;

require_once __DIR__.'/../../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../../Finalization/Support/FinalizationHelpers.php';
require_once __DIR__.'/../../../Phase2/Timestamp/Support/TimestampHelpers.php';

const LTV_PASS_ENV = 'P3LTV_TEST_PKI_PASSWORD';
const LTV_PASSWORD = 'senha-pki-ltv-teste-W4!';

if (! function_exists('ltvSetup')) {
    /**
     * Gera a PKI de TESTE e liga `operator_tsa` + `pades_ltv` com a TSA dessa PKI.
     *
     * @return array<string, string> caminhos devolvidos por `ltv-gen-test-pki`
     */
    function ltvSetup(string $work, bool $enable = true, bool $withCrl = true): array
    {
        putenv(LTV_PASS_ENV.'='.LTV_PASSWORD);

        $result = app(TsaToolRunner::class)->run('ltv-gen-test-pki', [
            '--out-dir', $work.DIRECTORY_SEPARATOR.'pki',
            '--pass-env', LTV_PASS_ENV,
            '--days', '30',
            '--tsa-days', '20',
        ], [LTV_PASS_ENV]);

        /** @var array<string, string> $files */
        $files = $result['files'];

        config()->set('assinavelox.tsa.pfx_path', $files['tsa_pfx']);
        config()->set('assinavelox.tsa.password_env', LTV_PASS_ENV);
        config()->set('assinavelox.tsa.chain_pem', $files['tsa_chain_pem']);
        config()->set('assinavelox.tsa.trust_roots', [$files['root_pem']]);
        config()->set('assinavelox.tsa.environment', 'test');
        config()->set('assinavelox.features.operator_tsa', $enable);
        config()->set('assinavelox.features.pades_ltv', $enable);
        config()->set('assinavelox.features.pades_ltv_advertise', false);
        config()->set('assinavelox.ltv.level', 'B-LTA');
        config()->set('assinavelox.ltv.trust_roots', [$files['root_pem']]);
        config()->set('assinavelox.ltv.crl_paths', $withCrl ? [$files['crl']] : []);
        config()->set('assinavelox.ltv.ocsp_paths', []);
        config()->set('assinavelox.ltv.refresh.lock_wait_seconds', 5);

        return $files;
    }
}

if (! function_exists('ltvCleanup')) {
    function ltvCleanup(?string $work): void
    {
        putenv(LTV_PASS_ENV);
        ktsaCleanup($work);
    }
}

if (! function_exists('ltvCompletedEnvelopeWithLtaFinal')) {
    /**
     * Envelope concluído cujo arquivo final é B-LTA.
     *
     * A finalização ainda não chama o LtvSigner (integração pendente, fora da área P3-LTV). Este
     * helper SIMULA essa integração: conclui o envelope sem certificado, assina o final com o
     * LtvSigner (certificado da PKI de teste como "operadora"), guarda o resultado como nova versão
     * final e aponta documento, envelope e registro para ela — com `signature_profile` PAdES-B-B,
     * como a finalização grava hoje.
     *
     * @param  array<string, string>  $files
     * @return array{envelope: Envelope, record: VerificationRecord, document: Document, version: DocumentVersion, signed: array<string, mixed>}
     */
    function ltvCompletedEnvelopeWithLtaFinal(string $work, array $files): array
    {
        finalizationDisk($work.DIRECTORY_SEPARATOR.'disco-documents');
        config()->set('pdftool.company_certificate.enabled', false);
        config()->set('pdftool.company_certificate.pfx_path', null);

        $scenario = finalizationEnvelope($work);
        finalizationRun($scenario['envelope']);
        $envelope = $scenario['envelope']->refresh();

        $plain = finalizationDownload($envelope->finalVersion, $work.DIRECTORY_SEPARATOR.'final-sem-assinatura.pdf');
        $signedPath = $work.DIRECTORY_SEPARATOR.'final-lta.pdf';
        $signed = app(LtvSigner::class)->sign(
            $plain,
            $signedPath,
            $files['signer_pfx'],
            LTV_PASS_ENV,
            'B-LTA',
            ['reason' => 'Assinatura eletrônica AssinaVelox (teste)'],
            (int) $envelope->organization_id,
            (int) $envelope->getKey(),
        );

        $version = app(FinalizationArtifacts::class)->store($envelope, $scenario['document'], DocumentVersionKind::Final, $signedPath);

        $report = $signed['report'];
        $reference = TestCertificate::register([
            'password_env' => LTV_PASS_ENV,
            'raw' => [
                'subject' => $report['signer_subject'] ?? null,
                'issuer' => $report['issuer'] ?? null,
                'cert_fingerprint_sha256' => $report['cert_fingerprint_sha256'] ?? null,
                'not_after' => $report['not_after'] ?? null,
            ],
        ]);

        Document::query()->withoutGlobalScopes()->whereKey($scenario['document']->getKey())->update(['final_version_id' => $version->getKey()]);
        Envelope::withoutOrganizationScope()->whereKey($envelope->getKey())->update(['final_document_version_id' => $version->getKey()]);

        $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->firstOrFail();
        // A finalização da Fase 2 também grava uma linha por documento (verification_record_documents).
        VerificationRecordDocument::query()
            ->where('verification_record_id', $record->getKey())
            ->where('document_id', $scenario['document']->getKey())
            ->update(['final_document_version_id' => $version->getKey(), 'final_sha256' => $version->sha256]);
        $record->forceFill([
            'final_document_version_id' => $version->getKey(),
            'final_sha256' => $version->sha256,
            'signature_status' => SignatureStatus::CompanyA1,
            'signature_profile' => 'PAdES-B-B',
            'certificate_reference_id' => $reference->getKey(),
        ])->save();

        app(LtvState::class)->apply($record, $report);

        return [
            'envelope' => $envelope->refresh(),
            'record' => $record->refresh(),
            'document' => $scenario['document'],
            'version' => $version,
            'signed' => $report,
        ];
    }
}

if (! function_exists('ltvRunRefresh')) {
    /**
     * Roda o job de re-carimbo como o worker rodaria (mesma classe, mesmo `handle`).
     *
     * @param  list<int>|null  $sources
     */
    function ltvRunRefresh(VerificationRecord $record, ?array $sources): void
    {
        $job = new RefreshArchiveTimestamp((int) $record->getKey(), (int) $record->envelope_id, $sources);
        app()->call([$job, 'handle']);
    }
}

if (! function_exists('ltvValidate')) {
    /**
     * `pdftool ltv-validate` com a raiz da PKI de teste.
     *
     * @param  array<string, string>  $files
     * @return array<string, mixed>
     */
    function ltvValidate(string $pdf, array $files, bool $withCrl = true): array
    {
        $args = ['--in', $pdf, '--trust', $files['root_pem']];

        if ($withCrl) {
            array_push($args, '--crl', $files['crl']);
        }

        return app(TsaToolRunner::class)->run('ltv-validate', $args);
    }
}
