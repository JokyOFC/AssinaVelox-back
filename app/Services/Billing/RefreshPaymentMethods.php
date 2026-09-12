<?php

namespace App\Services\Billing;

use App\Integrations\Payments\CheckoutProGateway;
use App\Integrations\Payments\Dto\GatewayPaymentMethod;
use App\Integrations\Payments\Exceptions\PaymentGatewayException;
use App\Models\PaymentMethodCheck;
use App\Models\User;
use App\Services\Billing\Exceptions\BillingActionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Consulta e registra os meios ativos da conta vendedora (GET /v1/payment_methods) — Fase 2,
 * onda D. É o que `PaymentMethodPolicy` usa para não oferecer uma família que a conta não tem
 * (por exemplo, Pix sem chave cadastrada: o comportamento do Checkout Pro nesse caso é NÃO
 * CONFIRMADO, então a regra operacional é conferir o `status` de `pix` aqui).
 */
class RefreshPaymentMethods
{
    public function __construct(
        private readonly CheckoutProGateway $gateway,
        private readonly BillingSettings $settings,
    ) {}

    /**
     * @throws BillingActionException
     */
    public function handle(?User $actor = null): PaymentMethodCheck
    {
        if (! $this->settings->extendedPayments()) {
            throw BillingActionException::featureDisabled();
        }

        $attributes = [
            'provider' => $this->gateway->name(),
            'environment' => $this->gateway->environment()->value,
            'checked_by_user_id' => $actor?->getKey(),
            'correlation_id' => (string) Str::ulid(),
            'checked_at' => Carbon::now(),
        ];

        try {
            $methods = $this->gateway->listPaymentMethods();
        } catch (PaymentGatewayException $exception) {
            return PaymentMethodCheck::query()->create([
                ...$attributes,
                'status' => PaymentMethodCheck::STATUS_FAILED,
                'error' => Str::limit($exception->errorCode.($exception->status !== null ? ':'.$exception->status : ''), 191, ''),
            ]);
        }

        return PaymentMethodCheck::query()->create([
            ...$attributes,
            'status' => PaymentMethodCheck::STATUS_OK,
            'methods' => array_map(static fn (GatewayPaymentMethod $method): array => $method->toArray(), $methods),
        ]);
    }
}
