<?php

namespace App\Services\Verification;

use App\Models\RetentionDeletion;
use App\Services\Retention\RetentionConfig;
use App\Services\Retention\VerificationAfterPurge;

/**
 * Verificação pública DEPOIS da exclusão por retenção (decisão pendente viabilidade §4.5
 * item 30; docs/fase-2/retencao-e-preservacao.md §6).
 *
 * Decisão adotada (recomendada, configurável pela operadora em
 * `assinavelox.retention.verification_after_purge`): {@see VerificationAfterPurge::NoticeWithFinalHash}
 * — a página diz apenas que o registro foi removido por política de retenção e em que data, e
 * mantém SÓ o(s) resumo(s) SHA-256 do(s) arquivo(s) final(is), sem título, organização,
 * participantes, linha do tempo ou resumos intermediários. Quem guardou uma cópia ainda
 * consegue conferir que ela é o arquivo emitido; quem não tem a cópia não aprende nada sobre
 * o conteúdo nem sobre as pessoas (um resumo SHA-256 não é revertível).
 *
 * A configuração vigente é o TETO: `hidden` esconde recibos que guardaram resumos, e `notice`
 * não mostra resumos ainda que guardados. Só recibos de envelopes que JÁ ERAM publicáveis
 * (enviados) têm código; rascunhos expurgados continuam indistinguíveis de código inexistente.
 */
final class RetentionTombstones
{
    public function find(string $normalizedCode): ?RetentionDeletion
    {
        if ($normalizedCode === '' || ! RetentionConfig::verificationMode()->keepsCode()) {
            return null;
        }

        return RetentionDeletion::withoutOrganizationScope()
            ->where('verification_code', $normalizedCode)
            ->where('subject_type', RetentionDeletion::SUBJECT_ENVELOPE)
            ->first();
    }

    /**
     * @return list<string>
     */
    public function hashes(RetentionDeletion $deletion): array
    {
        if (! RetentionConfig::verificationMode()->keepsHashes()) {
            return [];
        }

        return array_values(array_filter(
            $deletion->final_hashes ?? [],
            static fn (string $hash): bool => preg_match('/^[a-f0-9]{64}$/', $hash) === 1,
        ));
    }

    /**
     * Props no MESMO formato de `PublicVerification::result()` (a página atual renderiza sem
     * mudança), com a chave extra `retention` para a tela dedicada. Lista fechada: nada além
     * do código, da data, do status e — conforme a decisão — dos resumos finais.
     *
     * @return array<string, mixed>
     */
    public function result(PurgedEnvelope $envelope): array
    {
        $deletion = $envelope->retentionDeletion;

        if ($deletion === null) {
            return [];
        }

        $purgedAt = ($deletion->purged_at ?? $deletion->created_at)?->toIso8601String();
        $hashes = $this->hashes($deletion);
        $mode = RetentionConfig::verificationMode();
        $message = 'O registro deste documento foi removido pela política de retenção da organização responsável. O arquivo, os participantes e a linha do tempo não estão mais disponíveis nesta plataforma.';

        $status = in_array($deletion->envelope_status, ['completed', 'refused', 'expired', 'canceled'], true)
            ? $deletion->envelope_status
            : 'completed';

        $result = [
            'verification_code' => $envelope->formatted_verification_code,
            'status' => $status,
            'status_label' => 'Removido por política de retenção',
            'title' => 'Registro removido por política de retenção',
            'organization_name' => '',
            'created_at' => null,
            'sent_at' => null,
            'completed_at' => null,
            'pages' => 0,
            'hashes' => [
                'sent_sha256' => null,
                'final_sha256' => $hashes[0] ?? null,
                'original_sha256' => null,
                'signed_sha256' => $hashes[0] ?? null,
            ],
            'hash_primer' => $hashes !== []
                ? 'Se você guardou uma cópia do arquivo final, confira abaixo se ela é o arquivo emitido: o resumo SHA-256 é calculado no seu navegador e o arquivo não é enviado.'
                : null,
            'signature_status' => 'none',
            'signature_state' => 'none',
            'signature_label' => null,
            'signature_statement' => $message,
            'signature_profile' => null,
            'certificate' => null,
            'validation' => null,
            'validation_summary' => null,
            'verify_url' => route('verify.show', ['code' => $deletion->verification_code]),
            'recipients' => [],
            'events_summary' => $purgedAt !== null
                ? [['label' => 'Registro removido por política de retenção', 'occurred_at' => $purgedAt]]
                : [],
            'retention' => [
                'purged' => true,
                'purged_at' => $purgedAt,
                'mode' => $mode->value,
                'message' => $message,
                'final_hashes_count' => count($hashes),
            ],
        ];

        if (count($hashes) > 1) {
            $result['documents'] = array_map(fn (string $hash, int $index): array => [
                'position' => $index + 1,
                'name' => 'Arquivo '.($index + 1),
                'pages' => 0,
                'sent_sha256' => null,
                'final_sha256' => $hash,
            ], $hashes, array_keys($hashes));
            $result['documents_count'] = count($hashes);
        }

        return $result;
    }

    /**
     * Conferência por resumo digitado: só contra os resumos finais mantidos.
     *
     * @return array<string, mixed>
     */
    public function checkHash(PurgedEnvelope $envelope, string $sha256): array
    {
        $checked = strtolower(trim($sha256));
        $hashes = $envelope->retentionDeletion !== null ? $this->hashes($envelope->retentionDeletion) : [];

        foreach ($hashes as $index => $hash) {
            if (hash_equals($hash, $checked)) {
                return count($hashes) > 1
                    ? ['matches' => 'signed', 'checked_sha256' => $checked, 'document' => ['position' => $index + 1, 'name' => 'Arquivo '.($index + 1)]]
                    : ['matches' => 'signed', 'checked_sha256' => $checked];
            }
        }

        return count($hashes) > 1
            ? ['matches' => 'none', 'checked_sha256' => $checked, 'document' => null]
            : ['matches' => 'none', 'checked_sha256' => $checked];
    }
}
