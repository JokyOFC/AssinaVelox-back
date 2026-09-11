<?php

namespace App\Services\Timestamp;

use App\Services\Pdf\Dto\SignOptions;
use App\Services\Pdf\PdfToolClient;
use App\Services\Timestamp\Exceptions\TsaException;
use App\Services\Timestamp\Exceptions\TsaUnavailableException;
use App\Services\Timestamp\Models\OperatorTsaIssuance;
use Illuminate\Support\Str;
use Throwable;

/**
 * Assinatura PAdES com o carimbo da TSA da operadora embutido (`pdftool sign --tsa-*`),
 * atrás da flag `pades_bt` (que exige `operator_tsa`).
 *
 * O perfil devolvido/anunciado continua {@see PadesProfilePolicy::DECLARED_PROFILE}
 * (`PAdES-B-B`). O fato técnico — há um carimbo de assinatura, de tipo `operator` — sai em
 * `signature_timestamp`, com o rótulo "carimbo do tempo da operadora — não é carimbo
 * ICP-Brasil" e `announced = false`.
 *
 * Integração pendente (fora da área do K-TSA): `OperatorSignature` e o pipeline do
 * participante (K-A1) chamam este serviço no lugar de `PdfToolClient::sign()` quando
 * `TimestampFeatures::padesBt()` for verdadeiro, com degradação explícita para B-B
 * registrada se a TSA estiver indisponível (R5). O serial é reservado no banco ANTES da
 * chamada e nunca é reaproveitado.
 */
final class PadesBtSigner
{
    public function __construct(
        private readonly OperatorTsa $tsa,
        private readonly OperatorTsaSerials $serials,
        private readonly TsaToolRunner $runner,
        private readonly PdfToolClient $pdftool,
    ) {}

    public function isAvailable(): bool
    {
        return TimestampFeatures::padesBt() && $this->tsa->isAvailable();
    }

    /**
     * @return array{declared_profile: string, signature_timestamp: array<string, mixed>|null, result: array<string, mixed>}
     */
    public function sign(
        string $in,
        string $out,
        string $pfxPath,
        string $pfxPasswordEnv,
        SignOptions $options = new SignOptions,
        ?int $organizationId = null,
        ?string $correlationId = null,
    ): array {
        if (! TimestampFeatures::padesBt()) {
            throw new TsaUnavailableException(['A flag pades_bt está desligada (ou operator_tsa).']);
        }

        $reasons = $this->tsa->unavailableReasons();

        if ($reasons !== []) {
            throw new TsaUnavailableException($reasons);
        }

        $correlationId ??= (string) Str::ulid();
        $config = $this->tsa->config();
        $issuance = $this->serials->reserve('signature', $organizationId, $correlationId);

        try {
            $result = $this->runner->run('sign', [
                '--in', $in,
                '--out', $out,
                '--pfx', $pfxPath,
                '--pass-env', $pfxPasswordEnv,
                ...$options->toArguments(),
                '--tsa-pfx', (string) $config->pfxPath(),
                '--tsa-pass-env', $config->passwordEnv(),
                '--tsa-serial', $issuance->serial,
                '--tsa-policy-oid', $config->policyOid(),
                '--tsa-accuracy-ms', (string) $config->accuracyMs(),
            ], [$pfxPasswordEnv, $config->passwordEnv()], $this->pdftool->signTimeout(), null, $correlationId);
        } catch (Throwable $exception) {
            $this->serials->markFailed($issuance, $exception instanceof TsaException ? $exception->errorCode : 'unexpected_error');

            throw $exception;
        }

        $stamp = is_array($result['signature_timestamp'] ?? null) ? $result['signature_timestamp'] : null;

        if ($stamp === null) {
            $this->serials->markFailed($issuance, 'no_timestamp_embedded');
        } elseif ($issuance->status === OperatorTsaIssuance::STATUS_RESERVED) {
            $this->serials->markGranted($issuance, $stamp);
        }

        return [
            // T2: o anunciado é SEMPRE o declarado, nunca o `unannounced_profile` do pdftool.
            'declared_profile' => PadesProfilePolicy::declaredProfile(),
            'signature_timestamp' => $stamp,
            'result' => $result,
        ];
    }
}
