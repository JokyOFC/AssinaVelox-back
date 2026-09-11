<?php

namespace App\Services\Timestamp;

use App\Integrations\Contracts\TimestampProvider;
use App\Integrations\Timestamp\TsaKindGuard;
use App\Models\Envelope;
use App\Models\Organization;
use App\Services\Documents\DocumentStorage;
use App\Services\Timestamp\Models\TimestampToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Guarda carimbos do tempo (`timestamp_tokens` + DER no disco privado).
 *
 * Regra T3 aplicada AQUI, no único ponto de gravação, por {@see TsaKindGuard}: o valor
 * `icp_brasil` só é aceito do provedor de ACT credenciada configurado e não simulado — hoje
 * nenhum (produção bloqueada até o contrato com uma ACT). Qualquer outra origem que se diga
 * `icp_brasil` é recusada com exceção, em vez de gravada.
 */
final class TimestampTokens
{
    public function __construct(private readonly DocumentStorage $storage) {}

    /**
     * Carimbo da TSA da operadora.
     */
    public function recordOperator(
        int $organizationId,
        IssuedTimestamp $issued,
        string $purpose,
        ?Envelope $envelope = null,
        ?int $dossierExportId = null,
        ?int $documentVersionId = null,
    ): TimestampToken {
        return $this->store($organizationId, [
            'purpose' => $purpose,
            'tsa_kind' => TsaKind::Operator,
            'provider' => 'tsa_operadora',
            'environment' => $issued->environment,
            'test_certificate' => $issued->testCertificate,
            'hash_algorithm' => $issued->hashAlgorithm,
            'imprint' => $issued->imprintHex,
            'serial' => $issued->serial,
            'gen_time' => Carbon::parse($issued->genTime),
            'policy_oid' => $issued->policyOid,
            'tsa_subject' => $issued->tsaSubject,
            'tsa_cert_fingerprint' => $issued->tsaCertFingerprint,
            'accuracy_ms' => $issued->accuracyMs,
            'token_sha256' => hash('sha256', $issued->tokenDer),
            'operator_tsa_issuance_id' => $issued->issuanceId,
        ], $issued->tokenDer, $envelope, $dossierExportId, $documentVersionId);
    }

    /**
     * Resultado de qualquer {@see TimestampProvider} (contrato reservado).
     *
     * @param  array{token_der_base64: string, tsa: string, genTime: string, serial: string, hash_algorithm: string, tsa_kind: string, simulated: bool}  $result
     */
    public function recordFromProvider(
        int $organizationId,
        TimestampProvider $provider,
        array $result,
        string $digestHex,
        string $purpose,
        ?Envelope $envelope = null,
    ): TimestampToken {
        $kind = TsaKind::tryFrom((string) $result['tsa_kind']);

        if ($kind === null) {
            throw new InvalidArgumentException('tsa_kind desconhecido.');
        }

        self::assertKindAllowed($kind, $provider, (bool) $result['simulated']);

        $der = base64_decode($result['token_der_base64'], true);

        if ($der === false) {
            throw new InvalidArgumentException('Token do provedor não está em base64.');
        }

        return $this->store($organizationId, [
            'purpose' => $purpose,
            'tsa_kind' => $kind,
            'provider' => mb_substr($provider->name(), 0, 48),
            'environment' => $provider->isSimulated() ? 'test' : 'production',
            'hash_algorithm' => $result['hash_algorithm'],
            'imprint' => strtolower($digestHex),
            'serial' => $result['serial'],
            'gen_time' => Carbon::parse($result['genTime']),
            'tsa_subject' => mb_substr($result['tsa'], 0, 255),
            'token_sha256' => hash('sha256', $der),
        ], $der, $envelope, null, null);
    }

    /**
     * T3: quem pode gravar cada tipo. `icp_brasil` exige ACT credenciada configurada de
     * verdade; simulador nunca passa de `simulated`.
     */
    public static function assertKindAllowed(TsaKind $kind, TimestampProvider $provider, bool $simulated): void
    {
        TsaKindGuard::assertStorable($kind, $provider, $simulated);
    }

    public function tokenBytes(TimestampToken $token): ?string
    {
        if ($token->storage_path === null) {
            return null;
        }

        $disk = Storage::disk($token->storage_disk ?? DocumentStorage::DISK);

        return $disk->exists($token->storage_path) ? $disk->get($token->storage_path) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function store(int $organizationId, array $attributes, string $der, ?Envelope $envelope, ?int $dossierExportId, ?int $documentVersionId): TimestampToken
    {
        $ulid = (string) Str::ulid();
        $organizationUlid = $envelope?->organization->ulid
            ?? (string) Organization::query()->whereKey($organizationId)->value('ulid');
        $path = sprintf('%s/%s/timestamps/%s.tsr', trim((string) config('assinavelox.upload.path_prefix', 'orgs'), '/'), $organizationUlid, $ulid);

        // Bytes primeiro, linha depois (mesma regra da finalização).
        $this->storage->disk()->put($path, $der);

        /** @var TimestampToken $token */
        $token = TimestampToken::withoutOrganizationScope()->create([
            ...$attributes,
            'ulid' => $ulid,
            'organization_id' => $organizationId,
            'envelope_id' => $envelope?->getKey(),
            'dossier_export_id' => $dossierExportId,
            'document_version_id' => $documentVersionId,
            'status' => 'granted',
            'storage_disk' => DocumentStorage::DISK,
            'storage_path' => $path,
        ]);

        return $token;
    }
}
