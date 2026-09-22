<?php

namespace App\Integrations\Identity\Verifiky;

use Illuminate\Support\Str;

/**
 * Lê uma resposta da Verifiky — a imediata do envio, o webhook `verification.completed` ou a
 * leitura de `GET /verificacoes/{id}` — e devolve o resultado no vocabulário do contrato.
 *
 * Os três formatos foram levantados na integração do metta-bank (VerifikyController,
 * VerifikyWebhookProcessor): o identificador vem como `verificacao_id`, `verification_id` ou
 * `data.verification_id`; a comparação de rostos vem como booleano ou como
 * `{match|verified, approved}`; o status vem em inglês.
 *
 * Regra de aprovação (a mesma de lá): `status = approved` E rosto correspondente E, quando o
 * provedor manda `approved`, ele não é falso. Duas diferenças deliberadas:
 *
 *  - rosto AUSENTE da resposta não é rosto diferente. Lá, `pending` sem `face_match` reprovava
 *    a pessoa; aqui continua `pending` e espera o resultado completo.
 *  - nada que não se entenda vira aprovação (T5): `success = false`, status desconhecido e
 *    resposta vazia saem como `inconclusive`.
 *
 * Os `details` são uma lista fechada, sem imagem e sem os dados lidos do documento (nome, CPF,
 * número) — a evidência guarda o que o provedor CONCLUIU, não o que ele extraiu.
 */
final class VerifikyResultMapper
{
    private const APPROVED = ['approved', 'aprovado', 'aprovada'];

    private const REJECTED = ['rejected', 'declined', 'reprovado', 'reprovada', 'rejeitado', 'rejeitada', 'recusado', 'recusada'];

    private const PENDING = ['pending', 'processing', 'in_review', 'review', 'pendente', 'processando', 'em_analise'];

    private const EXPIRED = ['expired', 'expirado', 'expirada'];

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: 'pending'|'approved'|'rejected'|'inconclusive'|'expired', verification_id: string|null, reference: string|null, details: array<string, mixed>}
     */
    public static function map(array $payload): array
    {
        /** @var array<string, mixed> $data */
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $providerStatus = self::text($payload['status'] ?? $data['status'] ?? null);
        $verified = self::bool($payload['verificado'] ?? $data['verificado'] ?? null);
        [$match, $matchApproved, $score] = self::faceMatch($payload, $data);
        $refused = ($payload['success'] ?? null) === false;

        $faceOk = ($match === true && $matchApproved !== false)
            || ($match === null && $verified === true);

        $status = match (true) {
            in_array($providerStatus, self::REJECTED, true) => 'rejected',
            in_array($providerStatus, self::EXPIRED, true) => 'expired',
            in_array($providerStatus, self::APPROVED, true) && $faceOk => 'approved',
            // O provedor aprovou o documento e disse que o rosto NÃO corresponde.
            $match === false || $matchApproved === false => 'rejected',
            $refused => 'inconclusive',
            in_array($providerStatus, self::APPROVED, true),
            in_array($providerStatus, self::PENDING, true),
            $providerStatus === null && self::identifier($payload, $data) !== null => 'pending',
            default => 'inconclusive',
        };

        $reasonCode = match (true) {
            $status === 'rejected' && ($match === false || $matchApproved === false) => 'face_mismatch',
            $status === 'rejected' => 'provider_rejected',
            $status === 'inconclusive' && $refused => 'provider_refused',
            $status === 'inconclusive' => 'unreadable_result',
            default => null,
        };

        return [
            'status' => $status,
            'verification_id' => self::identifier($payload, $data),
            'reference' => self::text($payload['user_reference'] ?? $data['user_reference'] ?? null, lower: false),
            'details' => array_filter([
                'provider_status' => $providerStatus,
                'verified_flag' => $verified,
                'face_match' => $match,
                'face_match_approved' => $matchApproved,
                'face_score' => $score,
                'reason_code' => $reasonCode,
                'reason' => self::reason($payload, $data, $status),
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $data
     */
    private static function identifier(array $payload, array $data): ?string
    {
        foreach ([$payload['verificacao_id'] ?? null, $payload['verification_id'] ?? null, $data['verification_id'] ?? null, $data['verificacao_id'] ?? null] as $candidate) {
            if (is_int($candidate) || (is_string($candidate) && trim($candidate) !== '')) {
                return trim((string) $candidate);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $data
     * @return array{0: bool|null, 1: bool|null, 2: float|null} rosto corresponde, aprovado pelo provedor, pontuação
     */
    private static function faceMatch(array $payload, array $data): array
    {
        foreach ([$data['face_match'] ?? null, $payload['face_match'] ?? null] as $raw) {
            if (is_bool($raw)) {
                return [$raw, null, null];
            }

            if (is_array($raw)) {
                $score = null;

                foreach (['similarity', 'similaridade', 'score', 'confidence'] as $key) {
                    if (is_numeric($raw[$key] ?? null)) {
                        $score = round((float) $raw[$key], 4);
                        break;
                    }
                }

                return [
                    self::bool($raw['match'] ?? $raw['verified'] ?? null),
                    self::bool($raw['approved'] ?? null),
                    $score,
                ];
            }
        }

        return [null, null, null];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $data
     */
    private static function reason(array $payload, array $data, string $status): ?string
    {
        if (! in_array($status, ['rejected', 'inconclusive', 'expired'], true)) {
            return null;
        }

        foreach ([$data['reason'] ?? null, $payload['reason'] ?? null, $payload['message'] ?? null, $payload['error'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return Str::limit(trim(strip_tags($candidate)), 300, '…');
            }
        }

        return null;
    }

    private static function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private static function text(mixed $value, bool $lower = true): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : ($lower ? strtolower($text) : $text);
    }
}
