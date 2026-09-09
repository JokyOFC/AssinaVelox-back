<?php

namespace App\Integrations\Payments;

use App\Enums\PaymentEnvironment;
use App\Integrations\Dto\CheckoutPreference;
use App\Integrations\Dto\CheckoutPreferenceRequest;
use App\Integrations\Dto\GatewayPayment;
use App\Integrations\Payments\Dto\GatewayMerchantOrder;
use App\Integrations\Payments\Exceptions\PaymentGatewayException;
use DateTimeImmutable;
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
            raw: ['fake' => true, 'status' => $status],
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
