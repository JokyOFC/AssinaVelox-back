<?php

namespace App\Services\Pdf;

use App\Services\Pdf\Dto\ComposePlan;
use App\Services\Pdf\Dto\ComposeResult;
use App\Services\Pdf\Dto\PdfInspection;
use App\Services\Pdf\Dto\SignOptions;
use App\Services\Pdf\Dto\SignResult;
use App\Services\Pdf\Dto\ValidationResult;
use App\Services\Pdf\Exceptions\PdfToolInputRejectedException;
use App\Services\Pdf\Exceptions\PdfToolProcessingException;
use App\Services\Pdf\Exceptions\PdfToolUsageException;
use App\Services\Pdf\Support\ProcessEnvironment;
use App\Services\Pdf\Support\TemporaryDirectory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Env;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Cliente do `tools/pdftool` (Python). O Laravel orquestra; o pdftool é um
 * processo controlado:
 *
 * - argumentos sempre em array (Symfony Process), nunca shell;
 * - cwd = tools/pdftool, interpretador do venv (config pdftool.python);
 * - ambiente mínimo (ver ProcessEnvironment) + apenas a variável da passphrase
 *   ao assinar — o valor é lido do ambiente do PHP e nunca passa por argv;
 * - timeout por comando; stdout = exatamente um JSON; exit codes 2/3/4
 *   mapeados para exceções tipadas com o `error.code` do JSON;
 * - stderr registrado truncado e com qualquer segredo redigido;
 * - diretório temporário exclusivo por chamada, removido em finally;
 * - correlation id (ULID) por chamada, presente em logs e exceções.
 */
class PdfToolClient
{
    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    public const EXIT_PROCESSING = 3;

    public const EXIT_INPUT_REJECTED = 4;

    public const PAGE_SIZES = ['a4', 'letter', 'fit'];

    public const DEFAULT_TEST_SUBJECT = 'CN=AssinaVelox TESTE,O=AssinaVelox,C=BR';

