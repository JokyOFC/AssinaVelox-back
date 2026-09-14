<?php

namespace App\Services\Signing\GovBr;

use App\Enums\DocumentVersionKind;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\VerificationRecord;
use App\Services\Signing\Certificates\ParticipantSignatureViews;
use App\Services\Signing\External\ExternalSignatureLabels;
use App\Services\Signing\GovBr\Models\ExternalSignatureRequest;
use App\Services\Verification\NameMask;
use Illuminate\Support\Collection;

/**
 * Devoluções do portal gov.br ACEITAS (Fase 3 §3.5) para as páginas, no MESMO formato da lista
 * `participant_signatures` ({@see ParticipantSignatureViews}), com `kind` e rótulo próprios (T1):
 *
 * - `participant_govbr` — "Assinatura gov.br (avançada)": só com a cadeia validada até uma âncora
 *   gov.br fixada por impressão digital;
 * - `participant_external_unverified` — "Assinatura digital de terceiro, cadeia não verificada":
 *   qualquer outro aceite. NUNCA dito gov.br.
 *
 * Integração I-3A: antes desta classe as devoluções ficavam fora da lista (docs/fase-3/gov-br.md §8
 * item 4). A página pública recebe só o aceito, com nome MASCARADO e sem CPF, série, impressão
 * digital ou e-mail.
 */
final class GovBrSignatureViews
{
    public const METHOD = 'govbr_return';

    /**
     * @return list<array<string, mixed>>
     */
    public static function forEvidence(Envelope $envelope): array
    {
        $rows = self::completed($envelope);

        if ($rows->isEmpty()) {
            return [];
        }

        $results = self::finalResults($envelope);

        return array_values($rows->map(function (ExternalSignatureRequest $row) use ($results): array {
            $kind = $row->signature_kind ?? GovBrSignatureKind::ParticipantExternalUnverified;
            $integrity = self::integrity($results, (string) $row->field_name);

            return [
                'id' => $row->ulid,
                'kind' => $kind->value,
                'kind_label' => $kind->label(),
                'method' => self::METHOD,
                'component' => null,
                'simulated' => false,
                'recipient' => ['id' => $row->recipient?->ulid, 'name' => $row->recipient?->name],
                'status' => 'applied',
                'status_label' => 'Devolvido e conferido',
                'label' => self::signedLabel($row->holder_name, self::issuerCn($row->signer_issuer), $kind, $row->is_test_certificate),
                'certificate' => $row->signer_fingerprint_sha256 === null ? null : [
                    'holder_name' => $row->holder_name,
                    'holder_cpf_masked' => $row->holder_cpf_masked,
                    'subject' => $row->signer_subject,
                    'issuer' => $row->signer_issuer,
                    'issuer_cn' => self::issuerCn($row->signer_issuer),
                    'serial' => $row->signer_serial,
                    'fingerprint_sha256' => $row->signer_fingerprint_sha256,
                    'valid_from' => $row->signer_not_before?->toIso8601String(),
                    'valid_to' => $row->signer_not_after?->toIso8601String(),
                    'is_test' => $row->is_test_certificate,
                    'kind_label' => $row->is_test_certificate ? ExternalSignatureLabels::TEST_CERTIFICATE : $kind->description(),
                    'declares_icp_brasil_policy' => false,
                    'declared_certificate_type' => null,
                    'icp_brasil_validated' => false,
                ],
                'consent' => null,
                'documents' => [[
                    'document_id' => $row->document?->ulid,
                    'name' => $row->document?->name,
                    'position' => (int) ($row->document->position ?? 1),
                    'field_name' => $row->field_name,
                    'revision_index' => self::revisionIndex($row),
                    'signed_at' => $row->completed_at?->toIso8601String(),
                    'signed_sha256' => $row->signedVersion?->sha256,
                    'profile' => null,
                    'mode' => null,
                ] + $integrity],
                'signed_at' => $row->completed_at?->toIso8601String(),
                'window_expires_at' => null,
                'failure' => null,
            ];
        })->all());
    }

