<?php

namespace App\Integrations\Contracts;

use App\Integrations\Contracts\Exceptions\ProviderDisabledException;

/**
 * Verificação de domínio de envio no serviço de e-mail do proprietário (Fase 2 §2.8,
 * classe B). A API desse serviço não tem documentação: só existem o simulador
 * (App\Integrations\Email\FakeSenderDomainVerifier) e o adaptador de produção desabilitado
 * (App\Integrations\Email\HttpSenderDomainVerifier).
 *
 * Os registros DNS devolvidos são os que o PROVEDOR exige (DKIM, SPF, Return-Path,
 * verificação de posse). Nada aqui presume quais são.
 */
interface SenderDomainVerifier
{
    /**
     * Cadastra o domínio no provedor e devolve os registros DNS esperados.
     *
     * @return array{provider_domain_id: string|null, records: list<array{type: string, host: string, value: string, purpose: string}>}
     *
     * @throws ProviderDisabledException
     */
    public function register(string $domain, string $verificationToken, ?string $correlationId = null): array;

    /**
     * Consulta a situação do domínio no provedor.
     *
     * @return array{status: 'pending'|'verified'|'failed', checked_at: string, reason?: string|null}
     *
     * @throws ProviderDisabledException
     */
    public function check(string $domain, ?string $providerDomainId, ?string $correlationId = null): array;

    public function isConfigured(): bool;

    public function isSimulated(): bool;

    public function name(): string;

    /**
     * @return list<string>
     */
    public function missingRequirements(): array;
}
