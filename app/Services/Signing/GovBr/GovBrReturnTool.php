<?php

namespace App\Services\Signing\GovBr;

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
 * `pdftool verify-incremental` (P3-GOV): o arquivo devolvido estende a revisão reservada com
 * exatamente uma assinatura nova, íntegra, cobrindo o arquivo inteiro e sem outras mudanças?
 *
 * Mesma disciplina do {@see PdfToolClient}: argv em array, sem shell, ambiente mínimo, JSON
 * único em stdout, exit codes 2/3/4 em exceções tipadas. O CPF que o participante informou
 * (dado pessoal) NUNCA vai para argv — o `PdfToolClient` registra o argv em log —, e sim
 * para o ambiente do processo filho sob {@see self::EXPECTED_CPF_ENV}; o pdftool devolve só
 * `match`/`mismatch`/`unknown` e o CPF do certificado MASCARADO. O stdout não é registrado.
 */
final class GovBrReturnTool
{
    public const EXPECTED_CPF_ENV = 'AV_GOVBR_EXPECTED_CPF';

    public function __construct(
        private readonly PdfToolClient $client,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function isAvailable(): bool
    {
        return $this->client->isAvailable();
    }

    public function temporaryRoot(): string
    {
        return $this->client->temporaryRoot();
    }

    /**
     * @param  list<string>  $trustRoots  âncoras JÁ conferidas por impressão digital
     * @param  list<string>  $permittedLevels
     * @return array<string, mixed>
     */
    public function verify(
        string $expectedRevision,
        string $returned,
        array $trustRoots = [],
        array $permittedLevels = ['NONE', 'FORM_FILLING'],
        #[\SensitiveParameter] ?string $expectedCpf = null,
        ?string $correlationId = null,
    ): array {
        $args = ['--base', $this->absolute($expectedRevision), '--in', $this->absolute($returned)];

        foreach ($trustRoots as $root) {
            $args[] = '--trust';
            $args[] = $this->absolute($root);
        }

        foreach ($permittedLevels as $level) {
            $args[] = '--permitted-level';
            $args[] = strtoupper($level);
        }

        $env = [];

        if ($expectedCpf !== null && $expectedCpf !== '') {
            $args[] = '--expect-cpf-env';
            $args[] = self::EXPECTED_CPF_ENV;
            $env[self::EXPECTED_CPF_ENV] = $expectedCpf;
        }

        return $this->run('verify-incremental', $args, $env, $correlationId);
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string>  $secretEnv
     * @return array<string, mixed>
     */
    private function run(string $command, array $args, #[\SensitiveParameter] array $secretEnv, ?string $correlationId): array
    {
        $correlationId ??= (string) Str::ulid();
        $argv = [$this->client->pythonBinary(), '-m', 'pdftool', $command, ...$args];
        $timeout = max(30, (int) $this->config->get('assinavelox.govbr.verify_timeout_seconds', 180));
        $workDir = TemporaryDirectory::create($this->client->temporaryRoot(), 'govbr-proc-');

        try {
            $process = new Process(
                $argv,
                $this->client->workingDirectory(),
                ProcessEnvironment::minimal($workDir->path(), [
                    'PYTHONUTF8' => '1',
                    'PYTHONIOENCODING' => 'utf-8',
                    'PYTHONNOUSERSITE' => '1',
                    ...$secretEnv,
                ]),
                null,
                $timeout,
            );

            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                throw new PdfToolProcessingException(sprintf('pdftool %s excedeu o tempo limite de %ds.', $command, $timeout), 'timeout', null, $argv, $correlationId);
            } catch (ProcessException) {
                // Sem encadear: a exceção do Process carrega o ambiente do filho (com o CPF).
                throw new PdfToolProcessingException(sprintf('Não foi possível executar o pdftool (%s).', $command), 'process_failed', null, $argv, $correlationId);
            }

            $exitCode = $process->getExitCode();
            $stderr = $this->excerpt($process->getErrorOutput(), $secretEnv);
            $stdout = $process->getOutput();
            unset($process);

            $this->logger->log($exitCode === PdfToolClient::EXIT_OK ? 'debug' : 'warning', 'pdftool (devolução gov.br): comando executado', [
                'command' => $command,
                'argv' => $argv,
                'exit_code' => $exitCode,
                'correlation_id' => $correlationId,
                'stderr' => $stderr !== '' ? $stderr : null,
            ]);

            $payload = $this->decode($stdout);

            if ($payload === null) {
                throw new PdfToolProcessingException(sprintf('pdftool %s não devolveu JSON válido (exit code %s).', $command, $exitCode ?? 'desconhecido'), 'invalid_output', $exitCode, $argv, $correlationId, $stderr);
            }

            if ($exitCode === PdfToolClient::EXIT_OK && ($payload['ok'] ?? false) === true) {
                return $payload;
            }

            $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
            $errorCode = isset($error['code']) ? (string) $error['code'] : 'unknown_error';
            $text = sprintf('pdftool %s falhou [%s]: %s', $command, $errorCode, $this->redact(isset($error['message']) ? (string) $error['message'] : 'falha sem mensagem', $secretEnv));

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
        $last = trim((string) end($lines));
        $decoded = $last === '' ? null : json_decode($last, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, string>  $secrets
     */
    private function excerpt(string $stderr, #[\SensitiveParameter] array $secrets): string
    {
        $limit = max(200, (int) $this->config->get('pdftool.stderr_log_limit', 4000));
        $text = trim($this->redact($stderr, $secrets));
        $text = (string) preg_replace('/[^\P{C}\n\t]/u', '', $text);

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit).' […truncado]' : $text;
    }

    /**
     * @param  array<string, string>  $secrets
     */
    private function redact(string $text, #[\SensitiveParameter] array $secrets): string
    {
        foreach ($secrets as $value) {
            if ($value !== '') {
                $text = str_replace($value, '[REDACTED]', $text);
            }
        }

        return $text;
    }
}
