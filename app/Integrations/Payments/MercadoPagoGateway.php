<?php

namespace App\Integrations\Payments;

use App\Enums\PaymentEnvironment;
use App\Integrations\Dto\CheckoutPreference;
use App\Integrations\Dto\CheckoutPreferenceRequest;
use App\Integrations\Dto\GatewayPayment;
use App\Integrations\Payments\Dto\GatewayMerchantOrder;
use App\Integrations\Payments\Exceptions\PaymentGatewayException;
use App\Services\Billing\BillingSettings;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MercadoPago\MercadoPagoConfig;
use Throwable;

/**
 * Adaptador do Mercado Pago (Checkout Pro) — arquitetura §8.
 *
 * ## Por que o HTTP Client do Laravel e não o SDK oficial para as chamadas
 *
 * O `mercadopago/dx-php` 3.16 está instalado e é usado aqui para o que ele faz melhor:
 * a **validação da assinatura do webhook** (`MercadoPago\Webhook\WebhookSignatureValidator`,
 * função pura, sem rede) e as constantes canônicas `MercadoPagoConfig::$BASE_URL` e
 * `$CURRENT_VERSION`. As chamadas HTTP, porém, vão pelo cliente do Laravel por três
 * motivos concretos:
 *
 * 1. **Estado global.** O SDK guarda o access token em propriedade estática
 *    (`MercadoPagoConfig::setAccessToken`). Com a exigência de nunca misturar sandbox e
 *    produção, um estado mutável compartilhado entre requisições é risco desnecessário —
 *    aqui o token viaja no cabeçalho de cada chamada e some com ela.
 * 2. **Testabilidade.** O SDK monta o próprio cliente cURL; não há como fingi-lo. Com
 *    `Http::fake()` conseguimos exercitar timeout, 5xx, repetição idempotente e
 *    ambiguidade de resposta — que é justamente o que precisa de teste.
 * 3. **Fidelidade.** Os três endpoints usados estão documentados e conferidos em
 *    docs/integracoes/mercado-pago.md §2, §4.6 e §5: `POST /checkout/preferences`,
 *    `GET /v1/payments/{id}` e `GET /merchant_orders/{id}` — todos com
 *    `Authorization: Bearer <access token>`. Nada foi inventado.
 *
 * ## Repetição idempotente e ambiguidade de timeout
 *
 * Só repetimos erro de conexão, 429 e 5xx, sempre com a MESMA `X-Idempotency-Key`
 * (derivada do nosso `external_reference`), para que uma repetição não crie um segundo
 * recurso. Esgotadas as tentativas sem resposta conclusiva, lançamos
 * `PaymentGatewayException::inconclusive()` — o chamador **consulta antes de recriar**
 * (ver `App\Services\Billing\StartCheckout`). Uma resposta inconclusiva nunca vira
 * sucesso.
 *
 * Observação de fidelidade: `X-Idempotency-Key` é **obrigatório** em `POST /v1/payments`
 * e em refunds; em `POST /checkout/preferences` a referência não o lista (marcado como
 * NÃO CONFIRMADO na pesquisa). Enviamos assim mesmo porque é o que o SDK oficial faz em
 * todo POST/PUT/PATCH — é seguro e não inventa campo de corpo.
 */
class MercadoPagoGateway implements CheckoutProGateway
{
    public const NAME = 'mercadopago';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly BillingSettings $settings,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function isFake(): bool
    {
        return false;
    }

    public function isConfigured(): bool
    {
        return $this->settings->isConfigured();
    }

    public function environment(): PaymentEnvironment
    {
        return $this->settings->environment();
    }

    // -- Preferência do Checkout Pro -------------------------------------------------

    public function createCheckoutPreference(CheckoutPreferenceRequest $request): CheckoutPreference
    {
        $this->assertConfigured();

        $correlationId = $request->correlationId ?? (string) Str::ulid();

        $response = $this->send(
            operation: 'create_preference',
            correlationId: $correlationId,
            // Chave derivada do nosso external_reference: uma repetição da MESMA criação
            // carrega a MESMA chave; uma criação nova carrega outra.
            idempotencyKey: 'pref-'.$request->externalReference,
            callback: fn (PendingRequest $client): Response => $client->post(
                '/checkout/preferences',
                $this->preferenceBody($request),
            ),
        );

        return $this->toCheckoutPreference($response->json(), $request->externalReference, $correlationId);
    }

