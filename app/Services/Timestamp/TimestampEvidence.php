<?php

namespace App\Services\Timestamp;

use App\Models\Envelope;
use App\Services\Timestamp\Models\TimestampToken;
use Illuminate\Support\Collection;

/**
 * Props dos carimbos do tempo de um envelope para a página de evidências e para a
 * verificação pública (contrato para o front; os controllers dessas páginas são de outra
 * área — ver docs/fase-2/carimbo-e-dossie.md §7).
 *
 * Linguagem: o rótulo vem sempre de {@see TsaKind::label()} — para `operator`, "Carimbo do
 * tempo da operadora — não é carimbo ICP-Brasil". Carimbo de TSA de TESTE é marcado
 * `is_test = true` e ganha aviso próprio.
 */
final class TimestampEvidence
{
    public const NOTICE = 'O carimbo do tempo da operadora prova apenas que a AssinaVelox atesta que o resumo existia no horário indicado. Não é carimbo emitido por Autoridade de Carimbo do Tempo da ICP-Brasil e não altera o perfil da assinatura (PAdES-B-B).';

    public const TEST_NOTICE = 'Carimbo emitido por TSA de TESTE: sem valor jurídico, apenas para desenvolvimento e homologação.';

    /**
     * Página de evidências (autenticada): todos os carimbos do envelope.
     *
     * @return array{items: list<array<string, mixed>>, notice: string}
     */
    public static function forEnvelope(Envelope $envelope): array
    {
        return [
            'items' => array_values(self::tokens($envelope)->map(fn (TimestampToken $token): array => self::item($token))->all()),
            'notice' => self::NOTICE,
        ];
    }

    /**
     * Verificação pública: sem impressão digital, sem assunto completo, sem ids internos.
     *
     * @return list<array{tsa_kind: string, label: string, purpose_label: string, gen_time: string, is_test: bool}>
     */
    public static function forPublic(Envelope $envelope): array
    {
        return array_values(self::tokens($envelope)
            ->map(fn (TimestampToken $token): array => self::publicItem($token))
            ->all());
    }

    /**
     * `['timestamps' => [...]]` ou `[]` para a verificação pública (lista FECHADA de chaves: só
     * aparece quando há o que mostrar). Integração I-2C: o carimbo do MANIFESTO DO DOSSIÊ fica
     * de fora — é artefato de uma exportação pedida por alguém da organização, e publicar a hora
     * dele diria a qualquer um com o código quando o dossiê foi baixado (sinal de litígio). Ele
     * continua na página de evidências e no próprio dossiê.
     *
     * @return array<string, list<array{tsa_kind: string, label: string, purpose_label: string, gen_time: string, is_test: bool}>>
     */
    public static function publicProps(Envelope $envelope): array
    {
        $items = array_values(self::tokens($envelope)
            ->reject(fn (TimestampToken $token): bool => $token->purpose === TimestampToken::PURPOSE_DOSSIER_MANIFEST)
            ->map(fn (TimestampToken $token): array => self::publicItem($token))
            ->all());

        return $items === [] ? [] : ['timestamps' => $items];
    }

    /**
     * @return array{tsa_kind: string, label: string, purpose_label: string, gen_time: string, is_test: bool}
     */
    private static function publicItem(TimestampToken $token): array
    {
        return [
            'tsa_kind' => $token->tsa_kind->value,
            'label' => $token->tsa_kind->label(),
            'purpose_label' => self::purposeLabel($token->purpose),
            'gen_time' => $token->gen_time->toIso8601ZuluString('millisecond'),
            'is_test' => $token->isTest(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function item(TimestampToken $token): array
    {
        return [
            'id' => $token->ulid,
            'purpose' => $token->purpose,
            'purpose_label' => self::purposeLabel($token->purpose),
            'tsa_kind' => $token->tsa_kind->value,
            'label' => $token->tsa_kind->label(),
            'statement' => $token->tsa_kind->statement(),
            'gen_time' => $token->gen_time->toIso8601ZuluString('millisecond'),
            'serial' => $token->serial,
            'policy_oid' => $token->policy_oid,
            'tsa_subject' => $token->tsa_subject,
            'tsa_cert_fingerprint' => $token->tsa_cert_fingerprint,
            'hash_algorithm' => $token->hash_algorithm,
            'imprint' => $token->imprint,
            'accuracy_ms' => $token->accuracy_ms,
            'environment' => $token->environment,
            'is_test' => $token->isTest(),
            'test_notice' => $token->isTest() ? self::TEST_NOTICE : null,
            'verification' => $token->verification,
        ];
    }

    public static function purposeLabel(string $purpose): string
    {
        return match ($purpose) {
            TimestampToken::PURPOSE_DOSSIER_MANIFEST => 'Carimbo sobre o manifesto do dossiê',
            // T2: o perfil (PAdES-B-B) fica só no texto fixo; o rótulo público não cita B-T.
            TimestampToken::PURPOSE_SIGNATURE => 'Carimbo do tempo da assinatura',
            default => 'Carimbo do tempo',
        };
    }

    /**
     * @return Collection<int, TimestampToken>
     */
    private static function tokens(Envelope $envelope): Collection
    {
        return TimestampToken::withoutOrganizationScope()
            ->where('organization_id', $envelope->organization_id)
            ->where('envelope_id', $envelope->getKey())
            ->orderBy('gen_time')
            ->orderBy('id')
            ->get();
    }
}
