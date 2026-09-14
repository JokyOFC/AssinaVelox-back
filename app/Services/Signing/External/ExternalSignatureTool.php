<?php

namespace App\Services\Signing\External;

use App\Services\Pdf\Exceptions\PdfToolInputRejectedException;
use App\Services\Pdf\Exceptions\PdfToolProcessingException;
use App\Services\Pdf\Exceptions\PdfToolUsageException;
use App\Services\Pdf\PdfToolClient;
use App\Services\Pdf\Support\ProcessEnvironment;
use App\Services\Pdf\Support\TemporaryDirectory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * `pdftool prepare-external` / `embed-external` (Fase 3 §3.4).
 *
 * Mesma disciplina do {@see PdfToolClient}: argv em array, sem shell, ambiente mínimo, JSON
 * único em stdout, exit codes 2/3/4 em exceções tipadas. Aqui não há segredo nenhum a
 * proteger — nenhuma chave passa pelo servidor —, mas o argv só carrega CAMINHOS e o nome do
 * campo: digest, assinatura e CMS vão por arquivo, nunca por argumento (e, portanto, nunca
 * pelo log do comando).
 */
final class ExternalSignatureTool
{
    public function __construct(
        private readonly PdfToolClient $client,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function isAvailable(): bool
    {
        return $this->client->isAvailable();
    }

    /**
     * @param  list<string>  $chain
     * @param  list<string>  $trust
     * @return array<string, mixed>
     */
    public function prepare(
        string $input,
        string $pendingOut,
        string $stateOut,
        string $certificate,
        array $chain,
        string $fieldName,
        array $trust,
        ?string $reason,
        int $bytesReserved,
        ?string $correlationId = null,
    ): array {
        $args = [
            '--in', $this->absolute($input),
            '--out', $this->absolute($pendingOut),
            '--state-out', $this->absolute($stateOut),
            '--cert', $this->absolute($certificate),
            '--field-name', $fieldName,
            '--bytes-reserved', (string) $bytesReserved,
        ];

        foreach ($chain as $path) {
            $args[] = '--chain';
            $args[] = $this->absolute($path);
        }

        foreach ($trust as $path) {
            $args[] = '--trust';
            $args[] = $this->absolute($path);
        }

        if ($reason !== null && $reason !== '') {
            $args[] = '--reason';
            $args[] = $reason;
        }

        return $this->run('prepare-external', $args, $correlationId);
    }

    /**
     * @param  list<string>  $chain
     * @param  list<string>  $trust
     * @return array<string, mixed>
     */
    public function embed(
        string $pending,
        string $state,
        string $output,
        ?string $signature,
        ?string $certificate,
        array $chain,
        ?string $cms,
        array $trust,
        string $expectFingerprint,
        ?string $correlationId = null,
    ): array {
        $args = [
            '--pending', $this->absolute($pending),
            '--state', $this->absolute($state),
            '--out', $this->absolute($output),
            '--expect-fingerprint', $expectFingerprint,
        ];

        if ($signature !== null) {
            $args[] = '--signature';
            $args[] = $this->absolute($signature);
        }

        if ($certificate !== null) {
            $args[] = '--cert';
            $args[] = $this->absolute($certificate);
        }

        foreach ($chain as $path) {
            $args[] = '--chain';
            $args[] = $this->absolute($path);
        }

        if ($cms !== null) {
            $args[] = '--cms';
            $args[] = $this->absolute($cms);
        }

        foreach ($trust as $path) {
            $args[] = '--trust';
            $args[] = $this->absolute($path);
        }

        return $this->run('embed-external', $args, $correlationId);
    }

    /**
     * @param  list<string>  $args
     * @return array<string, mixed>
     */
    private function run(string $command, array $args, ?string $correlationId): array
    {
        $correlationId ??= (string) Str::ulid();
        $argv = [$this->client->pythonBinary(), '-m', 'pdftool', $command, ...$args];
        $timeout = $this->client->signTimeout();
        $workDir = TemporaryDirectory::create($this->client->temporaryRoot(), 'ext-proc-');

        try {
            $process = new Process(
                $argv,
                $this->client->workingDirectory(),
                ProcessEnvironment::minimal($workDir->path(), [
                    'PYTHONUTF8' => '1',
                    'PYTHONIOENCODING' => 'utf-8',
                    'PYTHONNOUSERSITE' => '1',
                ]),
                null,
                $timeout,
            );

            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                throw new PdfToolProcessingException(sprintf('pdftool %s excedeu o tempo limite de %ds.', $command, $timeout), 'timeout', null, $argv, $correlationId);
            } catch (ProcessException) {
                throw new PdfToolProcessingException(sprintf('Não foi possível executar o pdftool (%s).', $command), 'process_failed', null, $argv, $correlationId);
            }

            $exitCode = $process->getExitCode();
            $stderr = $this->excerpt($process->getErrorOutput());
            $stdout = $process->getOutput();

            $this->logger->log($exitCode === PdfToolClient::EXIT_OK ? 'debug' : 'warning', 'pdftool (assinatura externa): comando executado', [
                'command' => $command,
                'argv' => $argv,
                'exit_code' => $exitCode,
                'correlation_id' => $correlationId,
                'stderr' => $stderr !== '' ? $stderr : null,
            ]);

            $payload = $this->decode($stdout);

            if ($payload === null) {
                throw new PdfToolProcessingException(
                    sprintf('pdftool %s não devolveu JSON válido em stdout (exit code %s).', $command, $exitCode ?? 'desconhecido'),
                    'invalid_output',
                    $exitCode,
                    $argv,
                    $correlationId,
                    $stderr,
                );
            }

            if ($exitCode === PdfToolClient::EXIT_OK && ($payload['ok'] ?? false) === true) {
                return $payload;
            }

            $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
            $errorCode = isset($error['code']) ? (string) $error['code'] : 'unknown_error';
            $text = sprintf('pdftool %s falhou [%s]: %s', $command, $errorCode, isset($error['message']) ? (string) $error['message'] : 'falha sem mensagem');

            throw match ($exitCode) {
                PdfToolClient::EXIT_USAGE => new PdfToolUsageException($text, $errorCode, $exitCode, $argv, $correlationId, $stderr),
                PdfToolClient::EXIT_INPUT_REJECTED => new PdfToolInputRejectedException($text, $errorCode, $exitCode, $argv, $correlationId, $stderr),
                default => new PdfToolProcessingException($text, $errorCode, $exitCode, $argv, $correlationId, $stderr),
            };
        } finally {
            $workDir->delete();
        }
    }

    private function absolute(string $path): string
    {
        if (trim($path) === '' || str_contains($path, "\0") || ! PdfToolClient::isAbsolutePath($path)) {
            throw new InvalidArgumentException('Caminho inválido para o pdftool: use um caminho absoluto.');
        }

        return $path;
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

    private function excerpt(string $stderr): string
    {
        $limit = max(200, (int) $this->config->get('pdftool.stderr_log_limit', 4000));
        $text = trim((string) preg_replace('/[^\P{C}\n\t]/u', '', $stderr));

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit).' […truncado]' : $text;
    }
}
