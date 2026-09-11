<?php

namespace App\Integrations\Contracts;

/**
 * Emissão de nota fiscal de serviço (NFS-e) para pagamentos aprovados (roadmap §2.21,
 * classe B: emissão real bloqueada por dados, parecer e certificado da operadora).
 *
 * Hoje só existe o simulador identificado (App\Integrations\Fiscal\FakeFiscalInvoiceProvider),
 * que devolve `status = simulated` e NUNCA número, código de verificação, PDF ou XML: ele não
 * emite nota. O recibo interno da cobrança continua dizendo que não é documento fiscal.
 */
interface FiscalInvoiceProvider
{
    /**
     * @param  array<string, mixed>  $invoice  dados fiscais (tomador, serviço, valor em centavos, referência do pagamento)
     * @return array{invoice_id: string, provider: string, status: 'issued'|'processing'|'rejected'|'simulated', number?: string, verification_code?: string, pdf_url?: string, xml_url?: string, details?: array<string, mixed>}
     */
    public function issue(array $invoice, ?string $correlationId = null): array;

    /**
     * @return array{invoice_id: string, status: 'cancelled'|'processing'|'rejected'|'simulated', details?: array<string, mixed>}
     */
    public function cancel(string $invoiceId, string $reason, ?string $correlationId = null): array;

    public function isConfigured(): bool;

    public function isSimulated(): bool;

    public function name(): string;
}
