<?php

namespace App\Integrations\Contracts;

/**
 * Fase 2/3 — sem implementação. Consulta de CNPJ (razão social, situação,
 * endereço) para preencher organizations.legal_name e emissão fiscal.
 */
interface CnpjLookupProvider
{
    /**
     * @return array{found: bool, provider: string, legal_name?: string, trade_name?: string, status?: string, address?: array<string, string>, details?: array<string, mixed>}
     */
    public function lookup(string $cnpj, ?string $correlationId = null): array;

    public function isConfigured(): bool;

    public function name(): string;
}
