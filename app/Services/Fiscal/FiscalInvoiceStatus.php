<?php

namespace App\Services\Fiscal;

use App\Models\FiscalInvoice;
use App\Models\Payment;

/**
 * Rótulo honesto da situação fiscal de um pagamento (Fase 2, onda D). Enquanto não houver
 * provedor real, todo pagamento pago mostra "não emitida — integração fiscal pendente"; o que o
 * simulador produz é dito como simulação, nunca como nota.
 */
final class FiscalInvoiceStatus
{
    public const NOT_ISSUED = 'Nota fiscal: não emitida — integração fiscal pendente';

    public const SIMULATED = 'Nota fiscal: simulada — nenhuma NFS-e foi emitida (sem validade fiscal)';

    public const PENDING = 'Nota fiscal: emissão sem confirmação — em análise pela equipe; não será reemitida automaticamente';

    /**
     * @return array{status: string, label: string}|null null para pagamento não pago
     */
    public static function forPayment(Payment $payment, ?FiscalInvoice $invoice = null): ?array
    {
        if (! $payment->isPaid()) {
            return null;
        }

        return match ($invoice?->status) {
            FiscalInvoice::STATUS_SIMULATED => ['status' => 'simulated', 'label' => self::SIMULATED],
            FiscalInvoice::STATUS_ISSUED => ['status' => 'issued', 'label' => 'Nota fiscal: NFS-e emitida'],
            FiscalInvoice::STATUS_CANCELED => ['status' => 'canceled', 'label' => 'Nota fiscal: NFS-e cancelada'],
            // Nada consulta nem reemite uma emissão inconclusiva (sem consulta por DPS no contrato
            // atual, docs §11): o rótulo diz o que acontece de fato — a equipe analisa.
            FiscalInvoice::STATUS_PENDING => ['status' => 'pending', 'label' => self::PENDING],
            FiscalInvoice::STATUS_FAILED => ['status' => 'failed', 'label' => 'Nota fiscal: não emitida — falha registrada para a equipe'],
            default => ['status' => 'not_issued', 'label' => self::NOT_ISSUED],
        };
    }
}
