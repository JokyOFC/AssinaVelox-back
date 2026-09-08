<?php

namespace App\Integrations\Contracts;

/**
 * Fase 2/3 — sem implementação. Verificação de identidade do signatário
 * (documento + selfie/liveness) como método de autenticação adicional.
 *
 * O resultado alimenta signature_acceptances.auth_method e a página de
 * evidências; o provedor nunca recebe o conteúdo do documento assinado.
 */
interface IdentityVerificationProvider
{
    /**
     * Inicia uma verificação e devolve o que o front precisa (URL/sessão).
     *
     * @param  array<string, mixed>  $options
     * @return array{verification_id: string, provider: string, redirect_url?: string, expires_at?: string}
     */
    public function start(string $recipientUlid, array $options = [], ?string $correlationId = null): array;

    /**
     * @return array{verification_id: string, status: 'pending'|'approved'|'rejected'|'inconclusive'|'expired', provider: string, checked_at: string, details?: array<string, mixed>}
     */
    public function result(string $verificationId, ?string $correlationId = null): array;

    public function isConfigured(): bool;

    public function name(): string;
}
