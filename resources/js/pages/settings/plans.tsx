import { Head, router, usePage } from '@inertiajs/react';
import { CircleAlert, Info } from 'lucide-react';
import { useState } from 'react';
import { BillingCallout } from '@/components/billing/billing-callout';
import {
    type BillingInterval,
    isSandboxPlan,
} from '@/components/billing/billing-state';
import {
    type PlanOption,
    PlanOptionCard,
} from '@/components/billing/plan-option-card';
import { SandboxPriceNotice } from '@/components/billing/sandbox-notice';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { useConfirmsPassword } from '@/components/confirms-password';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { SegmentedControl } from '@/components/segmented-control';
import {
    cancel as cancelSubscription,
    checkout as billingCheckout,
    index as billingIndex,
} from '@/routes/billing';
import { index as plansIndex } from '@/routes/plans';
import type { PlanCode } from '@/types';

export interface PlansProps {
    current_plan: PlanCode;
    interval: BillingInterval;
    plans: PlanOption[];
    /** Mensagem de falha ao abrir o checkout, quando o servidor a expõe. */
    checkout_error?: string | null;
}

/**
 * Escolha de plano (ROUTES §2.16). Compara os planos ativos e públicos que o
 * servidor devolveu, destaca o atual e leva ao Checkout Pro.
 *
 * Dois pontos de honestidade: o preço só aparece como oferta quando o plano
 * **não** está marcado como sandbox (PlanSeeder marca todos os pagos hoje), e
 * a página deixa claro que o Checkout Pro cobra por ciclo, sem recorrência
 * automática (docs/integracoes/mercado-pago.md §6.1).
 */
export default function Plans({
    current_plan,
    interval: initialInterval,
    plans,
    checkout_error = null,
}: PlansProps) {
    const pageErrors = usePage().props.errors;
    const [interval, setInterval] = useState<BillingInterval>(initialInterval);
    const [redirecting, setRedirecting] = useState<string | null>(null);
    const [downgrade, setDowngrade] = useState<PlanOption | null>(null);
    const [error, setError] = useState<string | null>(
        checkout_error ?? pageErrors.plan ?? pageErrors.interval ?? null,
    );

    const password = useConfirmsPassword({
        description:
            'Voltar para o plano Grátis cancela a renovação do plano pago. Confirme sua senha para continuar.',
    });

    const sandboxCount = plans.filter(isSandboxPlan).length;
    const hasYearly = plans.some((plan) => plan.price_cents_yearly !== null);
    const busy = redirecting !== null;

    const goToCheckout = (plan: PlanOption) => {
        setError(null);
        setRedirecting(plan.key);

        router.post(
            billingCheckout.url(),
            { plan: plan.key, interval },
            {
                onError: (errors) =>
                    setError(
                        Object.values(errors)[0] ??
                            'Não foi possível abrir o checkout do Mercado Pago.',
                    ),
                onFinish: () => setRedirecting(null),
            },
        );
    };

    /*
     * Voltar para o Grátis é `billing.cancel`, protegida por `password.confirm`. Igual à
     * tela de cobrança: a senha é pedida antes do POST, porque o middleware não retoma
     * um POST interrompido (ele só guarda `url.intended` para requisições GET).
     */
    const confirmDowngrade = () => {
        if (!downgrade) {
            return;
        }

        const key = downgrade.key;

        password.ensure(() => {
            setRedirecting(key);

            router.post(
                cancelSubscription.url(),
                {},
                {
                    onFinish: () => {
                        setRedirecting(null);
                        setDowngrade(null);
                    },
                },
            );
        });
    };

    const choose = (plan: PlanOption) => {
        if (plan.cta === 'current' || plan.cta === 'contact') {
            return;
        }

        if (plan.key === 'free') {
            setDowngrade(plan);

            return;
        }

        goToCheckout(plan);
    };

    return (
        <>
            <Head title="Planos" />

            <PageHeader
                title="Planos"
                subtitle="Escolha o plano que acompanha o volume de documentos da sua organização. O pagamento é feito no Checkout Pro do Mercado Pago."
                actions={
                    hasYearly && (
                        <SegmentedControl
                            value={interval}
                            onChange={setInterval}
                            ariaLabel="Periodicidade da cobrança"
                            options={[
                                { value: 'monthly', label: 'Mensal' },
                                { value: 'yearly', label: 'Anual' },
                            ]}
                        />
                    )
                }
            />

            {error && (
                <BillingCallout
                    tone="danger"
                    role="alert"
                    icon={CircleAlert}
                    title="Não foi possível abrir o checkout"
                >
                    {error} Nenhuma cobrança foi feita — você pode tentar de
                    novo.
                </BillingCallout>
            )}

            {sandboxCount > 0 && <SandboxPriceNotice count={sandboxCount} />}

            {plans.length === 0 ? (
                <EmptyState
                    title="Nenhum plano disponível"
                    description="Não há plano ativo e público no catálogo desta instalação. Fale com a AssinaVelox para liberar a contratação."
                />
            ) : (
                <div
                    className="grid gap-4"
                    style={{
                        gridTemplateColumns:
                            'repeat(auto-fit, minmax(260px, 1fr))',
                    }}
                >
                    {plans.map((plan) => (
                        <PlanOptionCard
                            key={plan.key}
                            plan={plan}
                            isCurrent={plan.key === current_plan}
                            interval={interval}
                            redirecting={redirecting === plan.key}
                            disabled={busy && redirecting !== plan.key}
                            onChoose={choose}
                        />
                    ))}
                </div>
            )}

            <BillingCallout
                tone="neutral"
                icon={Info}
                title="Como a cobrança funciona"
            >
                Cada ciclo é um pagamento avulso: o Checkout Pro não faz
                cobrança recorrente e não guarda cartão. Você escolhe o meio de
                pagamento (cartão, Pix, boleto ou saldo em conta) dentro do
                checkout, a cada renovação. A troca de plano só vale depois que
                o Mercado Pago confirmar o pagamento.
            </BillingCallout>

            <ConfirmDialog
                open={downgrade !== null}
                onOpenChange={(open) => !open && setDowngrade(null)}
                destructive
                processing={busy}
                title="Voltar para o plano Grátis?"
                description="A renovação do plano pago é cancelada. Até o fim do ciclo já pago nada muda; depois disso passam a valer os limites do plano Grátis. Documentos já assinados continuam válidos e disponíveis para download e verificação."
                confirmLabel="Voltar para o Grátis"
                cancelLabel="Manter o plano atual"
                onConfirm={confirmDowngrade}
            />

            {password.dialog}
        </>
    );
}

Plans.layout = {
    breadcrumbs: [
        { title: 'Plano e cobrança', href: billingIndex() },
        { title: 'Planos', href: plansIndex() },
    ],
};
