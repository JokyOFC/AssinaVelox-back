<?php

namespace App\Services\Billing;

use App\Models\Organization;
use App\Models\Payment;
use App\Support\TaxId;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Recibo interno de um pagamento aprovado, em PDF (Blade → DOMPDF).
 *
 * **Não é documento fiscal.** O aviso está no topo e no rodapé do documento, com todas
 * as letras: este PDF comprova que o pagamento foi recebido pela AssinaVelox e não
 * substitui a nota fiscal de serviço. A emissão de NFS-e é Fase 2 (RECONCILIACAO Q21) e
 * a pesquisa oficial confirma que o Mercado Pago **não** expõe API pública de NFS-e
 * (docs/integracoes/mercado-pago.md §9).
 *
 * O recibo não imprime nada de cartão: o Checkout Pro não nos entrega número, bandeira
 * completa nem código de segurança, e nada aqui inventaria isso. O que aparece é o meio
 * de pagamento em texto ("Pix", "Cartão de crédito") e o identificador do pagamento no
 * provedor.
 */
class PaymentReceipt
{
    public function __construct(private readonly BillingSettings $settings) {}

    public const NOT_A_FISCAL_DOCUMENT = 'Este recibo NÃO é documento fiscal e não substitui a nota fiscal de serviço (NFS-e).';

    /**
     * @return array<string, mixed>
     */
    public function data(Payment $payment, Organization $organization): array
    {
        $payment->loadMissing('plan');
        $operator = $this->settings->operator();

        return [
            'number' => $payment->ulid,
            'issued_at' => ($payment->paid_at ?? $payment->created_at)?->timezone($organization->timezone),
            'timezone' => $organization->timezone,
            'operator' => [
                'name' => $operator['name'],
                'legal_name' => $operator['legal_name'],
                'tax_id' => TaxId::format($operator['tax_id']),
            ],
            'customer' => $this->customer($organization),
            'plan' => [
                'name' => $payment->plan->name ?? '—',
                'code' => $payment->plan->code ?? null,
                'is_sandbox' => (bool) ($payment->plan->is_sandbox ?? false),
            ],
            'amount' => [
                'cents' => (int) $payment->amount_cents,
                'currency' => $payment->currency,
                'formatted' => Payment::formatBrl((int) $payment->amount_cents),
            ],
            'method' => PaymentMethods::label($payment->payment_method_id),
            'provider' => $payment->provider,
            'provider_payment_id' => $payment->provider_payment_id,
            'environment' => $payment->environment,
            'disclaimer' => self::NOT_A_FISCAL_DOCUMENT,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function customer(Organization $organization): array
    {
        $profile = BillingProfile::for($organization);

        if ($profile !== null) {
            return [
                'name' => $profile['legal_name'],
                'document' => TaxId::format($profile['document_number']),
                'address' => $profile['address_line'].' · '.$profile['city'].'/'.$profile['state'].' · '.$this->postalCode($profile['postal_code']),
                'email' => $profile['email'],
            ];
        }

        return [
            'name' => $organization->legal_name ?? $organization->name,
            'document' => TaxId::format($organization->tax_id),
            'address' => null,
            'email' => null,
        ];
    }

    private function postalCode(string $digits): string
    {
        return strlen($digits) === 8 ? substr($digits, 0, 5).'-'.substr($digits, 5) : $digits;
    }

    public function filename(Payment $payment): string
    {
        return 'recibo-assinavelox-'.strtolower($payment->ulid).'.pdf';
    }

    /**
     * Bytes do PDF. Sem rede (DOMPDF com `isRemoteEnabled` desligado).
     */
    public function render(Payment $payment, Organization $organization): string
    {
        return Pdf::loadView('billing.receipt', ['receipt' => $this->data($payment, $organization)])
            ->setPaper('a4')
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('isRemoteEnabled', false)
            ->setOption('isPhpEnabled', false)
            ->setOption('isJavascriptEnabled', false)
            ->output();
    }
}
