<?php

namespace App\Services\Timestamp;

use App\Services\Pdf\Support\TemporaryDirectory;
use App\Services\Timestamp\Exceptions\TsaException;
use App\Services\Timestamp\Exceptions\TsaUnavailableException;
use App\Services\Timestamp\Models\OperatorTsaIssuance;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * TSA RFC 3161 da OPERADORA (roadmap §2.13; `tsa_kind = operator`).
 *
 * Fluxo de toda emissão (resumo avulso, manifesto do dossiê, pedido HTTP):
 *
 * 1. confere flag `operator_tsa` e configuração (senão {@see TsaUnavailableException});
 * 2. reserva o número de série no banco em transação curta ({@see OperatorTsaSerials});
 * 3. SEM transação aberta, chama `pdftool tsa-issue` com o serial, a política e a senha
 *    do PKCS#12 injetada por nome no processo filho;
 * 4. grava o desfecho no livro (granted | rejected | failed). Um serial que falhou nunca é
 *    reaproveitado.
 *
 * O que o carimbo prova: "a AssinaVelox atesta que este resumo existia em genTime". Não é
 * carimbo ICP-Brasil. Produção exige o checklist do proprietário (HSM/KMS, NTP monitorado,
 * OID próprio, AC interna) — ver `php artisan tsa:status`.
 */
final class OperatorTsa
{
    public const DIGEST_LENGTHS = ['sha256' => 64, 'sha384' => 96, 'sha512' => 128];

    public function __construct(
        private readonly OperatorTsaConfig $config,
        private readonly OperatorTsaSerials $serials,
        private readonly TsaToolRunner $runner,
    ) {}

    public function isAvailable(): bool
    {
        return $this->unavailableReasons() === [];
    }

    /**
     * @return list<string>
     */
    public function unavailableReasons(): array
    {
        $reasons = TimestampFeatures::operatorTsa() ? [] : ['A flag operator_tsa está desligada.'];

        return [...$reasons, ...$this->config->missing()];
    }

    public function config(): OperatorTsaConfig
    {
        return $this->config;
    }

    /**
     * Carimba um resumo já calculado (manifesto do dossiê, provedor `operator`).
     */
    public function stampDigest(
        string $digestHex,
        string $hashAlgorithm = 'sha256',
        string $purpose = 'provider',
        ?int $organizationId = null,
        ?string $correlationId = null,
    ): IssuedTimestamp {
        $this->ensureAvailable();

        $algorithm = strtolower($hashAlgorithm);
        $digestHex = strtolower($digestHex);
        $expected = self::DIGEST_LENGTHS[$algorithm] ?? null;

        if ($expected === null || strlen($digestHex) !== $expected || ! ctype_xdigit($digestHex)) {
            throw new InvalidArgumentException('Resumo inválido para o algoritmo informado.');
        }

        $correlationId ??= (string) Str::ulid();
        $issuance = $this->serials->reserve($purpose, $organizationId, $correlationId);
        $workDir = $this->runner->temporaryDirectory();

        try {
            $data = $this->runner->run('tsa-issue', [
                ...$this->commonArguments($issuance, $workDir),
                '--digest', $digestHex,
                '--hash-alg', $algorithm,
                '--token-out', $workDir->path('token.der'),
            ], [$this->config->passwordEnv()], $this->config->timeout(), $workDir, $correlationId);

            if (($data['status'] ?? null) !== 'granted') {
                $this->serials->markRejected($issuance, (string) ($data['fail_info'] ?? 'rejected'));

                throw new TsaException('A TSA da operadora recusou o pedido: '.(string) ($data['status_text'] ?? 'sem motivo'), 'tsa_rejected', null, $correlationId);
            }

            $issued = IssuedTimestamp::fromTool(
                $data,
                (string) file_get_contents($workDir->path('token.der')),
                (string) file_get_contents($workDir->path('response.tsr')),
                $this->config->environment(),
                (int) $issuance->getKey(),
            );

            $this->serials->markGranted($issuance, $data);

            return $issued;
        } catch (TsaException $exception) {
            if ($issuance->status === OperatorTsaIssuance::STATUS_RESERVED) {
                $this->serials->markFailed($issuance, $exception->errorCode);
            }

            throw $exception;
        } catch (Throwable $exception) {
            $this->serials->markFailed($issuance, 'unexpected_error');

            throw new TsaException('Falha inesperada ao emitir o carimbo da operadora.', 'unexpected_error', null, $correlationId, $exception);
        } finally {
            $workDir->delete();
        }
    }

    /**
     * Responde a um `TimeStampReq` DER (endpoint interno `POST /tsa`). Pedido que a TSA não
     * aceita vira uma resposta RFC 3161 `rejection` (não um erro HTTP).
     *
     * @return array{response: string, status: string, fail_info: string|null, serial: string|null}
     */
    public function respond(string $requestDer, ?string $correlationId = null): array
    {
        $this->ensureAvailable();

        $correlationId ??= (string) Str::ulid();
        $issuance = $this->serials->reserve('http', null, $correlationId);
        $workDir = $this->runner->temporaryDirectory();

        try {
            file_put_contents($workDir->path('request.tsq'), $requestDer, LOCK_EX);

            $data = $this->runner->run('tsa-issue', [
                ...$this->commonArguments($issuance, $workDir),
                '--request', $workDir->path('request.tsq'),
            ], [$this->config->passwordEnv()], $this->config->timeout(), $workDir, $correlationId);

            $status = (string) ($data['status'] ?? 'rejection');

            if ($status === 'granted') {
                $this->serials->markGranted($issuance, $data);
            } else {
                $this->serials->markRejected($issuance, (string) ($data['fail_info'] ?? 'rejected'));
            }

            return [
                'response' => (string) file_get_contents($workDir->path('response.tsr')),
                'status' => $status,
                'fail_info' => isset($data['fail_info']) ? (string) $data['fail_info'] : null,
                'serial' => $status === 'granted' ? (string) $data['serial'] : null,
            ];
        } catch (Throwable $exception) {
            $this->serials->markFailed($issuance, $exception instanceof TsaException ? $exception->errorCode : 'unexpected_error');

            throw $exception instanceof TsaException
                ? $exception
                : new TsaException('Falha inesperada na TSA da operadora.', 'unexpected_error', null, $correlationId, $exception);
        } finally {
            $workDir->delete();
        }
    }

    /**
     * @return list<string>
     */
    private function commonArguments(OperatorTsaIssuance $issuance, TemporaryDirectory $workDir): array
    {
        return [
            '--tsa-pfx', (string) $this->config->pfxPath(),
            '--pass-env', $this->config->passwordEnv(),
            '--policy-oid', $this->config->policyOid(),
            '--serial', $issuance->serial,
            '--accuracy-ms', (string) $this->config->accuracyMs(),
            '--out', $workDir->path('response.tsr'),
        ];
    }

    private function ensureAvailable(): void
    {
        $reasons = $this->unavailableReasons();

        if ($reasons !== []) {
            throw new TsaUnavailableException($reasons);
        }
    }
}
