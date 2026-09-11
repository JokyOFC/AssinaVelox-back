<?php

namespace App\Services\Timestamp;

use App\Services\Timestamp\Models\OperatorTsaIssuance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sequência de números de série da TSA da operadora, persistida no banco.
 *
 * `reserve()` insere uma linha e o serial é `serial_offset + id`. Por que isso não repete
 * em corrida: o `id` autoincremental é atribuído pelo banco sob o seu próprio lock
 * (InnoDB auto-inc; SQLite AUTOINCREMENT), então dois workers — ou dois servidores —
 * recebem ids diferentes sem conversar entre si. Nada é calculado por "MAX + 1" (o erro
 * clássico). E uma linha nunca é apagada, nem quando a emissão falha: o serial fica
 * "queimado" (`failed`/`rejected`), jamais reaproveitado. A coluna `serial` é UNIQUE como
 * segunda barreira.
 *
 * A transação é curta e fecha ANTES da chamada ao pdftool (regra da onda C).
 */
final class OperatorTsaSerials
{
    public function __construct(private readonly OperatorTsaConfig $config) {}

    public function reserve(string $purpose, ?int $organizationId = null, ?string $correlationId = null): OperatorTsaIssuance
    {
        return DB::transaction(function () use ($purpose, $organizationId, $correlationId): OperatorTsaIssuance {
            /** @var OperatorTsaIssuance $issuance */
            $issuance = OperatorTsaIssuance::query()->create([
                // Marcador provisório e único; vira o serial definitivo logo abaixo.
                'serial' => 'r:'.(string) Str::ulid(),
                'purpose' => $purpose,
                'status' => OperatorTsaIssuance::STATUS_RESERVED,
                'organization_id' => $organizationId,
                'environment' => $this->config->environment(),
                'policy_oid' => $this->config->policyOid(),
                'correlation_id' => $correlationId,
            ]);

            $issuance->forceFill(['serial' => (string) ($this->config->serialOffset() + (int) $issuance->getKey())])->save();

            return $issuance;
        });
    }

    /**
     * @param  array<string, mixed>  $info  saída pública do pdftool (serial, gen_time, ...)
     */
    public function markGranted(OperatorTsaIssuance $issuance, array $info): void
    {
        $issuance->forceFill([
            'status' => OperatorTsaIssuance::STATUS_GRANTED,
            'hash_algorithm' => isset($info['hash_algorithm']) ? (string) $info['hash_algorithm'] : null,
            'imprint' => isset($info['imprint_hex']) ? (string) $info['imprint_hex'] : null,
            'gen_time' => isset($info['gen_time']) ? Carbon::parse((string) $info['gen_time']) : null,
            'token_sha256' => isset($info['token_sha256']) ? (string) $info['token_sha256'] : null,
            'tsa_cert_fingerprint' => isset($info['tsa_cert_fingerprint_sha256']) ? (string) $info['tsa_cert_fingerprint_sha256'] : null,
        ])->save();
    }

    public function markRejected(OperatorTsaIssuance $issuance, string $failInfo): void
    {
        $issuance->forceFill(['status' => OperatorTsaIssuance::STATUS_REJECTED, 'fail_info' => mb_substr($failInfo, 0, 64)])->save();
    }

    public function markFailed(OperatorTsaIssuance $issuance, string $errorCode): void
    {
        $issuance->forceFill(['status' => OperatorTsaIssuance::STATUS_FAILED, 'fail_info' => mb_substr($errorCode, 0, 64)])->save();
    }
}
