<?php

namespace App\Integrations\Payments;

use App\Enums\PaymentEnvironment;
use App\Integrations\Dto\CheckoutPreference;
use App\Integrations\Dto\CheckoutPreferenceRequest;
use App\Integrations\Dto\GatewayPayment;
use App\Integrations\Payments\Dto\GatewayChargeback;
use App\Integrations\Payments\Dto\GatewayMerchantOrder;
use App\Integrations\Payments\Dto\GatewayPaymentMethod;
use App\Integrations\Payments\Dto\GatewayPaymentPage;
use App\Integrations\Payments\Dto\GatewayPaymentSummary;
use App\Integrations\Payments\Dto\GatewayRefund;
use App\Integrations\Payments\Exceptions\PaymentGatewayException;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * Dublê explícito do gateway, para desenvolvimento e testes. **Não é o Mercado Pago.**
 *
 * Tudo nele se identifica como falso, de propósito e em toda superfície:
 *
 * - `name()` devolve `fake`, então `payments.provider` grava `fake` e nenhum pagamento
 *   de dublê se confunde com um pagamento real na lista de cobrança;
 * - `isFake()` devolve `true`;
 * - o `init_point` aponta para o domínio reservado `.invalid` (RFC 2606), que nunca
 *   resolve — se alguém publicar isto por engano, o link falha em vez de fingir um
 *   checkout;
 * - `environment()` é sempre `sandbox`;
 * - `liveMode` é sempre `false`.
 *
 * Não faz nenhuma chamada de rede. Os pagamentos que ele "conhece" são apenas os que
 * foram programados por `pretendPayment()` — ele nunca inventa um pagamento aprovado.
 */
class FakePaymentGateway implements CheckoutProGateway
{
    public const NAME = 'fake';

    public const CHECKOUT_HOST = 'https://checkout-falso.assinavelox.invalid';

    /** @var array<string, CheckoutPreference> preferências por external_reference */
    private array $preferences = [];

    /** @var array<string, GatewayPayment> pagamentos por id do provedor */
    private array $payments = [];

    /** @var array<string, GatewayMerchantOrder> ordens por id */
    private array $merchantOrders = [];

    /** @var list<CheckoutPreferenceRequest> */
    private array $preferenceRequests = [];

    private ?PaymentGatewayException $nextFailure = null;

    // -- Fase 2, onda D ---------------------------------------------------------------

    /** @var array<string, list<GatewayRefund>> estornos por id do pagamento */
    private array $refunds = [];

    /** @var array<string, GatewayRefund> estornos por X-Idempotency-Key (idempotência do provedor) */
    private array $refundsByKey = [];

    /** @var list<array{payment: string, amount_cents: int|null, idempotency_key: string}> */
    private array $refundRequests = [];

    /** @var list<array{payment: string, idempotency_key: string}> */
    private array $cancelRequests = [];

    /** @var array<string, GatewayChargeback> */
    private array $chargebacks = [];

    /** @var list<GatewayPaymentMethod>|null */
    private ?array $paymentMethods = null;

    private ?PaymentGatewayException $failAfterApplying = null;

    private ?string $nextRefundStatus = null;

    private int $searchCalls = 0;

    public function name(): string
    {
        return self::NAME;
    }

    public function isFake(): bool
    {
        return true;
    }

    /**
     * O dublê está sempre "configurado" — ele não usa credencial nenhuma.
     */
    public function isConfigured(): bool
    {
        return true;
    }

    public function environment(): PaymentEnvironment
    {
        return PaymentEnvironment::Sandbox;
    }

    // -- Contrato ---------------------------------------------------------------------

