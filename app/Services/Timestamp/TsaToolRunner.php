<?php

namespace App\Services\Timestamp;

use App\Services\Pdf\PdfToolClient;
use App\Services\Pdf\Support\ProcessEnvironment;
use App\Services\Pdf\Support\TemporaryDirectory;
use App\Services\Timestamp\Exceptions\TsaException;
use Illuminate\Support\Env;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Executa os subcomandos `tsa-issue`, `tsa-verify`, `tsa-gen-test` e `sign --tsa-*` do
 * pdftool com as MESMAS regras do {@see PdfToolClient} (que é de outra área e cujo executor
 * é privado):
 *
 * - argumentos em array, nunca shell; interpretador e cwd do PdfToolClient;
 * - ambiente mínimo ({@see ProcessEnvironment}) + só as variáveis de senha NOMEADAS pelo
 *   chamador, cujo valor é lido do ambiente do PHP no momento da chamada — nunca em argv,
 *   log, exceção ou fila;
 * - stdout = exatamente um JSON; exit 2/3/4 viram {@see TsaException} com o `error.code`;
 * - stderr registrado truncado e com o valor de cada senha redigido;
 * - diretório temporário exclusivo por chamada (o chamador pode passar o seu).
 */
final class TsaToolRunner
{
    public function __construct(
        private readonly PdfToolClient $pdftool,
        private readonly LoggerInterface $logger,
    ) {}

    public function isAvailable(): bool
    {
        return $this->pdftool->isAvailable();
    }

    public function temporaryDirectory(string $prefix = 'tsa-'): TemporaryDirectory
    {
        return $this->pdftool->temporaryDirectory($prefix);
    }

    /**
     * @param  list<string>  $args
     * @param  list<string>  $secretEnvNames  NOMES das variáveis de senha a injetar no filho
     * @return array<string, mixed>
     */
    public function run(
        string $command,
        array $args,
        array $secretEnvNames = [],
        ?int $timeout = null,
        ?TemporaryDirectory $workDir = null,
        ?string $correlationId = null,
    ): array {
        $correlationId ??= (string) Str::ulid();
        $argv = [$this->pdftool->pythonBinary(), '-m', 'pdftool', $command, ...array_map('strval', $args)];
        $secrets = $this->secrets($secretEnvNames, $command, $correlationId);
        $ownsWorkDir = $workDir === null;
        $workDir ??= $this->temporaryDirectory();
        $timeout ??= $this->pdftool->timeout();

        try {
            $process = new Process(
                $argv,
                $this->pdftool->workingDirectory(),
                ProcessEnvironment::minimal($workDir->path(), [
                    'PYTHONUTF8' => '1',
                    'PYTHONIOENCODING' => 'utf-8',
                    'PYTHONNOUSERSITE' => '1',
                    ...$secrets,
                ]),
                null,
                $timeout,
            );

            // A exceção do Symfony Process NUNCA é encadeada: ela carrega o objeto Process, e o
            // Process carrega o ambiente do filho (com as senhas). Só a classe vai para o log.
            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                $stderr = $this->redact(mb_substr(trim($process->getErrorOutput()), 0, 2000), $secrets);
                $this->logger->warning('pdftool (TSA): tempo limite excedido', [
                    'command' => $command,
                    'argv' => $argv,
                    'correlation_id' => $correlationId,
                    'stderr' => $stderr !== '' ? $stderr : null,
                ]);

                throw new TsaException(sprintf('pdftool %s excedeu o tempo limite de %ds.', $command, $timeout), 'timeout', null, $correlationId);
            } catch (ProcessException $exception) {
                $this->logger->warning('pdftool (TSA): falha ao executar o processo', [
                    'command' => $command,
                    'exception' => $exception::class,
                    'correlation_id' => $correlationId,
                ]);

                throw new TsaException(sprintf('Não foi possível executar o pdftool (%s).', $command), 'process_failed', null, $correlationId);
            }

            $exitCode = $process->getExitCode();
            $stderr = $this->redact(mb_substr(trim($process->getErrorOutput()), 0, 2000), $secrets);

            $this->logger->log($exitCode === 0 ? 'debug' : 'warning', 'pdftool (TSA): comando executado', [
                'command' => $command,
                'argv' => $argv,
                'exit_code' => $exitCode,
                'correlation_id' => $correlationId,
                'stderr' => $stderr !== '' ? $stderr : null,
            ]);

            $payload = $this->decode($process->getOutput());

            if ($payload === null) {
                throw new TsaException(sprintf('pdftool %s não devolveu JSON válido.', $command), 'invalid_output', $exitCode, $correlationId);
            }

            if ($exitCode === 0 && ($payload['ok'] ?? false) === true) {
                return $payload;
            }

            $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
            $code = isset($error['code']) ? (string) $error['code'] : 'unknown_error';
            $message = $this->redact(isset($error['message']) ? (string) $error['message'] : 'falha sem mensagem', $secrets);

            throw new TsaException(sprintf('pdftool %s falhou [%s]: %s', $command, $code, $message), $code, $exitCode, $correlationId);
        } finally {
            if ($ownsWorkDir) {
                $workDir->delete();
            }
        }
    }

    /**
     * @param  list<string>  $names
     * @return array<string, string>
     */
    private function secrets(array $names, string $command, string $correlationId): array
    {
        $values = [];

        foreach ($names as $name) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                throw new TsaException('Nome de variável de ambiente inválido para a senha.', 'invalid_env_name', null, $correlationId);
            }

            $value = Env::getRepository()->get($name);

            if (! is_string($value) || $value === '') {
                throw new TsaException(
                    sprintf('A variável de ambiente %s não está definida no ambiente do PHP (pdftool %s).', $name, $command),
                    'missing_passphrase',
                    null,
                    $correlationId,
                );
            }

            $values[$name] = $value;
        }

        return $values;
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
}
