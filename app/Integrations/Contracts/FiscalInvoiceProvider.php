<?php

namespace App\Integrations\Contracts;

/**
 * Fase 2/3 — sem implementação. Emissão de nota fiscal de serviço (NFS-e) para
 * pagamentos aprovados de assinaturas.
 */
interface FiscalInvoiceProvider
{
    /**
     * @param  array<string, mixed>  $invoice  dados fiscais (tomador, serviço, valor em centavos, referência do pagamento)
     * @return array{invoice_id: string, provider: string, status: 'issued'|'processing'|'rejected', number?: string, verification_code?: string, pdf_url?: string, xml_url?: string, details?: array<string, mixed>}
     */
    public function issue(array $invoice, ?string $correlationId = null): array;

    /**
     * @return array{invoice_id: string, status: 'cancelled'|'processing'|'rejected', details?: array<string, mixed>}
     */
    public function cancel(string $invoiceId, string $reason, ?string $correlationId = null): array;

    public function isConfigured(): bool;

    public function name(): string;
}