    public function __construct(
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function pythonBinary(): string
    {
        return (string) $this->config->get('pdftool.python', '');
    }

    public function workingDirectory(): string
    {
        return (string) $this->config->get('pdftool.cwd', '');
    }

    public function temporaryRoot(): string
    {
        return (string) $this->config->get('pdftool.tmp_path', '');
    }

    public function timeout(): int
    {
        return max(1, (int) $this->config->get('pdftool.timeout_seconds', 60));
    }

    public function signTimeout(): int
    {
        return max(1, (int) $this->config->get('pdftool.sign_timeout_seconds', 120));
    }

    /**
     * Interpretador e pacote presentes (não executa nada).
     */
    public function isAvailable(): bool
    {
        $cwd = $this->workingDirectory();

        return is_file($this->pythonBinary())
            && is_dir($cwd)
            && is_file($cwd.DIRECTORY_SEPARATOR.'pdftool'.DIRECTORY_SEPARATOR.'__main__.py');
    }

    /**
     * Diretório temporário exclusivo (para adaptadores que precisam de área de trabalho).
     */
    public function temporaryDirectory(string $prefix = ''): TemporaryDirectory
    {
        return TemporaryDirectory::create($this->temporaryRoot(), $prefix);
    }

    /**
     * "pdftool X.Y.Z" (saída textual de `--version`).
     */
    public function version(): string
    {
        return $this->runRaw([$this->pythonBinary(), '-m', 'pdftool', '--version'], 'version');
    }

    /**
     * "Python 3.x.y".
     */
    public function pythonVersion(): string
    {
        return $this->runRaw([$this->pythonBinary(), '--version'], 'python-version');
    }

    public function inspect(string $path, ?string $correlationId = null): PdfInspection
    {
        $data = $this->run('inspect', ['--in', $this->absolute($path, 'PDF')], correlationId: $correlationId);

        return PdfInspection::fromArray($data);
    }

    /**
     * PNG/JPEG/WEBP → PDF de uma página. Devolve o inspect do PDF gerado
     * (raw traz `source`, `normalized` e `page_size`).
     */
    public function imageToPdf(string $in, string $out, string $page = 'A4', ?string $correlationId = null): PdfInspection
    {
        $page = strtolower(trim($page));
        if (! in_array($page, self::PAGE_SIZES, true)) {
            throw new InvalidArgumentException("Tamanho de página inválido '{$page}': use A4, letter ou fit.");
        }

        $data = $this->run('image2pdf', [
            '--in', $this->absolute($in, 'imagem'),
            '--out', $this->absolute($out, 'saída'),
            '--page', $page,
        ], correlationId: $correlationId);

        return PdfInspection::fromArray($data);
    }

    /**
     * Achata os campos do plano sobre o PDF de origem.
     */
    public function compose(ComposePlan $plan, string $out, ?string $correlationId = null): ComposeResult
    {
        $correlationId ??= $this->newCorrelationId();
        $out = $this->absolute($out, 'saída');
        $workDir = $this->temporaryDirectory();

        try {
            $planPath = $workDir->path('plan.json');
            if (file_put_contents($planPath, $plan->toJson(), LOCK_EX) === false) {
                throw new PdfToolProcessingException('Não foi possível gravar o plano de composição.', 'plan_write_failed', null, [], $correlationId);
            }

            $data = $this->run('compose', ['--plan', $planPath, '--out', $out], workDir: $workDir, correlationId: $correlationId);

            return ComposeResult::fromArray($data, $out, $correlationId);
        } finally {
            $workDir->delete();
        }
    }

    /**
     * Páginas de $base seguidas das de $extra. Devolve o total de páginas.
     */
    public function append(string $base, string $extra, string $out, ?string $correlationId = null): int
    {
        $data = $this->run('append', [
            '--base', $this->absolute($base, 'PDF base'),
            '--extra', $this->absolute($extra, 'PDF extra'),
            '--out', $this->absolute($out, 'saída'),
        ], correlationId: $correlationId);

        return (int) ($data['page_count'] ?? 0);
    }

    /**
     * Uma assinatura PAdES B-B como atualização incremental.
     *
     * $passphraseEnvVar é o NOME da variável com a senha do PKCS#12. O valor é
     * lido do ambiente do PHP e injetado no ambiente do processo filho sob esse
     * nome; nunca entra em argv, log, exceção ou fila.
     */
    public function sign(
        string $in,
        string $out,
        string $pfxPath,
        string $passphraseEnvVar,
        SignOptions $opts = new SignOptions,
        ?string $correlationId = null,
    ): SignResult {
        $correlationId ??= $this->newCorrelationId();
        $args = [
            '--in', $this->absolute($in, 'PDF'),
            '--out', $this->absolute($out, 'saída'),
            '--pfx', $this->absolute($pfxPath, 'PKCS#12'),
            '--pass-env', $this->envName($passphraseEnvVar),
            ...$opts->toArguments(),
        ];

        $secret = $this->passphrase($passphraseEnvVar, $this->argv('sign', $args), $correlationId);

        $data = $this->run(
            'sign',
            $args,
            secretEnv: [$passphraseEnvVar => $secret],
            timeout: $this->signTimeout(),
            correlationId: $correlationId,
        );

        return SignResult::fromArray($data, $out, null, $correlationId);
    }

    /**
     * @param  list<string>  $trustRoots  arquivos PEM/DER de raízes confiáveis
     */
    public function validate(string $in, array $trustRoots = [], ?string $correlationId = null): ValidationResult
    {
        $correlationId ??= $this->newCorrelationId();
        $args = ['--in', $this->absolute($in, 'PDF')];
        foreach ($trustRoots as $root) {
            $args[] = '--trust';
            $args[] = $this->absolute((string) $root, 'raiz de confiança');
        }

        $data = $this->run('validate', $args, correlationId: $correlationId);

        return ValidationResult::fromArray($data, $correlationId);
    }

    /**
     * Metadados públicos do certificado dentro de um PKCS#12 — titular, emissor, série,
     * impressão digital SHA-256 e validade.
     *
     * Serve para **identificar o certificado que vai assinar** antes de a assinatura
     * existir, em vez de adivinhá-lo pela linha mais recente de `certificate_references`.
     * Nada de chave privada e nada de senha sai daqui: a senha é lida pelo processo filho
     * do NOME da variável de ambiente, como em `sign`.
     *
     * @return array<string, mixed>
     */
    public function certificateInfo(string $pfxPath, string $passphraseEnvVar, ?string $correlationId = null): array
    {
        $correlationId ??= $this->newCorrelationId();
        $args = [
            '--pfx', $this->absolute($pfxPath, 'PKCS#12'),
            '--pass-env', $this->envName($passphraseEnvVar),
        ];

        $secret = $this->passphrase($passphraseEnvVar, $this->argv('cert-info', $args), $correlationId);

        return $this->run(
            'cert-info',
            $args,
            secretEnv: [$passphraseEnvVar => $secret],
            correlationId: $correlationId,
        );
    }

    /**
     * Certificado autoassinado de TESTE (não é ICP-Brasil). $outPem opcional
     * grava o certificado em PEM para uso como raiz em validate().
     *
     * @return array<string, mixed>
     */
    public function generateTestCertificate(
        string $outPfx,
        string $passphraseEnvVar,
        string $subject = self::DEFAULT_TEST_SUBJECT,
        int $days = 365,
        ?string $outPem = null,
        ?string $correlationId = null,
    ): array {
        $correlationId ??= $this->newCorrelationId();
        $args = [
            '--out-pfx', $this->absolute($outPfx, 'PKCS#12'),
            '--pass-env', $this->envName($passphraseEnvVar),
            '--subject', $subject,
            '--days', (string) max(1, $days),
        ];
        if ($outPem !== null) {
            $args[] = '--out-pem';
            $args[] = $this->absolute($outPem, 'PEM');
        }

        $secret = $this->passphrase($passphraseEnvVar, $this->argv('gen-test-cert', $args), $correlationId);

        return $this->run(
            'gen-test-cert',
            $args,
            secretEnv: [$passphraseEnvVar => $secret],
            timeout: $this->signTimeout(),
            correlationId: $correlationId,
        );
    }

    /**
     * Smoke test ponta a ponta do pdftool.
     *
     * @return array<string, mixed>
     */
    public function selftest(?string $correlationId = null): array
    {
        return $this->run('selftest', [], timeout: max($this->timeout(), $this->signTimeout()), correlationId: $correlationId);
    }

    public static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    /**
     * Executa um comando do pdftool e devolve o JSON de sucesso.
     *
     * @param  list<string>  $args
     * @param  array<string, string>  $secretEnv  variáveis injetadas no filho (nunca registradas)
     * @return array<string, mixed>
     */
    private function run(
        string $command,
        array $args,
        array $secretEnv = [],
        ?int $timeout = null,
        ?TemporaryDirectory $workDir = null,
        ?string $correlationId = null,
    ): array {
        $correlationId ??= $this->newCorrelationId();
        $argv = $this->argv($command, $args);
        $timeout ??= $this->timeout();
        $ownsWorkDir = $workDir === null;
        $workDir ??= $this->temporaryDirectory();

        try {
            $process = new Process(
                $argv,
                $this->workingDirectory(),
                $this->environment($workDir, $secretEnv),
                null,
                $timeout,
            );

            $startedAt = microtime(true);

            try {
                $process->run();
            } catch (ProcessTimedOutException $exception) {
                $stderr = $this->excerpt($process->getErrorOutput(), $secretEnv);
                $this->logger->error('pdftool: tempo limite excedido', [
                    'command' => $command,
                    'argv' => $argv,
                    'timeout_seconds' => $timeout,
                    'correlation_id' => $correlationId,
                    'stderr' => $stderr !== '' ? $stderr : null,
                ]);

                throw new PdfToolProcessingException(
                    sprintf('pdftool %s excedeu o tempo limite de %ds.', $command, $timeout),
                    'timeout',
                    null,
                    $argv,
                    $correlationId,
                    $stderr,
                    $exception,
                );
            } catch (ProcessException $exception) {
                throw new PdfToolProcessingException(
                    sprintf('Não foi possível executar o pdftool (%s): %s', $command, $this->redact($exception->getMessage(), $secretEnv)),
                    'process_failed',
                    null,
                    $argv,
                    $correlationId,
                    null,
                    $exception,
                );
            }

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $exitCode = $process->getExitCode();
            $stderr = $this->excerpt($process->getErrorOutput(), $secretEnv);

            $this->logger->log($exitCode === self::EXIT_OK ? 'debug' : 'warning', 'pdftool: comando executado', [
                'command' => $command,
                'argv' => $argv,
                'exit_code' => $exitCode,
                'duration_ms' => $durationMs,
                'correlation_id' => $correlationId,
                'stderr' => $stderr !== '' ? $stderr : null,
            ]);

            $payload = $this->decode($process->getOutput());
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

            if ($exitCode === self::EXIT_OK && ($payload['ok'] ?? false) === true) {
                return $payload;
            }

            $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
            $errorCode = isset($error['code']) ? (string) $error['code'] : 'unknown_error';
            $message = $this->redact(isset($error['message']) ? (string) $error['message'] : 'falha sem mensagem', $secretEnv);
            $text = sprintf('pdftool %s falhou [%s]: %s', $command, $errorCode, $message);

            throw match ($exitCode) {
                self::EXIT_USAGE => new PdfToolUsageException($text, $errorCode, $exitCode, $argv, $correlationId, $stderr),
                self::EXIT_INPUT_REJECTED => new PdfToolInputRejectedException($text, $errorCode, $exitCode, $argv, $correlationId, $stderr),
                default => new PdfToolProcessingException($text, $errorCode, $exitCode, $argv, $correlationId, $stderr),
            };
        } finally {
            if ($ownsWorkDir) {
                $workDir->delete();
            }
        }
    }

    /**
     * Comandos que devolvem texto (não JSON), como `--version`.
     *
     * @param  list<string>  $argv
     */
    private function runRaw(array $argv, string $label): string
    {
        $workDir = $this->temporaryDirectory();

        try {
            $process = new Process($argv, $this->workingDirectory(), $this->environment($workDir), null, $this->timeout());

            try {
                $process->run();
            } catch (ProcessException $exception) {
                throw new PdfToolProcessingException(
                    sprintf('Não foi possível executar %s: %s', $label, $exception->getMessage()),
                    'process_failed',
                    null,
                    $argv,
                    null,
                    null,
                    $exception,
                );
            }

            if ($process->getExitCode() !== self::EXIT_OK) {
                throw new PdfToolProcessingException(
                    sprintf('%s terminou com exit code %s.', $label, $process->getExitCode() ?? 'desconhecido'),
                    'process_failed',
                    $process->getExitCode(),
                    $argv,
                    null,
                    $this->excerpt($process->getErrorOutput()),
                );
            }

            // O Python 2 imprimia --version em stderr; o 3 usa stdout. Aceita ambos.
            $output = trim($process->getOutput());

            return $output !== '' ? $output : trim($process->getErrorOutput());
        } finally {
            $workDir->delete();
        }
    }

    /**
     * @param  list<string>  $args
     * @return list<string>
     */
    private function argv(string $command, array $args): array
    {
        return [$this->pythonBinary(), '-m', 'pdftool', $command, ...array_map('strval', $args)];
    }

    /**
     * @param  array<string, string>  $secretEnv
     * @return array<string, string|false>
     */
    private function environment(TemporaryDirectory $workDir, array $secretEnv = []): array
    {
        return ProcessEnvironment::minimal($workDir->path(), [
            'PYTHONUTF8' => '1',
            'PYTHONIOENCODING' => 'utf-8',
            'PYTHONNOUSERSITE' => '1',
            ...$secretEnv,
        ]);
    }

    /**
     * Valor da passphrase no ambiente do PHP (repositório do Dotenv: $_ENV,
     * $_SERVER, putenv). Ausente/vazia => PdfToolUsageException(missing_passphrase)
     * sem sequer iniciar o processo.
     *
     * @param  list<string>  $argv
     */
    private function passphrase(string $envName, array $argv, string $correlationId): string
    {
        $value = Env::getRepository()->get($envName);

        if (! is_string($value) || $value === '') {
            throw new PdfToolUsageException(
                sprintf('A variável de ambiente %s (passphrase do PKCS#12) não está definida ou está vazia no ambiente do PHP.', $envName),
                'missing_passphrase',
                null,
                $argv,
                $correlationId,
            );
        }

        return $value;
    }

    private function envName(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException('Nome de variável de ambiente inválido para a passphrase.');
        }

        return $name;
    }

