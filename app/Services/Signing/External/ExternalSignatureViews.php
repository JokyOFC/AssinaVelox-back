<?php

namespace App\Services\Signing\External;

use App\Enums\ExternalSignatureKind;
use App\Enums\ParticipantSignatureRequestStatus;
use App\Models\Envelope;
use App\Models\ParticipantSignature;
use App\Models\ParticipantSignatureRequest;
use App\Models\VerificationRecord;
use App\Services\Signing\Certificates\ParticipantSignatureViews;
use App\Services\Verification\NameMask;
use Illuminate\Support\Collection;

/**
 * Assinaturas feitas FORA do servidor (Fase 3 §3.4) para as páginas, no MESMO formato da lista
 * `participant_signatures` do A1 ({@see ParticipantSignatureViews}),
 * com `kind` e rótulos próprios (T1): `participant_a3` ou `participant_external`, e
 * "simulado — nenhum token foi usado" quando veio do simulador.
 *
 * - {@see self::forEvidence()} — página de evidências (autenticada);
 * - {@see self::forPublic()} — verificação pública: só aplicadas, nome MASCARADO, sem CPF,
 *   série, impressão digital ou e-mail.
 */
final class ExternalSignatureViews
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
            [$kind, $simulated] = self::kindOf($request);
            $applied = $request->status === ParticipantSignatureRequestStatus::Applied;
            $facts = $request->certificate_facts ?? [];

            return [
                'id' => $request->ulid,
                'kind' => ($kind ?? ExternalSignatureKind::ParticipantExternal)->value,
                'kind_label' => $kind === null ? 'Assinatura com certificado do participante por componente externo' : ExternalSignatureLabels::kind($kind, $simulated),
                'method' => ExternalSignatureService::METHOD,
                'component' => $request->getAttribute('signing_component'),
                'simulated' => $simulated,
                'recipient' => ['id' => $request->recipient?->ulid, 'name' => $request->recipient?->name],
                'status' => $request->status->value,
                'status_label' => $request->status->label(),
                'label' => $applied && $kind !== null
                    ? ExternalSignatureLabels::signed($request->holderName(), $request->issuer_cn, $kind, $simulated, $request->is_test_certificate)
                    : $request->status->label(),
                'certificate' => $request->fingerprint_sha256 === null ? null : [
                    'holder_name' => $request->holderName(),
                    'holder_cpf_masked' => $request->holder_cpf_masked,
                    'subject' => $request->subject,
                    'issuer' => $request->issuer,
                    'issuer_cn' => $request->issuer_cn,
                    'serial' => $request->serial_number,
                    'fingerprint_sha256' => $request->fingerprint_sha256,
                    'valid_from' => $request->not_before?->toIso8601String(),
                    'valid_to' => $request->not_after?->toIso8601String(),
                    'is_test' => $request->is_test_certificate,
                    'kind_label' => ExternalSignatureLabels::certificate($facts, $request->is_test_certificate),
                    'declares_icp_brasil_policy' => ($facts['declares_icp_brasil_policy'] ?? false) === true,
                    'declared_certificate_type' => $facts['declared_certificate_type'] ?? null,
                    'icp_brasil_validated' => false,
                ],
                'consent' => null,
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
                        'mode' => $signature->validation_result['mode'] ?? null,
                    ] + self::integrity($results, $signature->field_name))
                    ->all(),
                'signed_at' => $request->applied_at?->toIso8601String(),
                'window_expires_at' => $request->window_expires_at?->toIso8601String(),
                'failure' => $request->failure_code === null ? null : ['code' => $request->failure_code, 'message' => $request->failure_message],
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
            [$kind, $simulated] = self::kindOf($request);
            $kind ??= ExternalSignatureKind::ParticipantExternal;
            $masked = NameMask::mask($request->holderName());
            $integrity = 'intact';
            $trusted = $request->signatures->isNotEmpty();

            foreach ($request->signatures as $signature) {
                $row = self::integrity($results, $signature->field_name);

                if ($row['integrity'] === 'broken') {
                    $integrity = 'broken';
                } elseif ($row['integrity'] === 'unknown' && $integrity === 'intact') {
                    $integrity = 'unknown';
                }

                $trusted = $trusted && $row['trusted'];
            }

            return [
                'kind' => $kind->value,
                'kind_label' => ExternalSignatureLabels::kind($kind, $simulated),
                'label' => ExternalSignatureLabels::signed($masked, $request->issuer_cn, $kind, $simulated, $request->is_test_certificate),
                'holder_name_masked' => $masked,
                'issuer_cn' => $request->issuer_cn,
                'valid_from' => $request->not_before?->toIso8601String(),
                'valid_to' => $request->not_after?->toIso8601String(),
                'is_test' => $request->is_test_certificate,
                'simulated' => $simulated,
                'certificate_label' => ExternalSignatureLabels::certificate($request->certificate_facts ?? [], $request->is_test_certificate),
                'signed_at' => $request->applied_at?->toIso8601String(),
                'documents_count' => $request->signatures->count(),
                'integrity' => $integrity,
                'integrity_label' => match ($integrity) {
                    'intact' => $request->is_test_certificate || $simulated
                        ? 'A assinatura está íntegra no arquivo final; o certificado é de teste ou a assinatura é simulada, sem validade jurídica.'
                        : 'Assinatura íntegra e válida no arquivo final.',
                    'broken' => 'A validação NÃO confirmou esta assinatura no arquivo final.',
                    default => 'Resultado desta assinatura não registrado na conclusão.',
                },
                'chain_trust' => $trusted ? 'trusted' : 'not_verified',
                'chain_trust_label' => $trusted
                    ? 'Cadeia de certificação validada até uma âncora configurada nesta plataforma. Revogação não verificada.'
                    : 'Cadeia de certificação NÃO verificada por esta plataforma.',
            ];
        })->all());
    }

    /**
     * @return array{0: ExternalSignatureKind|null, 1: bool}
     */
    private static function kindOf(ParticipantSignatureRequest $request): array
    {
        $kind = null;
        $simulated = false;

        foreach ($request->signatures as $signature) {
            $current = ExternalSignatureKind::tryFrom((string) $signature->getAttribute('signature_status'));
            $simulated = $simulated || (bool) $signature->getAttribute('is_simulated');

            // Uma única assinatura "externa" basta para o pedido não ser chamado de A3.
            if ($current === ExternalSignatureKind::ParticipantExternal || $kind === null) {
                $kind = $current ?? $kind;
            }
        }

        return [$kind, $simulated];
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
            ->where('signature_method', ExternalSignatureService::METHOD)
            ->orderBy('id')
            ->get();
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