    public function findPreferenceByExternalReference(string $externalReference): ?CheckoutPreference
    {
        $this->assertConfigured();

        $correlationId = (string) Str::ulid();

        $response = $this->send(
            operation: 'search_preference',
            correlationId: $correlationId,
            idempotencyKey: null,
            callback: fn (PendingRequest $client): Response => $client->get('/checkout/preferences/search', [
                'external_reference' => $externalReference,
            ]),
        );

        $payload = $response->json();
        $results = is_array($payload) && is_array($payload['elements'] ?? null) ? $payload['elements'] : [];

        foreach ($results as $element) {
            if (! is_array($element)) {
                continue;
            }

            if ((string) ($element['external_reference'] ?? '') !== $externalReference) {
                continue;
            }

            // A busca devolve um resumo; `init_point` só vem no GET por id.
            $id = (string) ($element['id'] ?? '');

            if ($id === '') {
                continue;
            }

            return $this->getPreference($id, $externalReference);
        }

        return null;
    }

    /**
     * `GET /checkout/preferences/{id}`.
     *
     * @throws PaymentGatewayException
     */
    public function getPreference(string $preferenceId, ?string $expectedExternalReference = null): CheckoutPreference
    {
        $this->assertConfigured();

        $correlationId = (string) Str::ulid();

        $response = $this->send(
            operation: 'get_preference',
            correlationId: $correlationId,
            idempotencyKey: null,
            callback: fn (PendingRequest $client): Response => $client->get('/checkout/preferences/'.rawurlencode($preferenceId)),
        );

        return $this->toCheckoutPreference($response->json(), $expectedExternalReference, $correlationId);
    }

    // -- Consultas -------------------------------------------------------------------

    public function getPayment(string $providerPaymentId): GatewayPayment
    {
        $this->assertConfigured();

        $correlationId = (string) Str::ulid();

        $response = $this->send(
            operation: 'get_payment',
            correlationId: $correlationId,
            idempotencyKey: null,
            callback: fn (PendingRequest $client): Response => $client->get('/v1/payments/'.rawurlencode($providerPaymentId)),
        );

        $payload = $response->json();

        if (! is_array($payload) || ! isset($payload['id'])) {
            throw PaymentGatewayException::malformedResponse('get_payment', 'sem campo id', $correlationId);
        }

        return new GatewayPayment(
            providerPaymentId: (string) $payload['id'],
            status: (string) ($payload['status'] ?? 'pending'),
            statusDetail: isset($payload['status_detail']) ? (string) $payload['status_detail'] : null,
            externalReference: isset($payload['external_reference']) ? (string) $payload['external_reference'] : null,
            amountCents: self::toCents($payload['transaction_amount'] ?? 0),
            currency: (string) ($payload['currency_id'] ?? 'BRL'),
            liveMode: (bool) ($payload['live_mode'] ?? false),
            paymentMethodId: isset($payload['payment_method_id']) ? (string) $payload['payment_method_id'] : null,
            payerEmailMasked: self::maskEmail($payload['payer']['email'] ?? null),
            approvedAt: self::toDate($payload['date_approved'] ?? null),
            raw: self::scrub($payload),
        );
    }