    public function createCheckoutPreference(CheckoutPreferenceRequest $request): CheckoutPreference
    {
        $this->preferenceRequests[] = $request;

        $this->throwProgrammedFailure();

        $preference = new CheckoutPreference(
            preferenceId: 'fake-pref-'.Str::lower((string) Str::ulid()),
            checkoutUrl: self::CHECKOUT_HOST.'/preferencia/'.$request->externalReference,
            externalReference: $request->externalReference,
            liveMode: false,
            raw: ['fake' => true, 'amount_cents' => $request->amountCents, 'currency_id' => $request->currency],
        );

        $this->preferences[$request->externalReference] = $preference;

        return $preference;
    }

    public function findPreferenceByExternalReference(string $externalReference): ?CheckoutPreference
    {
        $this->throwProgrammedFailure();

        return $this->preferences[$externalReference] ?? null;
    }

    public function getPayment(string $providerPaymentId): GatewayPayment
    {
        $this->throwProgrammedFailure();

        return $this->payments[$providerPaymentId]
            ?? throw PaymentGatewayException::rejected('get_payment', 404, '2000');
    }

    public function getMerchantOrder(string $merchantOrderId): GatewayMerchantOrder
    {
        $this->throwProgrammedFailure();

        return $this->merchantOrders[$merchantOrderId]
            ?? throw PaymentGatewayException::rejected('get_merchant_order', 404);
    }

    // -- Fase 2, onda D: operações ampliadas -----------------------------------------

    public function refundPayment(string $providerPaymentId, ?int $amountCents, string $idempotencyKey, ?string $correlationId = null): GatewayRefund
    {
        $this->refundRequests[] = ['payment' => $providerPaymentId, 'amount_cents' => $amountCents, 'idempotency_key' => $idempotencyKey];

        // Falha ANTES de aplicar: o "provedor" não recebeu o pedido.
        $this->throwProgrammedFailure();

        // O provedor reconhece a mesma chave e devolve o mesmo estorno (não cria outro).
        if (isset($this->refundsByKey[$idempotencyKey])) {
            $this->throwAfterApplying();

            return $this->refundsByKey[$idempotencyKey];
        }

        $payment = $this->payments[$providerPaymentId]
            ?? throw PaymentGatewayException::rejected('create_refund', 404, '2000');

        if ($payment->status !== 'approved') {
            throw PaymentGatewayException::rejected('create_refund', 400, '2063');
        }

        $already = $this->refundedCents($providerPaymentId);
        $remaining = $payment->amountCents - $already;
        $amount = $amountCents ?? $remaining;

        if ($amount <= 0 || $amount > $remaining) {
            throw PaymentGatewayException::rejected('create_refund', 400, 'invalid_amount');
        }

        $status = $this->nextRefundStatus ?? 'approved';
        $this->nextRefundStatus = null;

        $refund = new GatewayRefund(
            refundId: 'fake-refund-'.Str::lower((string) Str::ulid()),
            providerPaymentId: $providerPaymentId,
            amountCents: $amount,
            status: $status,
            createdAt: new DateTimeImmutable,
        );

        $this->refunds[$providerPaymentId][] = $refund;
        $this->refundsByKey[$idempotencyKey] = $refund;

        if ($refund->isApproved()) {
            $total = $already + $amount;
            $full = $total >= $payment->amountCents;

            $this->replacePayment($payment, $full ? 'refunded' : 'approved', $full ? 'refunded' : 'partially_refunded', $total);
        }

        // Falha DEPOIS de aplicar: o provedor processou, mas a resposta se perdeu (timeout).
        $this->throwAfterApplying();

        return $refund;
    }

    public function listRefunds(string $providerPaymentId): array
    {
        $this->throwProgrammedFailure();

        return $this->refunds[$providerPaymentId] ?? [];
    }

