<?php

namespace App\Integrations\Payments\Preapproval;

/**
 * Assinaturas recorrentes do Mercado Pago (`/preapproval`) — Fase 2, onda D, **classe B**
 * (docs/integracoes/mercado-pago-fase-2.md §7; docs/fase-2/pagamentos-e-fiscal.md §6).
 *
 * Só o contrato e um simulador identificado. A produção fica desabilitada porque falta:
 * (1) teste com conta vendedora real — quais meios `payment_methods_allowed` aceita e como
 * Pix/boleto se comportam no ciclo 2 (NÃO CONFIRMADO; "preapproval só com cartão" não é
 * confirmado nem desmentido pela documentação); (2) decisão de produto sobre o cancelamento
 * automático do Mercado Pago após 3 parcelas recusadas × a regra Q20 (`past_due` em 3 dias,
 * `expired` em 15); (3) confirmação de que o painel entrega os webhooks `subscription_*`.
 *
 * Campos do pedido: os documentados em `POST /preapproval` sem plano associado (`reason`,
 * `external_reference`, `payer_email`, `auto_recurring.frequency|frequency_type|
 * transaction_amount|currency_id`, `back_url`, `status = pending`).
 */
interface PreapprovalGateway
{
    public function name(): string;

    /**
     * Pode ser usado agora? (O adaptador real nunca; o simulador só fora de produção.)
     */
    public function isEnabled(): bool;

    public function isSimulated(): bool;

    /**
     * Motivo, em PT-BR, de não estar habilitado (null quando habilitado).
     */
    public function unavailableReason(): ?string;

    /**
     * @throws PreapprovalUnavailable
     */
    public function create(PreapprovalRequest $request): PreapprovalResult;

    /**
     * @throws PreapprovalUnavailable
     */
    public function get(string $preapprovalId): PreapprovalResult;

    /**
     * @throws PreapprovalUnavailable
     */
    public function cancel(string $preapprovalId): PreapprovalResult;
}
