<?php

namespace App\Services\Signing\Certificates;

use App\Enums\ParticipantSignatureRequestStatus;
use App\Models\Envelope;
use App\Models\ParticipantSignature;
use App\Models\ParticipantSignatureRequest;
use App\Models\VerificationRecord;
use App\Services\Verification\NameMask;
use Illuminate\Support\Collection;

/**
 * Lista de assinaturas com certificado do PRÓPRIO participante, para as páginas.
 *
 * - {@see self::forEvidence()} — página de evidências (autenticada): titular, CPF mascarado,
 *   emissor, série, validade, impressão digital, consentimento, cada documento assinado e o
 *   resultado da validação de cada assinatura; também os pedidos que não chegaram a ser
 *   aplicados (desistência, prazo, falha), com o motivo.
 * - {@see self::forPublic()} — verificação pública: só as assinaturas APLICADAS, com nome
 *   MASCARADO, emissor, validade, se é certificado de teste e o resultado técnico. Sem CPF,
 *   sem série, sem impressão digital, sem e-mail (verificacao-publica.md §1.4).
 *
 * As duas devolvem lista vazia quando o envelope não tem pedido nenhum — e quem monta as
 * props só acrescenta a chave nesse caso (a lista de chaves da página pública é fechada).
 */
final class ParticipantSignatureViews
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forEvidence(Envelope $envelope): array
    {
        $requests = self::requests($envelope);

        if ($requests->isEmpty()) {
            return [];
        }

        $results = self::finalResults($envelope);

        return array_values($requests->map(function (ParticipantSignatureRequest $request) use ($results): array {
            $applied = $request->status === ParticipantSignatureRequestStatus::Applied;
            $holder = $request->holderName();

            return [
                'id' => $request->ulid,
                'kind' => 'participant_a1',
                'kind_label' => 'Assinatura com o certificado do próprio participante',
                'recipient' => [
                    'id' => $request->recipient?->ulid,
                    'name' => $request->recipient?->name,
                ],
                'status' => $request->status->value,
                'status_label' => $request->status->label(),
                'label' => $applied
                    ? CertificateInspection::signedLabel($holder, $request->issuer_cn, $request->is_test_certificate)
                    : $request->status->label(),
                'certificate' => $request->fingerprint_sha256 === null ? null : [
                    'holder_name' => $holder,
                    'holder_cpf_masked' => $request->holder_cpf_masked,
                    'subject' => $request->subject,
                    'issuer' => $request->issuer,
                    'issuer_cn' => $request->issuer_cn,
                    'serial' => $request->serial_number,
                    'fingerprint_sha256' => $request->fingerprint_sha256,
                    'valid_from' => $request->not_before?->toIso8601String(),
                    'valid_to' => $request->not_after?->toIso8601String(),
                    'is_test' => $request->is_test_certificate,
                    'kind_label' => self::kindLabel($request),
                    'declares_icp_brasil_policy' => (bool) ($request->certificate_facts['declares_icp_brasil_policy'] ?? false),
                    'icp_brasil_validated' => false,
                ],
                'consent' => $request->consent_version === null ? null : [
                    'version' => $request->consent_version,
                    'consented_at' => $request->consented_at?->toIso8601String(),
                    'legal_review_required' => ParticipantCertificateConsent::LEGAL_REVIEW_REQUIRED,
                ],
                'documents' => $request->signatures
                    ->sortBy('revision_index')
                    ->values()
                    ->map(fn (ParticipantSignature $signature): array => [
                        'document_id' => $signature->document?->ulid,
                        'name' => $signature->document?->name,
                        'position' => (int) ($signature->document->position ?? 1),
                        'field_name' => $signature->field_name,
                        'revision_index' => $signature->revision_index,
                        'signed_at' => $signature->signed_at->toIso8601String(),
                        'signed_sha256' => $signature->signedVersion?->sha256,
                        'profile' => $signature->profile,
                    ] + self::integrity($results, $signature->field_name))
                    ->all(),
                'signed_at' => $request->applied_at?->toIso8601String(),
                'window_expires_at' => $request->window_expires_at?->toIso8601String(),
                'failure' => $request->failure_code === null ? null : [
                    'code' => $request->failure_code,
                    'message' => $request->failure_message,
                ],
            ];
        })->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function forPublic(Envelope $envelope): array
    {
        $requests = self::requests($envelope)
            ->filter(fn (ParticipantSignatureRequest $request): bool => $request->status === ParticipantSignatureRequestStatus::Applied);

        if ($requests->isEmpty()) {
            return [];
        }

        $results = self::finalResults($envelope);

        return array_values($requests->map(function (ParticipantSignatureRequest $request) use ($results): array {
            $masked = NameMask::mask($request->holderName());
            $documents = $request->signatures;
            $integrity = 'intact';
            $trusted = $documents->isNotEmpty();

            foreach ($documents as $signature) {
                $row = self::integrity($results, $signature->field_name);

                if ($row['integrity'] !== 'intact') {
                    $integrity = $row['integrity'] === 'unknown' && $integrity === 'intact' ? 'unknown' : ($row['integrity'] === 'broken' ? 'broken' : $integrity);
                }

                $trusted = $trusted && $row['trusted'];
            }

            return [
                'kind' => 'participant_a1',
                'kind_label' => 'Assinatura com o certificado do próprio participante',
                'label' => CertificateInspection::signedLabel($masked, $request->issuer_cn, $request->is_test_certificate),
                'holder_name_masked' => $masked,
                'issuer_cn' => $request->issuer_cn,
                'valid_from' => $request->not_before?->toIso8601String(),
                'valid_to' => $request->not_after?->toIso8601String(),
                'is_test' => $request->is_test_certificate,
                'certificate_label' => self::kindLabel($request),
                'signed_at' => $request->applied_at?->toIso8601String(),
                'documents_count' => $documents->count(),
                'integrity' => $integrity,
                // "Válida" é só o resultado criptográfico; com certificado de TESTE a frase não
                // pode dizer "válida" ao lado de "não tem validade jurídica".
                'integrity_label' => match ($integrity) {
                    'intact' => $request->is_test_certificate
                        ? 'A assinatura está íntegra no arquivo final; o certificado é de teste e não tem validade jurídica.'
                        : 'Assinatura íntegra e válida no arquivo final.',
                    'broken' => 'A validação NÃO confirmou esta assinatura no arquivo final.',
                    default => 'Resultado desta assinatura não registrado na conclusão.',
                },
                'chain_trust' => $trusted ? 'trusted' : 'not_verified',
                'chain_trust_label' => $trusted
                    ? 'Cadeia de certificação validada até uma raiz de confiança configurada nesta plataforma.'
                    : 'Cadeia de certificação NÃO verificada por esta plataforma.',
            ];
        })->all());
    }

    /**
     * `['participant_signatures' => [...]]` ou `[]` — para espalhar nas props da página de
     * evidências sem criar a chave quando o recurso não foi usado.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function evidenceProps(Envelope $envelope): array
    {
        $list = self::forEvidence($envelope);

        return $list === [] ? [] : ['participant_signatures' => $list];
    }

    /**
     * Idem para a verificação pública (lista FECHADA de chaves: só aparece quando usada).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function publicProps(Envelope $envelope): array
    {
        $list = self::forPublic($envelope);

        return $list === [] ? [] : ['participant_signatures' => $list];
    }

    /**
     * Quantas assinaturas de participante estão APLICADAS no envelope (por pedido).
     */
    public static function appliedCount(Envelope $envelope): int
    {
        return ParticipantSignatureRequest::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('status', ParticipantSignatureRequestStatus::Applied->value)
            ->count();
    }

    public static function anyTestCertificate(Envelope $envelope): bool
    {
        return ParticipantSignatureRequest::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->where('status', ParticipantSignatureRequestStatus::Applied->value)
            ->where('is_test_certificate', true)
            ->exists();
    }

    public static function kindLabel(ParticipantSignatureRequest $request): string
    {
        $facts = $request->certificate_facts ?? [];

        if ($request->is_test_certificate) {
            return 'Certificado de TESTE — não é ICP-Brasil e não tem validade jurídica';
        }

        if (($facts['self_signed'] ?? false) === true) {
            return 'Certificado autoassinado — nenhuma autoridade certificadora atesta o titular';
        }

        return ($facts['declares_icp_brasil_policy'] ?? false) === true
            ? 'Certificado A1 que declara política ICP-Brasil (cadeia não validada por esta plataforma)'
            : 'Certificado A1 de autoridade certificadora não identificada como ICP-Brasil';
    }

    /**
     * @return Collection<int, ParticipantSignatureRequest>
     */
    private static function requests(Envelope $envelope): Collection
    {
        return ParticipantSignatureRequest::withoutOrganizationScope()
            ->with([
                'recipient' => fn ($query) => $query->withoutGlobalScopes(),
                'signatures' => fn ($query) => $query->withoutGlobalScopes(),
                'signatures.document' => fn ($query) => $query->withoutGlobalScopes(),
                'signatures.signedVersion' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->where('envelope_id', $envelope->getKey())
            ->orderBy('id')
            ->get();
    }

    /**
     * Resultado de cada assinatura no arquivo final, por nome de campo (validação registrada na
     * conclusão, em `verification_records.validation_result.result.signatures`).
     *
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