    private function absolute(string $path, string $label): string
    {
        if (trim($path) === '') {
            throw new InvalidArgumentException("Caminho de {$label} vazio.");
        }
        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException("Caminho de {$label} inválido.");
        }
        if (! self::isAbsolutePath($path)) {
            throw new InvalidArgumentException("Caminho de {$label} deve ser absoluto: {$path}");
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

        // Contrato: exatamente uma linha. Tolera ruído antes dela (última linha).
        $lines = preg_split('/\r?\n/', $stdout) ?: [];
        $last = trim((string) end($lines));
        $decoded = $last === '' ? null : json_decode($last, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * stderr truncado e sem segredos, para log/exceção.
     *
     * @param  array<string, string>  $secrets
     */
    private function excerpt(string $stderr, array $secrets = []): string
    {
        $limit = max(200, (int) $this->config->get('pdftool.stderr_log_limit', 4000));
        $text = trim($this->redact($stderr, $secrets));
        $text = (string) preg_replace('/[^\P{C}\n\t]/u', '', $text);

        if (mb_strlen($text) > $limit) {
            $text = mb_substr($text, 0, $limit).' […truncado]';
        }

        return $text;
    }

    /**
     * @param  array<string, string>  $secrets
     */
    private function redact(string $text, array $secrets): string
    {
        foreach ($secrets as $value) {
            if ($value !== '') {
                $text = str_replace($value, '[REDACTED]', $text);
            }
        }

        return $text;
    }

    private function newCorrelationId(): string
    {
        return (string) Str::ulid();
    }
}
