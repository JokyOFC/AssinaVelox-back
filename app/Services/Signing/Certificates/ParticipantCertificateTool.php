<?php

namespace App\Services\Signing\Certificates;

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
 * Chamadas ao pdftool que usam o certificado do PARTICIPANTE (`inspect-cert`,
 * `participant-sign`, `gen-test-participant-cert`).
 *
 * Mesma disciplina do {@see PdfToolClient} — argv em array, sem shell, ambiente mínimo,
 * JSON único em stdout, exit codes 2/3/4 em exceções tipadas — com uma diferença: a senha
 * do participante NÃO existe no ambiente do PHP (ela chega na requisição ou sai do material
 * cifrado). Ela é passada por valor, marcada `#[\SensitiveParameter]` em toda a cadeia
 * (não aparece em stack trace), e injetada **somente** no ambiente do processo filho sob o
 * nome {@see self::PASSWORD_ENV}. Nunca em argv, log, exceção ou fila; o stderr e as
 * mensagens de erro passam por redação antes de qualquer registro.
 *
 * Nenhuma exceção daqui encadeia a exceção do Symfony Process: ela carrega o objeto
 * Process, e o objeto Process carrega o ambiente do filho.
 */
final class ParticipantCertificateTool
{
    public const PASSWORD_ENV = 'AV_PARTICIPANT_PFX_PASS';

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
     * Confere o PKCS#12 e devolve os fatos públicos. Recusa (PdfToolInputRejectedException)
     * senha errada, arquivo corrompido, PFX sem chave, certificado vencido/ainda não válido e
     * certificado sem uso para assinatura — o `errorCode` é o do pdftool.
     */
    public function inspect(string $pfxPath, #[\SensitiveParameter] string $password, ?string $correlationId = null): CertificateInspection
    {
        $data = $this->run('inspect-cert', [
            '--pfx', $this->absolute($pfxPath),
            '--pass-env', self::PASSWORD_ENV,
        ], $password, $this->client->timeout(), $correlationId);

        return CertificateInspection::fromArray($data);
    }

    /**
     * Assinatura PAdES B-B incremental com o certificado do participante + validação de
     * TODAS as assinaturas do arquivo resultante.
     *
     * @param  list<string>  $trustRoots
     * @return array<string, mixed>
     */
    public function sign(
        string $input,
        string $output,
        string $pfxPath,
        #[\SensitiveParameter] string $password,
        string $fieldName,
        ?string $expectFingerprint = null,
        array $trustRoots = [],
        ?string $reason = null,
        ?string $correlationId = null,
    ): array {
        $args = [
            '--in', $this->absolute($input),
            '--out', $this->absolute($output),
            '--pfx', $this->absolute($pfxPath),
            '--pass-env', self::PASSWORD_ENV,
            '--field-name', $fieldName,
        ];

        if ($expectFingerprint !== null && $expectFingerprint !== '') {
            $args[] = '--expect-fingerprint';
            $args[] = $expectFingerprint;
        }

        if ($reason !== null && $reason !== '') {
            $args[] = '--reason';
            $args[] = $reason;
        }

        foreach ($trustRoots as $root) {
            $args[] = '--trust';
            $args[] = $this->absolute($root);
        }

        return $this->run('participant-sign', $args, $password, $this->client->signTimeout(), $correlationId);
    }

    /**
     * Certificado de TESTE de participante (AC de teste descartável). Só para testes e
     * desenvolvimento: CN sempre com "TESTE", nunca ICP-Brasil.
     *
     * @return array<string, mixed>
     */
    public function generateTestCertificate(
        string $outPfx,
        #[\SensitiveParameter] string $password,
        string $name = 'Participante',
        ?string $cpf = null,
        int $days = 30,
        int $validFromDays = 0,
        bool $noKey = false,
        string $keyUsage = 'signing',
        ?string $outCaPem = null,
        bool $selfSigned = false,
    ): array {
        $args = [
            '--out-pfx', $this->absolute($outPfx),
            '--pass-env', self::PASSWORD_ENV,
            '--name', $name,
            '--days', (string) $days,
            '--valid-from-days', (string) $validFromDays,
            '--key-usage', $keyUsage,
        ];

        if ($cpf !== null) {
            $args[] = '--cpf';
            $args[] = $cpf;
        }

        if ($noKey) {
            $args[] = '--no-key';
        }

        if ($selfSigned) {
            $args[] = '--self-signed';
        }

        if ($outCaPem !== null) {
            $args[] = '--out-ca-pem';
            $args[] = $this->absolute($outCaPem);
        }

        return $this->run('gen-test-participant-cert', $args, $password, $this->client->signTimeout(), null);
    }

    /**
     * @param  list<string>  $args
     * @return array<string, mixed>
     */
    private function run(string $command, array $args, #[\SensitiveParameter] string $secret, int $timeout, ?string $correlationId): array
    {
        $correlationId ??= (string) Str::ulid();
        $argv = [$this->client->pythonBinary(), '-m', 'pdftool', $command, ...$args];
        $workDir = TemporaryDirectory::create($this->client->temporaryRoot(), 'a1-proc-');

        try {
            $process = new Process(
                $argv,
                $this->client->workingDirectory(),
                ProcessEnvironment::minimal($workDir->path(), [
                    'PYTHONUTF8' => '1',
                    'PYTHONIOENCODING' => 'utf-8',
                    'PYTHONNOUSERSITE' => '1',
                    self::PASSWORD_ENV => $secret,
                ]),
                null,
                $timeout,
            );

            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                throw new PdfToolProcessingException(
                    sprintf('pdftool %s excedeu o tempo limite de %ds.', $command, $timeout),
                    'timeout',
                    null,
                    $argv,
                    $correlationId,
                    $this->excerpt($process->getErrorOutput(), $secret),
                );
            } catch (ProcessException) {
                throw new PdfToolProcessingException(
                    sprintf('Não foi possível executar o pdftool (%s).', $command),
                    'process_failed',
                    null,
                    $argv,
                    $correlationId,
                );
            }

            $exitCode = $process->getExitCode();
            $stderr = $this->excerpt($process->getErrorOutput(), $secret);
            // O stdout é o JSON PÚBLICO do pdftool (que nunca imprime a senha — há teste para
            // isso no pdftool). Não é redigido: trocar a senha aqui corromperia fatos públicos
            // que por acaso contenham a mesma sequência (o ano da validade, um trecho da
            // impressão digital). A redação vale só para o stderr e para a mensagem de erro.
            $stdout = $process->getOutput();
            unset($process);

            $this->logger->log($exitCode === PdfToolClient::EXIT_OK ? 'debug' : 'warning', 'pdftool (certificado do participante): comando executado', [
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
            $message = $this->redact(isset($error['message']) ? (string) $error['message'] : 'falha sem mensagem', $secret);
            $text = sprintf('pdftool %s falhou [%s]: %s', $command, $errorCode, $message);

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

    private function excerpt(string $stderr, #[\SensitiveParameter] string $secret): string
    {
        $limit = max(200, (int) $this->config->get('pdftool.stderr_log_limit', 4000));
        $text = trim($this->redact($stderr, $secret));
        $text = (string) preg_replace('/[^\P{C}\n\t]/u', '', $text);

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit).' […truncado]' : $text;
    }

    private function redact(string $text, #[\SensitiveParameter] string $secret): string
    {
        return $secret === '' ? $text : str_replace($secret, '[REDACTED]', $text);
    }
}
