<?php

namespace App\Integrations\Payments;

use App\Integrations\Dto\GatewayPayment;
use App\Integrations\Payments\Dto\GatewayChargeback;
use App\Integrations\Payments\Dto\GatewayPaymentMethod;
use App\Integrations\Payments\Dto\GatewayPaymentPage;
use App\Integrations\Payments\Dto\GatewayRefund;
use App\Integrations\Payments\Exceptions\PaymentGatewayException;
use DateTimeInterface;

/**
 * Operações de pagamentos ampliados (Fase 2, onda D — roadmap §2.20). Todas sobre endpoints
 * públicos e documentados, com a mesma credencial do Checkout Pro
 * (docs/integracoes/mercado-pago.md §3, §7; docs/integracoes/mercado-pago-fase-2.md §4–§8):
 *
 * | método               | endpoint                                  |
 * |----------------------|-------------------------------------------|
 * | refundPayment        | POST /v1/payments/{id}/refunds            |
 * | listRefunds          | GET  /v1/payments/{id}/refunds            |
 * | cancelPayment        | PUT  /v1/payments/{id} {status=cancelled} |
 * | getChargeback        | GET  /v1/chargebacks/{id}                 |
 * | searchPayments       | GET  /v1/payments/search                  |
 * | listPaymentMethods   | GET  /v1/payment_methods                  |
 *
 * Toda operação com efeito leva a `X-Idempotency-Key` que o chamador gravou ANTES da chamada;
 * uma resposta inconclusiva lança `PaymentGatewayException::inconclusive()` e nunca vira sucesso.
 */
interface PaymentOperations
{
    /**
     * Estorno total (`$amountCents = null`: body sem `amount`) ou parcial.
     *
     * @throws PaymentGatewayException
     */
    public function refundPayment(string $providerPaymentId, ?int $amountCents, string $idempotencyKey, ?string $correlationId = null): GatewayRefund;

    /**
     * Estornos já registrados no provedor para o pagamento — a consulta que precede qualquer
     * repetição depois de um timeout.
     *
     * @return list<GatewayRefund>
     *
     * @throws PaymentGatewayException
     */
    public function listRefunds(string $providerPaymentId): array;

    /**
     * Cancela um pagamento `pending`, `in_process` ou `authorized` (qualquer outro: erro 2018).
     *
     * @throws PaymentGatewayException
     */
    public function cancelPayment(string $providerPaymentId, string $idempotencyKey, ?string $correlationId = null): GatewayPayment;

    /**
     * @throws PaymentGatewayException
     */
    public function getChargeback(string $chargebackId): GatewayChargeback;

    /**
     * Busca por janela de `date_last_updated` (máx. 12 meses para trás, intervalo ≤ 365 dias).
     *
     * @throws PaymentGatewayException
     */
    public function searchPayments(DateTimeInterface $begin, DateTimeInterface $end, int $offset, int $limit): GatewayPaymentPage;

    /**
     * @return list<GatewayPaymentMethod>
     *
     * @throws PaymentGatewayException
     */
    public function listPaymentMethods(): array;
}
