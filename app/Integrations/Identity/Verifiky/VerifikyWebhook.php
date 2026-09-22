<?php

namespace App\Integrations\Identity\Verifiky;

/**
 * Webhook da Verifiky (`POST /webhooks/verifiky`): autenticidade e leitura do corpo.
 *
 * Assinatura: cabeçalho `X-Verifiky-Signature` = HMAC-SHA256, em hexadecimal, do corpo CRU com
 * `VERIFIKY_WEBHOOK_SECRET` — o mesmo esquema do metta-bank (VerifikySignatureValidator).
 *
 * Diferença deliberada: lá, sem segredo configurado, qualquer POST era aceito. Aqui, sem
 * segredo, NENHUM é — um webhook aberto deixaria qualquer pessoa aprovar a verificação de
 * qualquer participante. É a mesma regra do webhook do Mercado Pago.
 *
 * Só o evento `verification.completed` interessa (webhooks antigos, sem `event`, também são
 * tratados como ele). `background_check.completed` é o fluxo de antecedentes do banco e não
 * existe aqui: é reconhecido e ignorado.
 */
final class VerifikyWebhook
{
    public const SIGNATURE_HEADER = 'X-Verifiky-Signature';

    public const EVENT_COMPLETED = 'verification.completed';

    public function isConfigured(): bool
    {
        return $this->secret() !== '';
    }

    public function isAuthentic(string $rawBody, ?string $signature): bool
    {
        $signature = strtolower(trim((string) $signature));

        if (! $this->isConfigured() || $signature === '') {
            return false;
        }

        // Alguns emissores prefixam o algoritmo ("sha256=…").
        $signature = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;

        return hash_equals(hash_hmac('sha256', $rawBody, $this->secret()), $signature);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function isVerificationCompleted(array $payload): bool
    {
        $event = is_string($payload['event'] ?? null) ? trim($payload['event']) : '';

        return $event === '' || $event === self::EVENT_COMPLETED;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: 'pending'|'approved'|'rejected'|'inconclusive'|'expired', verification_id: string|null, reference: string|null, details: array<string, mixed>}
     */
    public function result(array $payload): array
    {
        return VerifikyResultMapper::map($payload);
    }

    private function secret(): string
    {
        return trim((string) config('assinavelox.identity_verification.verifiky.webhook_secret', ''));
    }
}
