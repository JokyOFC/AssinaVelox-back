<?php

namespace App\Integrations\Payments\Preapproval;

use App\Integrations\Exceptions\IntegrationException;

/**
 * Assinatura recorrente indisponível. A mensagem é a que o operador lê; nunca carrega segredo.
 */
final class PreapprovalUnavailable extends IntegrationException
{
    /** O que falta decidir e testar antes de habilitar a produção. */
    public const PENDING = [
        'Testar com conta vendedora real do Mercado Pago quais meios `payment_methods_allowed` aceita e como Pix e boleto se comportam no segundo ciclo (NÃO CONFIRMADO na documentação).',
        'Decidir como o cancelamento automático do Mercado Pago após 3 parcelas recusadas convive com a regra Q20 (past_due em 3 dias, expired em 15).',
        'Confirmar que o painel do Mercado Pago entrega os webhooks subscription_preapproval, subscription_preapproval_plan e subscription_authorized_payment.',
    ];

    public const PRODUCTION_DISABLED = 'Assinaturas recorrentes (preapproval) estão desabilitadas em produção: faltam o teste com conta vendedora real, a decisão sobre o conflito com a regra Q20 e a confirmação dos meios aceitos. Cada ciclo continua sendo um pagamento avulso pelo Checkout Pro.';

    public static function productionDisabled(): self
    {
        return new self(self::PRODUCTION_DISABLED);
    }

    public static function simulatorOutsideProduction(): self
    {
        return new self('O simulador de assinaturas recorrentes só funciona fora de produção. '.self::PRODUCTION_DISABLED);
    }

    public static function unknown(string $preapprovalId): self
    {
        return new self("Assinatura simulada [{$preapprovalId}] não encontrada.");
    }
}
