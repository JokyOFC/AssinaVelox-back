<?php

namespace App\Integrations\Payments\Preapproval;

/**
 * Adaptador de Assinaturas do Mercado Pago — **DESABILITADO** (classe B). Não faz nenhuma chamada
 * de rede: toda operação responde com a mensagem do que falta (PreapprovalUnavailable::PENDING).
 * O `mp_preapproval_id` reservado no roadmap continua sem uso até a decisão do proprietário.
 */
final class MercadoPagoPreapprovalGateway implements PreapprovalGateway
{
    public const NAME = 'mercadopago_preapproval';

    public function name(): string
    {
        return self::NAME;
    }

    public function isEnabled(): bool
    {
        return false;
    }

    public function isSimulated(): bool
    {
        return false;
    }

    public function unavailableReason(): string
    {
        return PreapprovalUnavailable::PRODUCTION_DISABLED;
    }

    public function create(PreapprovalRequest $request): PreapprovalResult
    {
        throw PreapprovalUnavailable::productionDisabled();
    }

    public function get(string $preapprovalId): PreapprovalResult
    {
        throw PreapprovalUnavailable::productionDisabled();
    }

    public function cancel(string $preapprovalId): PreapprovalResult
    {
        throw PreapprovalUnavailable::productionDisabled();
    }
}