    public function cancelPayment(string $providerPaymentId, string $idempotencyKey, ?string $correlationId = null): GatewayPayment
    {
        $this->cancelRequests[] = ['payment' => $providerPaymentId, 'idempotency_key' => $idempotencyKey];

        $this->throwProgrammedFailure();

        $payment = $this->payments[$providerPaymentId]
            ?? throw PaymentGatewayException::rejected('cancel_payment', 404, '2000');

        if (! in_array($payment->status, ['pending', 'in_process', 'authorized'], true)) {
            // Erro documentado quando o status inicial não permite cancelar.
            throw PaymentGatewayException::rejected('cancel_payment', 400, '2018');
        }

        $cancelled = $this->replacePayment($payment, 'cancelled', 'by_collector');

        $this->throwAfterApplying();

        return $cancelled;
    }

    public function getChargeback(string $chargebackId): GatewayChargeback
    {
        $this->throwProgrammedFailure();

        return $this->chargebacks[$chargebackId]
            ?? throw PaymentGatewayException::rejected('get_chargeback', 404);
    }

    public function searchPayments(DateTimeInterface $begin, DateTimeInterface $end, int $offset, int $limit): GatewayPaymentPage
    {
        $this->searchCalls++;

        $this->throwProgrammedFailure();

        $all = array_values(array_map(static fn (GatewayPayment $payment): GatewayPaymentSummary => new GatewayPaymentSummary(
            providerPaymentId: $payment->providerPaymentId,
            status: $payment->status,
            statusDetail: $payment->statusDetail,
            externalReference: $payment->externalReference,
            amountCents: $payment->amountCents,
            currency: $payment->currency,
            liveMode: $payment->liveMode,
            paymentTypeId: is_string($payment->raw['payment_type_id'] ?? null) ? $payment->raw['payment_type_id'] : null,
            lastUpdatedAt: new DateTimeImmutable,
        ), $this->payments));

        return new GatewayPaymentPage(
            results: array_slice($all, $offset, $limit),
            total: count($all),
            offset: $offset,
            limit: $limit,
        );
    }

    public function listPaymentMethods(): array
    {
        $this->throwProgrammedFailure();

        return $this->paymentMethods ?? [
            new GatewayPaymentMethod('pix', 'Pix (simulado)', 'bank_transfer', 'active'),
            new GatewayPaymentMethod('bolbradesco', 'Boleto (simulado)', 'ticket', 'active'),
            new GatewayPaymentMethod('visa', 'Visa (simulado)', 'credit_card', 'active'),
            new GatewayPaymentMethod('master', 'Mastercard (simulado)', 'credit_card', 'active'),
        ];
    }

    // -- Programação das operações ampliadas -------------------------------------------

    public function pretendChargeback(GatewayChargeback $chargeback): GatewayChargeback
    {
        return $this->chargebacks[$chargeback->chargebackId] = $chargeback;
    }

    /**
     * @param  list<GatewayPaymentMethod>  $methods
     */
    public function pretendPaymentMethods(array $methods): void
    {
        $this->paymentMethods = $methods;
    }

    /**
     * A próxima operação com efeito é APLICADA e, em seguida, falha com esta exceção — o caso
     * "o provedor processou, mas a resposta não chegou" (ambiguidade de timeout, T5).
     */
    public function failNextAfterApplying(PaymentGatewayException $exception): void
    {
        $this->failAfterApplying = $exception;
    }

    /**
     * Status do próximo estorno criado (padrão `approved`).
     */
    public function nextRefundWillBe(string $status): void
    {
        $this->nextRefundStatus = $status;
    }

    /**
     * @return list<array{payment: string, amount_cents: int|null, idempotency_key: string}>
     */
    public function refundRequests(): array
    {
        return $this->refundRequests;
    }

    /**
     * @return list<array{payment: string, idempotency_key: string}>
     */
    public function cancelRequests(): array
    {
        return $this->cancelRequests;
    }

    /**
     * @return list<GatewayRefund>
     */
    public function refundsFor(string $providerPaymentId): array
    {
        return $this->refunds[$providerPaymentId] ?? [];
    }

    public function searchCallCount(): int
    {
        return $this->searchCalls;
    }

