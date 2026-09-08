<?php

namespace App\Console\Commands;

use App\Integrations\Pdf\LibreOfficeConverter;
use App\Integrations\Pdf\PdfConverterManager;
use App\Integrations\Pdf\PyHankoSigner;
use App\Services\Pdf\Exceptions\PdfToolException;
use App\Services\Pdf\PdfToolClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Diagnóstico operacional do pipeline de PDF:
 *
 *   php artisan pdftool:selftest [--json]
 *
 * Mostra interpretador/versões (python, pdftool, pins de pypdf/pyHanko/reportlab/
 * Pillow lidos de requirements.lock.txt), roda o selftest ponta a ponta do
 * pdftool (compose, append, inspect, image2pdf, gen-test-cert, sign, validate) e
 * informa o estado dos adaptadores (conversores por tipo, LibreOffice,
 * certificado A1 + ambiente, raízes de confiança). Exit code 1 se o pdftool não
 * estiver disponível ou o selftest falhar; LibreOffice/certificado ausentes são
 * avisos (são opcionais). Nunca imprime valores de variáveis de ambiente.
 */
class PdftoolSelftestCommand extends Command
{
    protected $signature = 'pdftool:selftest {--json : Saída em JSON (para monitoramento)}';

    protected $description = 'Verifica o pdftool (Python), roda o selftest e mostra o estado dos adaptadores de PDF.';

