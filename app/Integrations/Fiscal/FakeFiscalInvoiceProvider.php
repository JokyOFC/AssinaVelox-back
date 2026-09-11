<?php

namespace App\Integrations\Fiscal;

use App\Integrations\Contracts\FiscalInvoiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Simulador IDENTIFICADO de NFS-e (contrato reservado, roadmap §2.21). **Não emite nota.**
 *
 * `issue()` e `cancel()` devolvem `status = simulated`, sem número, código de verificação,
 * PDF ou XML — nada que possa ser confundido com documento fiscal. O log registra só a
 * referência do pagamento, nunca os dados do tomador.
 */
final class FakeFiscalInvoiceProvider implements FiscalInvoiceProvider
{
    public const NAME = 'nfse_simulada';

    public const NOTICE = 'Simulador: nenhuma NFS-e foi emitida. Isto não é documento fiscal.';

    public function __construct(private readonly Repository $config) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function isSimulated(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return (bool) $this->config->get('assinavelox.channels.allow_simulated', false);
    }

    public function issue(array $invoice, ?string $correlationId = null): array
    {
        $id = 'simulada-'.Str::lower((string) Str::ulid());

        Log::warning('[SIMULADO] NFS-e NÃO emitida — FakeFiscalInvoiceProvider.', [
            'provider' => self::NAME,
            'invoice_id' => $id,
            'payment_reference' => is_scalar($invoice['payment_reference'] ?? null) ? (string) $invoice['payment_reference'] : null,
            'correlation_id' => $correlationId,
        ]);

        return [
            'invoice_id' => $id,
            'provider' => self::NAME,
            'status' => 'simulated',
            'details' => ['simulated' => true, 'message' => self::NOTICE],
        ];
    }

    public function cancel(string $invoiceId, string $reason, ?string $correlationId = null): array
    {
        Log::warning('[SIMULADO] Cancelamento de NFS-e simulado — nada foi cancelado.', [
            'provider' => self::NAME,
            'invoice_id' => $invoiceId,
            'correlation_id' => $correlationId,
        ]);

        return [
            'invoice_id' => $invoiceId,
            'status' => 'simulated',
            'details' => ['simulated' => true, 'message' => 'Simulador: nenhuma NFS-e existe para ser cancelada.'],
        ];
    }
}