    private function refundedCents(string $providerPaymentId): int
    {
        $total = 0;

        foreach ($this->refunds[$providerPaymentId] ?? [] as $refund) {
            if ($refund->isApproved()) {
                $total += $refund->amountCents;
            }
        }

        return $total;
    }

    private function replacePayment(GatewayPayment $payment, string $status, ?string $statusDetail, ?int $refundedCents = null): GatewayPayment
    {
        $raw = $payment->raw;
        $raw['status'] = $status;

        if ($refundedCents !== null) {
            $raw['transaction_amount_refunded'] = round($refundedCents / 100, 2);
        }

        return $this->payments[$payment->providerPaymentId] = new GatewayPayment(
            providerPaymentId: $payment->providerPaymentId,
            status: $status,
            statusDetail: $statusDetail,
            externalReference: $payment->externalReference,
            amountCents: $payment->amountCents,
            currency: $payment->currency,
            liveMode: $payment->liveMode,
            paymentMethodId: $payment->paymentMethodId,
            payerEmailMasked: $payment->payerEmailMasked,
            approvedAt: $payment->approvedAt,
            raw: $raw,
        );
    }

    private function throwAfterApplying(): void
    {
        if ($this->failAfterApplying !== null) {
            $failure = $this->failAfterApplying;
            $this->failAfterApplying = null;

            throw $failure;
        }
    }

    // -- Programação (desenvolvimento e testes) ---------------------------------------

    /**
     * Programa o pagamento que `getPayment()` vai devolver.
     */
    public function pretendPayment(
        string $providerPaymentId,
        string $externalReference,
        int $amountCents,
        string $status = 'approved',
        ?string $statusDetail = 'accredited',
        string $currency = 'BRL',
        ?string $paymentMethodId = 'pix',
        ?DateTimeImmutable $approvedAt = null,
        bool $liveMode = false,
    ): GatewayPayment {
        $payment = new GatewayPayment(
            providerPaymentId: $providerPaymentId,
            status: $status,
            statusDetail: $statusDetail,
            externalReference: $externalReference,
            amountCents: $amountCents,
            currency: $currency,
            liveMode: $liveMode,
            paymentMethodId: $paymentMethodId,
            payerEmailMasked: 'p****@exemplo.com',
            approvedAt: $status === 'approved' ? ($approvedAt ?? new DateTimeImmutable) : null,
            raw: [
                'fake' => true,
                'status' => $status,
                // Tipo documentado da família do meio (Pix = bank_transfer, boleto = ticket).
                'payment_type_id' => match ($paymentMethodId) {
                    'pix' => 'bank_transfer',
                    'bolbradesco' => 'ticket',
                    null => null,
                    default => 'credit_card',
                },
            ],
        );

        $this->payments[$providerPaymentId] = $payment;

        return $payment;
    }

    public function pretendMerchantOrder(GatewayMerchantOrder $order): GatewayMerchantOrder
    {
        $this->merchantOrders[$order->merchantOrderId] = $order;

        return $order;
    }

    /**
     * A próxima chamada (qualquer uma) falha com esta exceção; depois volta ao normal.
     * Serve para exercitar a ambiguidade de timeout sem rede.
     */
    public function failNextWith(PaymentGatewayException $exception): void
    {
        $this->nextFailure = $exception;
    }

    /**
     * @return list<CheckoutPreferenceRequest>
     */
    public function preferenceRequests(): array
    {
        return $this->preferenceRequests;
    }

    public function preferenceRequestCount(): int
    {
        return count($this->preferenceRequests);
    }

    /**
     * Esquece uma preferência já criada — usado para simular o caso em que o provedor
     * NÃO recebeu a criação que ficou inconclusiva.
     */
    public function forgetPreference(string $externalReference): void
    {
        unset($this->preferences[$externalReference]);
    }

    private function throwProgrammedFailure(): void
    {
        if ($this->nextFailure !== null) {
            $failure = $this->nextFailure;
            $this->nextFailure = null;

            throw $failure;
        }
    }
}