    public function getMerchantOrder(string $merchantOrderId): GatewayMerchantOrder
    {
        $this->assertConfigured();

        $correlationId = (string) Str::ulid();

        $response = $this->send(
            operation: 'get_merchant_order',
            correlationId: $correlationId,
            idempotencyKey: null,
            callback: fn (PendingRequest $client): Response => $client->get('/merchant_orders/'.rawurlencode($merchantOrderId)),
        );

        $payload = $response->json();

        if (! is_array($payload) || ! isset($payload['id'])) {
            throw PaymentGatewayException::malformedResponse('get_merchant_order', 'sem campo id', $correlationId);
        }

        $payments = [];
        $currency = 'BRL';

        foreach ((array) ($payload['payments'] ?? []) as $payment) {
            if (! is_array($payment)) {
                continue;
            }

            $currency = (string) ($payment['currency_id'] ?? $currency);

            $payments[] = [
                'id' => (string) ($payment['id'] ?? ''),
                'status' => (string) ($payment['status'] ?? 'pending'),
                'status_detail' => isset($payment['status_detail']) ? (string) $payment['status_detail'] : null,
                'amount_cents' => self::toCents($payment['transaction_amount'] ?? 0),
            ];
        }

        return new GatewayMerchantOrder(
            merchantOrderId: (string) $payload['id'],
            preferenceId: isset($payload['preference_id']) ? (string) $payload['preference_id'] : null,
            externalReference: isset($payload['external_reference']) ? (string) $payload['external_reference'] : null,
            status: (string) ($payload['status'] ?? 'opened'),
            orderStatus: isset($payload['order_status']) ? (string) $payload['order_status'] : null,
            totalAmountCents: self::toCents($payload['total_amount'] ?? 0),
            paidAmountCents: self::toCents($payload['paid_amount'] ?? 0),
            currency: $currency,
            payments: $payments,
            raw: self::scrub($payload),
        );
    }

    // -- Corpo da preferência ---------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function preferenceBody(CheckoutPreferenceRequest $request): array
    {
        $body = [
            'items' => [[
                'id' => $request->externalReference,
                'title' => $request->title,
                'description' => $request->description ?? $request->title,
                'quantity' => $request->quantity,
                'currency_id' => $request->currency,
                // A API recebe o preço em unidades monetárias; a fonte da verdade do
                // nosso lado é sempre o inteiro em centavos.
                'unit_price' => round($request->amountCents / 100, 2),
            ]],
            'back_urls' => [
                'success' => $request->successUrl,
                'pending' => $request->pendingUrl,
                'failure' => $request->failureUrl,
            ],
            // Só redireciona sozinho no aprovado com cartão; para meios offline o retorno
            // é sempre `pending` — e nenhum dos dois ativa plano.
            'auto_return' => 'approved',
            'external_reference' => $request->externalReference,
            'binary_mode' => $this->settings->binaryMode(),
        ];

        if ($request->notificationUrl !== null) {
            $body['notification_url'] = $request->notificationUrl;
        }

        if ($request->payerEmail !== null) {
            $body['payer'] = ['email' => $request->payerEmail];
        }

        $descriptor = $request->statementDescriptor ?? $this->settings->statementDescriptor();

        if ($descriptor !== null) {
            $body['statement_descriptor'] = mb_substr($descriptor, 0, 13);
        }

        if ($request->expiresAt !== null) {
            $body['expires'] = true;
            $body['expiration_date_from'] = Carbon::now()->toIso8601String();
            $body['expiration_date_to'] = Carbon::instance($request->expiresAt)->toIso8601String();
        }

        $paymentMethods = [];
        $excluded = $this->settings->excludedPaymentTypes();

        if ($excluded !== []) {
            $paymentMethods['excluded_payment_types'] = array_map(
                static fn (string $type): array => ['id' => $type],
                $excluded,
            );
        }

        $installments = $this->settings->installments();

        if ($installments !== null) {
            $paymentMethods['installments'] = $installments;
        }

        if ($paymentMethods !== []) {
            $body['payment_methods'] = $paymentMethods;
        }

        if ($request->metadata !== []) {
            $body['metadata'] = $request->metadata;
        }

        return $body;
    }

    /**
     * @param  mixed  $payload
     */
    private function toCheckoutPreference($payload, ?string $externalReference, string $correlationId): CheckoutPreference
    {
        if (! is_array($payload) || ! isset($payload['id'])) {
            throw PaymentGatewayException::malformedResponse('preference', 'sem campo id', $correlationId);
        }

        // `sandbox_init_point` é explicitamente desaconselhado pela documentação: mesmo
        // em teste o redirecionamento é pelo `init_point`.
        $initPoint = (string) ($payload['init_point'] ?? '');

        if ($initPoint === '') {
            throw PaymentGatewayException::malformedResponse('preference', 'sem init_point', $correlationId);
        }

        return new CheckoutPreference(
            preferenceId: (string) $payload['id'],
            checkoutUrl: $initPoint,
            externalReference: (string) ($payload['external_reference'] ?? $externalReference ?? ''),
            liveMode: (bool) ($payload['live_mode'] ?? false),
            raw: self::scrub($payload),
        );
    }

