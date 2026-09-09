<?php

namespace App\Integrations\Payments\Dto;

/**
 * Ordem comercial consultada em `GET /merchant_orders/{id}`.
 *
 * O Checkout Pro agrega vários pagamentos da mesma preferência numa ordem (um boleto
 * expirado + um cartão aprovado, por exemplo). A regra oficial é somar os pagamentos
 * `approved` e liberar quando cobrirem `total_amount`
 * (docs/integracoes/mercado-pago.md §5.2).
 *
 * Valores sempre em centavos inteiros com a moeda explícita.
 */
final readonly class GatewayMerchantOrder
{
    /**
     * @param  list<array{id: string, status: string, status_detail: string|null, amount_cents: int}>  $payments
     * @param  array<string, mixed>  $raw  resposta sem dados pessoais do pagador
     */
    public function __construct(
        public string $merchantOrderId,
        public ?string $preferenceId,
        public ?string $externalReference,
        public string $status,
        public ?string $orderStatus,
        public int $totalAmountCents,
        public int $paidAmountCents,
        public string $currency = 'BRL',
        public array $payments = [],
        public array $raw = [],
    ) {}

    /**
     * Soma dos pagamentos `approved` desta ordem, em centavos.
     */
    public function approvedAmountCents(): int
    {
        $total = 0;

        foreach ($this->payments as $payment) {
            if ($payment['status'] === 'approved') {
                $total += $payment['amount_cents'];
            }
        }

        return $total;
    }

    public function isFullyPaid(): bool
    {
        return $this->totalAmountCents > 0 && $this->approvedAmountCents() >= $this->totalAmountCents;
    }
}
