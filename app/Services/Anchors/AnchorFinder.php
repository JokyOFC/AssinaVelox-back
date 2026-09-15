<?php

namespace App\Services\Anchors;

use App\Services\Pdf\PdfToolClient;
use App\Services\Pdf\Support\ProcessEnvironment;
use Illuminate\Contracts\Config\Repository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Executa `pdftool find-anchors` (Fase 3 §3.2) como processo isolado, nas mesmas regras do
 * {@see PdfToolClient}: argumentos em array (sem shell), interpretador do venv, ambiente
 * MÍNIMO (nenhuma variável do PHP herdada, nenhum segredo), diretório temporário exclusivo
 * removido em finally, timeout e exatamente um JSON em stdout.
 *
 * O `PdfToolClient::run()` é privado e fica fora desta área; por isso o laço de processo é
 * repetido aqui, reaproveitando as partes públicas (interpretador, cwd, temporário).
 *
 * Erros viram {@see AnchorDetectionException} com o `error.code` do pdftool; a mensagem ao
 * usuário é a da exceção (PT-BR, sem caminho interno). stderr vai só para o log, truncado.
 */
class AnchorFinder
{
    public function __construct(
        private readonly PdfToolClient $pdftool,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function isAvailable(): bool
    {
        return $this->pdftool->isAvailable();
    }

    /**
     * `$interactiveBudgetSeconds`: busca feita DENTRO de uma requisição HTTP ("Testar regras" do
     * modelo) — orçamento curto e processo com timeout curto, sem o piso da fila.
     *
     * @throws AnchorDetectionException
     */
    public function find(string $pdfPath, AnchorQuery $query, ?OcrRequest $ocr = null, ?int $interactiveBudgetSeconds = null): AnchorDetection
    {
        if ($query->isEmpty()) {
            throw AnchorDetectionException::make('nothing_to_search');
        }

        if (! $this->isAvailable()) {
            throw AnchorDetectionException::make('pdftool_unavailable');
        }

        $workDir = $this->pdftool->temporaryDirectory('anchors-');

        try {
            $specPath = $workDir->path('spec.json');
            file_put_contents($specPath, json_encode($query->toSpec(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            $textBudget = $interactiveBudgetSeconds !== null
                ? max(1, min(60, $interactiveBudgetSeconds))
                : max(1, min(600, (int) $this->config->get('assinavelox.field_anchors.time_budget_seconds', 60)));
            $budget = $ocr?->timeBudgetSeconds($textBudget) ?? $textBudget;

            $argv = [
                $this->pdftool->pythonBinary(), '-m', 'pdftool', 'find-anchors',
                '--in', $pdfPath,
                '--spec', $specPath,
                '--max-pages', (string) max(1, min(1000, (int) $this->config->get('assinavelox.field_anchors.max_pages', 200))),
                '--max-bytes', (string) (max(1, (int) $this->config->get('assinavelox.field_anchors.max_file_mb', 50)) * 1024 * 1024),
                '--time-budget', (string) $budget,
            ];

            $extraEnv = [];

            if ($ocr !== null) {
                array_push(
                    $argv,
                    '--ocr',
                    '--tesseract', $ocr->tesseractPath,
                    '--ocr-lang', $ocr->language,
                    '--ocr-dpi', (string) $ocr->dpi,
                    '--ocr-timeout', (string) $ocr->pageTimeoutSeconds,
                    '--ocr-max-pages', (string) $ocr->maxPages,
                );

                foreach ($ocr->pages as $page) {
                    array_push($argv, '--ocr-page', (string) $page);
                }

                if ($ocr->tessdataPrefix !== null && $ocr->tessdataPrefix !== '') {
                    $extraEnv['TESSDATA_PREFIX'] = $ocr->tessdataPrefix;
                }
            }

            // O pdftool para no orçamento (conferido também dentro da página e antes de cada OCR,
            // com o tesseract limitado ao que resta dele). Com OCR, a última página ainda pode
            // levar até `page_timeout`: o processo nunca é morto antes disso (o tesseract filho
            // não fica órfão). Busca interativa: sem o piso de 90 s da fila.
            $ceiling = $budget + 30 + ($ocr !== null ? $ocr->pageTimeoutSeconds : 0);
            $timeout = $interactiveBudgetSeconds !== null
                ? $ceiling
                : max((int) $this->config->get('assinavelox.field_anchors.process_timeout_seconds', 90), $ceiling);

            $process = new Process(
                $argv,
                $this->pdftool->workingDirectory(),
                ProcessEnvironment::minimal($workDir->path(), [
                    'PYTHONUTF8' => '1',
                    'PYTHONIOENCODING' => 'utf-8',
                    'PYTHONNOUSERSITE' => '1',
                    ...$extraEnv,
                ]),
                null,
                $timeout,
            );

            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                $this->logger->warning('anchors: find-anchors excedeu o tempo limite', ['timeout_seconds' => $timeout, 'ocr' => $ocr !== null]);

                throw AnchorDetectionException::make('timeout');
            } catch (ProcessException $exception) {
                $this->logger->error('anchors: não foi possível executar o pdftool', ['error' => $exception->getMessage()]);

                throw AnchorDetectionException::make('process_failed');
            }

            $payload = $this->decode($process->getOutput());
            $exitCode = $process->getExitCode();

            if ($payload !== null && $exitCode === 0 && ($payload['ok'] ?? false) === true) {
                return AnchorDetection::fromArray($payload);
            }

            $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
            $code = is_string($error['code'] ?? null) ? (string) $error['code'] : ($payload === null ? 'invalid_output' : 'unknown_error');

            $this->logger->warning('anchors: find-anchors falhou', [
                'exit_code' => $exitCode,
                'code' => $code,
                'ocr' => $ocr !== null,
                'stderr' => mb_substr(trim($process->getErrorOutput()), 0, 2000),
            ]);

            throw AnchorDetectionException::make($code);
        } finally {
            $workDir->delete();
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $stdout): ?array
    {
        $stdout = trim($stdout);

        if ($stdout === '') {
            return null;
        }

        $decoded = json_decode($stdout, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $lines = preg_split('/\r?\n/', $stdout) ?: [];
        $decoded = json_decode(trim((string) end($lines)), true);

        return is_array($decoded) ? $decoded : null;
    }
}
