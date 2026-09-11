<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes do K-TSA (TSA da operadora e dossiê)
|--------------------------------------------------------------------------
| Incluído com require_once. Não contém testes. Tudo roda contra o pdftool real, com uma
| TSA de TESTE gerada por `tsa-gen-test` (EC P-256, rápida). A senha do PKCS#12 da TSA existe
| só numa variável de ambiente do processo de teste, sob um nome.
*/

use App\Models\Organization;
use App\Services\Dossier\Models\DossierExport;
use App\Services\Timestamp\TsaToolRunner;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Feature\Pdf\Support\PdfFixtures;

const KTSA_TSA_PASS_ENV = 'KTSA_TEST_TSA_PASSWORD';
const KTSA_TSA_PASSWORD = 'senha-da-tsa-de-teste-Q9x!';

if (! function_exists('ktsaWorkspace')) {
    /**
     * Área de trabalho + pdftool com tmp exclusivo. Pula o teste sem o venv do pdftool.
     */
    function ktsaWorkspace(object $test): string
    {
        if (! PdfFixtures::available()) {
            $test->markTestSkipped(PdfFixtures::skipMessage());
        }

        $work = PdfFixtures::workspace();
        config()->set('pdftool.tmp_path', $work.DIRECTORY_SEPARATOR.'pdftool-tmp');
        config()->set('app.url', 'https://assinavelox.test');

        return $work;
    }
}

if (! function_exists('ktsaConfigureTsa')) {
    /**
     * Gera uma TSA de TESTE e liga `operator_tsa` com a configuração completa.
     *
     * @return array{dir: string, pfx: string, root: string, chain: string}
     */
    function ktsaConfigureTsa(string $work, bool $enableFlag = true): array
    {
        putenv(KTSA_TSA_PASS_ENV.'='.KTSA_TSA_PASSWORD);

        $dir = $work.DIRECTORY_SEPARATOR.'tsa';
        @mkdir($dir, 0700, true);

        $paths = [
            'dir' => $dir,
            'pfx' => $dir.DIRECTORY_SEPARATOR.'tsa.pfx',
            'root' => $dir.DIRECTORY_SEPARATOR.'root.pem',
            'chain' => $dir.DIRECTORY_SEPARATOR.'chain.pem',
        ];

        app(TsaToolRunner::class)->run('tsa-gen-test', [
            '--out-pfx', $paths['pfx'],
            '--pass-env', KTSA_TSA_PASS_ENV,
            '--out-root-pem', $paths['root'],
            '--out-chain-pem', $paths['chain'],
            '--days', '5',
            '--key', 'ec-p256',
        ], [KTSA_TSA_PASS_ENV]);

        config()->set('assinavelox.tsa.pfx_path', $paths['pfx']);
        config()->set('assinavelox.tsa.password_env', KTSA_TSA_PASS_ENV);
        config()->set('assinavelox.tsa.chain_pem', $paths['chain']);
        config()->set('assinavelox.tsa.trust_roots', [$paths['root']]);
        config()->set('assinavelox.tsa.environment', 'test');
        config()->set('assinavelox.features.operator_tsa', $enableFlag);

        return $paths;
    }
}

if (! function_exists('ktsaCleanup')) {
    function ktsaCleanup(?string $work): void
    {
        putenv(KTSA_TSA_PASS_ENV);
        PdfFixtures::cleanup($work);
    }
}

if (! function_exists('ktsaBuildRequest')) {
    /**
     * `TimeStampReq` DER montado pelo asn1crypto do venv (um cliente RFC 3161 comum).
     */
    function ktsaBuildRequest(string $digestHex, string $algorithm = 'sha256', ?int $nonce = 4242, ?string $policy = null): string
    {
        $script = 'import sys; from pdftool.tsa import build_request; '
            .'nonce = None if sys.argv[3] == "-" else int(sys.argv[3]); policy = None if sys.argv[4] == "-" else sys.argv[4]; '
            .'sys.stdout.buffer.write(build_request(bytes.fromhex(sys.argv[1]), sys.argv[2], nonce=nonce, cert_req=True, policy_oid=policy).dump())';

        $process = new Process(
            [PdfFixtures::pythonBinary(), '-c', $script, $digestHex, $algorithm, $nonce === null ? '-' : (string) $nonce, $policy ?? '-'],
            base_path('tools/pdftool'),
            ['PYTHONIOENCODING' => 'utf-8'],
        );
        $process->mustRun();

        return $process->getOutput();
    }
}

if (! function_exists('ktsaEnableDossier')) {
    /**
     * Liga `dossier_export`: chave global E plano vigente da organização.
     */
    function ktsaEnableDossier(Organization $organization): void
    {
        config()->set('assinavelox.features.dossier_export', true);

        $plan = $organization->currentSubscription()->with('plan')->first()?->plan;

        if ($plan === null) {
            return;
        }

        $features = (array) ($plan->features ?? []);
        $features['dossier_export'] = true;
        $plan->forceFill(['features' => $features])->save();
    }
}

if (! function_exists('ktsaZipEntries')) {
    /**
     * Conteúdo de cada entrada de um ZIP (bytes descompactados), por nome.
     *
     * @return array<string, string>
     */
    function ktsaZipEntries(string $zipBytes, string $work): array
    {
        $path = $work.DIRECTORY_SEPARATOR.'z-'.bin2hex(random_bytes(6)).'.zip';
        file_put_contents($path, $zipBytes);

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('ZIP inválido.');
        }

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $entries[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($path);

        return $entries;
    }
}

if (! function_exists('ktsaExportBytes')) {
    function ktsaExportBytes(DossierExport $export): string
    {
        return (string) Storage::disk($export->storage_disk ?? 'documents')->get((string) $export->storage_path);
    }
}
