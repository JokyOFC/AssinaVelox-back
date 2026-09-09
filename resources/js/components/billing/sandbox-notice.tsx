import { FlaskConical } from 'lucide-react';
import { BillingCallout } from '@/components/billing/billing-callout';

/**
 * Aviso obrigatório quando o catálogo exibido tem plano marcado como sandbox
 * ou com preço fictício (`plans.is_sandbox`, PlanSeeder). Preço de teste nunca
 * pode ser apresentado como preço real: o texto abaixo nega a oferta de forma
 * explícita, e não apenas "sujeito a alteração".
 */
export function SandboxPriceNotice({ count }: { count?: number }) {
    return (
        <BillingCallout
            tone="warning"
            icon={FlaskConical}
            title={
                count === 1
                    ? 'Um dos planos usa preço de desenvolvimento'
                    : 'Preços de desenvolvimento — não são uma oferta'
            }
        >
            Os planos marcados como <b>sandbox</b> existem para testar a
            integração de pagamento. Os valores e limites são fictícios,{' '}
            <b>não constituem proposta comercial</b> e podem mudar sem aviso.
            Não use esses números como base para uma decisão de compra.
        </BillingCallout>
    );
}