    // -- Transporte -------------------------------------------------------------------

    /**
     * @param  callable(PendingRequest): Response  $callback
     *
     * @throws PaymentGatewayException
     */
    private function send(string $operation, string $correlationId, ?string $idempotencyKey, callable $callback): Response
    {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'AssinaVelox/1.0 (MercadoPago DX-PHP SDK/'.MercadoPagoConfig::$CURRENT_VERSION.')',
            // Identificador de correlação nosso, devolvido nos logs e nas exceções.
            'X-Correlation-Id' => $correlationId,
        ];

        if ($idempotencyKey !== null) {
            $headers['X-Idempotency-Key'] = $idempotencyKey;
        }

        $client = $this->http
            ->baseUrl(MercadoPagoConfig::$BASE_URL)
            ->withToken((string) $this->settings->accessToken())
            ->withHeaders($headers)
            ->asJson()
            ->connectTimeout($this->settings->connectTimeoutSeconds())
            ->timeout($this->settings->timeoutSeconds())
            ->retry(
                $this->settings->retries() + 1,
                $this->settings->retryDelayMs(),
                // Só repete o que é seguro repetir com a MESMA chave de idempotência:
                // erro de conexão, 429 e 5xx. Um 4xx é resposta definitiva do provedor e
                // repetir só multiplicaria o erro.
                static function (Throwable $exception): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if ($exception instanceof RequestException) {
                        $status = $exception->response->status();

                        return $status === 429 || $status >= 500;
                    }

                    return false;
                },
                throw: false,
            );

        try {
            $response = $callback($client);
        } catch (ConnectionException $exception) {
            // Timeout/conexão: a requisição pode ter chegado. NUNCA tratar como falha
            // definitiva nem recriar às cegas.
            Log::warning('mercadopago.request.inconclusive', [
                'operation' => $operation,
                'correlation_id' => $correlationId,
                'reason' => 'connection',
            ]);

            throw PaymentGatewayException::inconclusive($operation, $correlationId);
        }

        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();

        Log::warning('mercadopago.request.failed', [
            'operation' => $operation,
            'correlation_id' => $correlationId,
            'status' => $status,
            'provider_error' => $this->providerErrorCode($response),
        ]);

        // 5xx e 429 depois das repetições: o provedor pode ou não ter aplicado a operação.
        if ($status === 429 || $status >= 500) {
            throw PaymentGatewayException::inconclusive($operation, $correlationId, $status);
        }

        throw PaymentGatewayException::rejected($operation, $status, $this->providerErrorCode($response), $correlationId);
    }

    private function providerErrorCode(Response $response): ?string
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            return null;
        }

        foreach (['error', 'code', 'status'] as $key) {
            if (isset($payload[$key]) && (is_string($payload[$key]) || is_int($payload[$key]))) {
                return (string) $payload[$key];
            }
        }

        return null;
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw PaymentGatewayException::notConfigured(self::NAME);
        }
    }

    // -- Conversões -------------------------------------------------------------------

    /**
     * Valor monetário do provedor → centavos inteiros.
     *
     * @param  mixed  $value
     */
    public static function toCents($value): int
    {
        if (is_int($value)) {
            return $value * 100;
        }

        if (is_float($value)) {
            return (int) round($value * 100);
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) round(((float) $value) * 100);
        }

        return 0;
    }

    /**
     * @param  mixed  $value
     */
    private static function toDate($value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  mixed  $email
     */
    public static function maskEmail($email): ?string
    {
        if (! is_string($email) || ! str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).str_repeat('*', max(1, mb_strlen($local) - 1)).'@'.$domain;
    }

    /**
     * Remove do `raw` tudo que não queremos guardar: dados pessoais do pagador e
     * qualquer coisa parecida com cartão. O provedor já devolve o pagador como null na
     * maior parte dos casos, mas nada garante isso para sempre.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function scrub(array $payload): array
    {
        unset(
            $payload['payer'],
            $payload['card'],
            $payload['additional_info'],
            $payload['charges_details'],
            $payload['point_of_interaction'],
        );

        return $payload;
    }
}
