<?php

namespace App\Integrations\Payments\Dto;

/**
 * Meio de pagamento da conta, de GET /v1/payment_methods. `status` documentado: `active`,
 * `deactive`, `temporally_deactive`. `paymentTypeId`: `bank_transfer` (Pix), `ticket` (boleto),
 * `credit_card`, `debit_card`, `prepaid_card`, `account_money`…
 */
final readonly class GatewayPaymentMethod
{
    public function __construct(
        public string $id,
        public ?string $name,
        public ?string $paymentTypeId,
        public ?string $status,
    ) {}

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * @return array{id: string, name: string|null, payment_type_id: string|null, status: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'payment_type_id' => $this->paymentTypeId,
            'status' => $this->status,
        ];
    }
}