    /**
     * Posição da revisão devolvida na cadeia de revisões assinadas do documento (1 = primeira
     * `signed_incremental`), como a das assinaturas por componente e A1. Null sem a versão.
     */
    private static function revisionIndex(ExternalSignatureRequest $row): ?int
    {
        $signed = $row->signedVersion;

        if ($signed === null) {
            return null;
        }

        $index = DocumentVersion::withoutOrganizationScope()
            ->where('document_id', $signed->document_id)
            ->where('kind', DocumentVersionKind::SignedIncremental->value)
            ->where('version_number', '<=', $signed->version_number)
            ->count();

        return $index > 0 ? $index : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function forPublic(Envelope $envelope): array
    {
        $rows = self::completed($envelope);

        if ($rows->isEmpty()) {
            return [];
        }

        $results = self::finalResults($envelope);

        return array_values($rows->map(function (ExternalSignatureRequest $row) use ($results): array {
            $kind = $row->signature_kind ?? GovBrSignatureKind::ParticipantExternalUnverified;
            $masked = NameMask::mask($row->holder_name);
            $check = self::integrity($results, (string) $row->field_name);
            $trusted = $kind === GovBrSignatureKind::ParticipantGovBr && $row->trusted;

            return [
                'kind' => $kind->value,
                'kind_label' => $kind->label(),
                'simulated' => false,
                'label' => self::signedLabel($masked, self::issuerCn($row->signer_issuer), $kind, $row->is_test_certificate),
                'holder_name_masked' => $masked,
                'issuer_cn' => self::issuerCn($row->signer_issuer),
                'valid_from' => $row->signer_not_before?->toIso8601String(),
                'valid_to' => $row->signer_not_after?->toIso8601String(),
                'is_test' => $row->is_test_certificate,
                'certificate_label' => $row->is_test_certificate ? ExternalSignatureLabels::TEST_CERTIFICATE : $kind->description(),
                'signed_at' => $row->completed_at?->toIso8601String(),
                'documents_count' => 1,
                'integrity' => $check['integrity'],
                'integrity_label' => match ($check['integrity']) {
                    'intact' => $row->is_test_certificate
                        ? 'A assinatura está íntegra no arquivo final; o certificado é de teste, sem validade jurídica.'
                        : 'Assinatura íntegra e válida no arquivo final.',
                    'broken' => 'A validação NÃO confirmou esta assinatura no arquivo final.',
                    default => 'Resultado desta assinatura não registrado na conclusão.',
                },
                'chain_trust' => $trusted ? 'trusted' : 'not_verified',
                'chain_trust_label' => $trusted
                    ? 'Cadeia de certificação validada até a âncora gov.br fixada nesta plataforma. Revogação não verificada.'
                    : 'Cadeia de certificação NÃO verificada por esta plataforma.',
            ];
        })->all());
    }

    public static function signedLabel(?string $holder, ?string $issuerCn, GovBrSignatureKind $kind, bool $test): string
    {
        $who = $holder !== null && $holder !== '' ? $holder : 'titular não identificado';
        $issuer = $issuerCn !== null && $issuerCn !== '' ? $issuerCn : 'emissor não identificado';

        $label = $kind === GovBrSignatureKind::ParticipantGovBr
            ? sprintf('Assinatura gov.br (avançada) de %s, devolvida pelo portal, com certificado emitido por %s', $who, $issuer)
            : sprintf('Assinatura digital de terceiro, cadeia não verificada — documento devolvido por %s, certificado emitido por %s', $who, $issuer);

        return $test ? $label.' — '.ExternalSignatureLabels::TEST_CERTIFICATE : $label;
    }

    /**
     * @return Collection<int, ExternalSignatureRequest>
     */
    private static function completed(Envelope $envelope): Collection
    {
        return ExternalSignatureRequest::withoutOrganizationScope()
            ->with([
                'recipient' => fn ($query) => $query->withoutGlobalScopes(),
                'document' => fn ($query) => $query->withoutGlobalScopes(),
                'signedVersion' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->where('envelope_id', $envelope->getKey())
            ->where('status', ExternalSignatureRequestStatus::Completed->value)
            ->whereNotNull('signed_document_version_id')
            ->orderBy('completed_at')
            ->orderBy('id')
            ->get();
    }

    private static function issuerCn(?string $issuer): ?string
    {
        if ($issuer === null || $issuer === '') {
            return null;
        }

        return preg_match('/(?:^|,)\s*CN=([^,]+)/i', $issuer, $match) === 1 ? trim($match[1]) : $issuer;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function finalResults(Envelope $envelope): array
    {
        /** @var VerificationRecord|null $record */
        $record = VerificationRecord::query()->where('envelope_id', $envelope->getKey())->first();
        $payload = $record?->validation_result;
        $signatures = is_array($payload) && is_array($payload['result']['signatures'] ?? null) ? $payload['result']['signatures'] : [];
        $map = [];

        foreach ($signatures as $signature) {
            if (is_array($signature) && is_string($signature['field_name'] ?? null)) {
                $map[$signature['field_name']] = $signature;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, array<string, mixed>>  $results
     * @return array{integrity: string, trusted: bool, coverage: string|null, modification_level: string|null}
     */
    private static function integrity(array $results, string $fieldName): array
    {
        $row = $results[$fieldName] ?? null;

        if ($row === null) {
            return ['integrity' => 'unknown', 'trusted' => false, 'coverage' => null, 'modification_level' => null];
        }

        return [
            'integrity' => ($row['intact'] ?? false) === true && ($row['valid'] ?? false) === true ? 'intact' : 'broken',
            'trusted' => ($row['trusted'] ?? false) === true,
            'coverage' => is_string($row['coverage'] ?? null) ? $row['coverage'] : null,
            'modification_level' => is_string($row['modification_level'] ?? null) ? $row['modification_level'] : null,
        ];
    }
}