    public function handle(PdfToolClient $client, PdfConverterManager $converters, LibreOfficeConverter $libreOffice, PyHankoSigner $signer): int
    {
        $report = [
            'ok' => true,
            'pdftool' => $this->pdftoolReport($client),
            'selftest' => null,
            'adapters' => $this->adaptersReport($converters, $libreOffice, $signer),
        ];

        if ($report['pdftool']['available']) {
            $report['selftest'] = $this->selftestReport($client);
            $report['ok'] = (bool) ($report['selftest']['ok'] ?? false);
        } else {
            $report['ok'] = false;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $report['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $this->render($report);

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function pdftoolReport(PdfToolClient $client): array
    {
        $report = [
            'python' => $client->pythonBinary(),
            'cwd' => $client->workingDirectory(),
            'tmp_path' => $client->temporaryRoot(),
            'timeout_seconds' => $client->timeout(),
            'sign_timeout_seconds' => $client->signTimeout(),
            'available' => $client->isAvailable(),
            'python_version' => null,
            'pdftool_version' => null,
            'pinned' => $this->pinnedVersions($client->workingDirectory()),
            'error' => null,
        ];

        if (! $report['available']) {
            $report['error'] = 'Interpretador ou pacote pdftool não encontrado. Instale o venv: python3 -m venv tools/pdftool/.venv && .venv/bin/python -m pip install -r tools/pdftool/requirements.lock.txt';

            return $report;
        }

        try {
            $report['python_version'] = $client->pythonVersion();
            $report['pdftool_version'] = $client->version();
        } catch (PdfToolException $exception) {
            $report['available'] = false;
            $report['error'] = $exception->getMessage();
        }

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function selftestReport(PdfToolClient $client): array
    {
        try {
            $result = $client->selftest();

            return [
                'ok' => (bool) ($result['ok'] ?? false),
                'steps' => array_map(static fn (mixed $step): array => [
                    'step' => (string) ($step['step'] ?? '?'),
                    'ok' => (bool) ($step['ok'] ?? false),
                    'ms' => isset($step['ms']) ? (int) $step['ms'] : null,
                ], array_values(array_filter((array) ($result['steps'] ?? []), 'is_array'))),
                'error' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'steps' => [],
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function adaptersReport(PdfConverterManager $converters, LibreOfficeConverter $libreOffice, PyHankoSigner $signer): array
    {
        $trustRoots = [];
        foreach ($signer->trustRoots() as $root) {
            $trustRoots[] = ['path' => $root, 'exists' => is_file($root)];
        }

        return [
            'converters' => $converters->status(),
            'libreoffice' => [
                'binary' => $libreOffice->binary() !== '' ? $libreOffice->binary() : null,
                'resolved' => $libreOffice->resolvedBinary(),
                'configured' => $libreOffice->isConfigured(),
                'timeout_seconds' => $libreOffice->timeout(),
            ],
            'certificate' => [
                'configured' => $signer->isConfigured(),
                'name' => $signer->certificateName(),
                'environment' => $signer->environment()->value,
                'pfx_path' => $signer->pfxPath() !== '' ? $signer->pfxPath() : null,
                'pfx_exists' => $signer->pfxPath() !== '' && is_file($signer->pfxPath()),
                'passphrase_env' => $signer->passphraseEnvName(),
                'problems' => $signer->configurationProblems(),
                'profile' => PyHankoSigner::PROFILE,
            ],
            'trust_roots' => $trustRoots,
        ];
    }

    /**
     * Versões fixadas em requirements.lock.txt (o pdftool não expõe as versões
     * das bibliotecas em tempo de execução).
     *
     * @return array<string, string|null>
     */
    private function pinnedVersions(string $cwd): array
    {
        $wanted = ['pypdf', 'pyHanko', 'pyhanko-certvalidator', 'reportlab', 'pillow', 'cryptography'];
        $versions = array_fill_keys($wanted, null);

        $lock = $cwd.DIRECTORY_SEPARATOR.'requirements.lock.txt';
        if (! is_file($lock)) {
            return $versions;
        }

        foreach (file($lock, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^([A-Za-z0-9_.\-]+)==(\S+)/', trim($line), $matches) !== 1) {
                continue;
            }
            foreach ($wanted as $name) {
                if (strcasecmp($matches[1], $name) === 0) {
                    $versions[$name] = $matches[2];
                }
            }
        }

        return $versions;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function render(array $report): void
    {
        $pdftool = $report['pdftool'];

        $this->components->info('pdftool');
        $this->components->twoColumnDetail('Python', (string) $pdftool['python']);
        $this->components->twoColumnDetail('Disponível', $pdftool['available'] ? '<fg=green>sim</>' : '<fg=red>não</>');
        $this->components->twoColumnDetail('Versão do Python', (string) ($pdftool['python_version'] ?? '—'));
        $this->components->twoColumnDetail('Versão do pdftool', (string) ($pdftool['pdftool_version'] ?? '—'));
        foreach ($pdftool['pinned'] as $name => $version) {
            $this->components->twoColumnDetail("Pin {$name}", (string) ($version ?? '—'));
        }
        $this->components->twoColumnDetail('Diretório de trabalho', (string) $pdftool['cwd']);
        $this->components->twoColumnDetail('Diretório temporário', (string) $pdftool['tmp_path']);
        $this->components->twoColumnDetail('Timeouts (comando / assinatura)', sprintf('%ds / %ds', $pdftool['timeout_seconds'], $pdftool['sign_timeout_seconds']));
        if ($pdftool['error']) {
            $this->components->error((string) $pdftool['error']);
        }

        $this->newLine();
        $this->components->info('Selftest');
        $selftest = $report['selftest'];
        if ($selftest === null) {
            $this->components->warn('Não executado (pdftool indisponível).');
        } else {
            foreach ($selftest['steps'] as $step) {
                $this->components->twoColumnDetail(
                    $step['step'],
                    ($step['ok'] ? '<fg=green>ok</>' : '<fg=red>FALHA</>').($step['ms'] !== null ? sprintf(' (%d ms)', $step['ms']) : ''),
                );
            }
            if ($selftest['error']) {
                $this->components->error((string) $selftest['error']);
            }
        }

        $this->newLine();
        $this->components->info('Adaptadores');
        foreach ($report['adapters']['converters'] as $type => $status) {
            $this->components->twoColumnDetail(
                sprintf('Conversor %s', $type),
                sprintf('%s — %s', class_basename($status['converter']), $status['configured'] ? '<fg=green>configurado</>' : '<fg=yellow>não configurado</>'),
            );
        }

        $lo = $report['adapters']['libreoffice'];
        $this->components->twoColumnDetail('LibreOffice', $lo['configured']
            ? sprintf('<fg=green>configurado</> (%s, timeout %ds)', $lo['resolved'], $lo['timeout_seconds'])
            : ($lo['binary'] ? sprintf('<fg=red>binário não encontrado</> (%s)', $lo['binary']) : '<fg=yellow>não configurado</> (LIBREOFFICE_BIN vazio; DOCX indisponível)'));

        $cert = $report['adapters']['certificate'];
        $this->components->twoColumnDetail('Certificado A1', $cert['configured']
            ? sprintf('<fg=green>configurado</> — %s, ambiente <options=bold>%s</>, perfil %s', $cert['name'], $cert['environment'], $cert['profile'])
            : '<fg=yellow>não configurado</> (envelopes concluem como aceite eletrônico com evidências)');
        $this->components->twoColumnDetail('  PFX', $cert['pfx_path'] ? ($cert['pfx_exists'] ? $cert['pfx_path'] : $cert['pfx_path'].' <fg=red>(não encontrado)</>') : '—');
        $this->components->twoColumnDetail('  Variável da passphrase', sprintf('%s (valor nunca exibido)', $cert['passphrase_env']));
        foreach ($cert['problems'] as $problem) {
            $this->components->warn($problem);
        }
        if ($cert['configured'] && $cert['environment'] !== 'production') {
            $this->components->warn('Certificado em ambiente de TESTE: as assinaturas serão rotuladas como teste e nunca como ICP-Brasil.');
        }

        $roots = $report['adapters']['trust_roots'];
        $this->components->twoColumnDetail('Raízes de confiança', $roots === [] ? '<fg=yellow>nenhuma</> (validate: trusted=false)' : count($roots).' configurada(s)');
        foreach ($roots as $root) {
            $this->components->twoColumnDetail('  '.$root['path'], $root['exists'] ? '<fg=green>ok</>' : '<fg=red>não encontrada</>');
        }

        $this->newLine();
        if ($report['ok']) {
            $this->components->info('pdftool operacional.');
        } else {
            $this->components->error('pdftool NÃO operacional. Veja docs/pdf-pipeline.md.');
        }
    }
}
