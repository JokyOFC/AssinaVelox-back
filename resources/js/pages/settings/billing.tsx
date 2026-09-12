import { Head, Link, router } from '@inertiajs/react';
import {
    CircleAlert,
    Clock,
    CreditCard,
    Info,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import { BillingCallout } from '@/components/billing/billing-callout';
import {
    type BillingPlan,
    type CheckoutOutcome,
    checkoutOutcome,
    describeSubscription,
    isSandboxPlan,
    remainingQuota,
    usageLevel,
} from '@/components/billing/billing-state';
import { BillingProfileCard } from '@/components/billing/billing-profile-card';
import { CurrentPlanCard } from '@/components/billing/current-plan-card';
import { CycleUsageCard } from '@/components/billing/cycle-usage-card';
import { PaymentMethodCard } from '@/components/billing/payment-method-card';
import {
    type BillingPayment,
    PaymentsTable,
} from '@/components/billing/payments-table';
import {
    RefundDialog,
    type RefundTarget,
} from '@/components/billing/refund-dialog';
import { SandboxPriceNotice } from '@/components/billing/sandbox-notice';
import { useVisitLoading } from '@/components/billing/use-visit-loading';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { useConfirmsPassword } from '@/components/confirms-password';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDateMedium, formatNumber } from '@/lib/format';
import {
    cancel as cancelSubscription,
    checkout as billingCheckout,
    index as billingIndex,
    resume as resumeSubscription,
} from '@/routes/billing';
import {
    cancel as cancelPendingPayment,
    refund as requestPaymentRefund,
} from '@/routes/billing/payments';
import { index as plansIndex } from '@/routes/plans';
import type {
    BillingProfile,
    Paginated,
    Payment,
    PaymentMethod,
    PlanUsage,
    Subscription,
} from '@/types';

export interface BillingProps {
    subscription: Subscription;
    usage: PlanUsage;
    payment_method: PaymentMethod | null;
    billing_profile: BillingProfile | null;
    payments: Paginated<Payment>;
    can: { manage: boolean; cancel: boolean };
    /** Há `Payment` pendente nas últimas 24 h (ROUTES §2.15). */
    pending_checkout: boolean;
    /** Retorno do Checkout Pro, quando o servidor o expõe (`billing.return`). */
    checkout_return?: CheckoutOutcome | null;
    /** Mensagem de falha ao abrir o checkout, quando o servidor a expõe. */
    checkout_error?: string | null;
    /** Fase 2, onda D — só com a flag `extended_payments` ligada. */
    extended?: {
        /** `available`: confirmado na conta do Mercado Pago; `null` = ainda não consultado. */
        methods: {
            key: string;
            label: string;
            offered: boolean;
            available: boolean | null;
        }[];
        offline_expiration_hours: number;
        owner_refunds: { allowed: boolean; window_days: number };
    } | null;
    /** Fase 2, onda D — só com a flag `fiscal_invoices` ligada. */
    fiscal_invoices?: { enabled: boolean; notice: string } | null;
}

/**
 * Plano e cobrança (ROUTES §2.15; DESIGN §6.11, aba "Plano e cobrança").
 *
 * Três decisões de honestidade guiam a tela, todas de RECONCILIACAO §4:
 * - Q20: o Checkout Pro cobra por ciclo, um pagamento avulso de cada vez;
 *   `past_due` bloqueia **envio**, não leitura nem download.
 * - Q21: não existe cartão salvo — a forma de pagamento é só leitura, e o
 *   "PDF" da lista é recibo interno, não documento fiscal.
 * - O retorno do checkout nunca afirma que o plano já mudou: quem confirma é o
 *   Mercado Pago, pelo aviso automático que chega depois.
 */
export default function Billing({
    subscription,
    usage,
    payment_method,
    billing_profile,
    payments,
    can,
    pending_checkout,
    checkout_return = null,
    checkout_error = null,
    extended = null,
    fiscal_invoices = null,
}: BillingProps) {
    const [cancelOpen, setCancelOpen] = useState(false);
    const [cancelPayment, setCancelPayment] = useState<BillingPayment | null>(
        null,
    );
    const [cancelingPayment, setCancelingPayment] = useState(false);
    const [refundTarget, setRefundTarget] = useState<RefundTarget | null>(null);
    const refundPassword = useConfirmsPassword({
        description:
            'Pedir estorno é uma ação protegida. Confirme sua senha para continuar.',
    });
    const [canceling, setCanceling] = useState(false);
    const [resuming, setResuming] = useState(false);
    const [paying, setPaying] = useState(false);

    const plan = subscription.plan as BillingPlan;
    const summary = describeSubscription(subscription);
    const outcome = checkoutOutcome(checkout_return);
    const envelopes = usage.envelopes;
    const quota = usageLevel(envelopes.used, envelopes.limit);
    const left = remainingQuota(envelopes.used, envelopes.limit);
    const periodEnd = subscription.current_period_end;
    const loadingPayments = useVisitLoading(billingIndex.url());
    const password = useConfirmsPassword({
        description:
            'Cancelar a renovação do plano é uma ação protegida. Confirme sua senha para continuar.',
    });

    const payNow = () => {
        setPaying(true);
        router.post(
            billingCheckout.url(),
            {
                plan: plan.key,
                interval: subscription.interval ?? 'monthly',
            },
            { onFinish: () => setPaying(false) },
        );
    };

    /*
     * `billing.cancel` está protegida por `password.confirm` (ROUTES §2.15). Num POST
     * o middleware não consegue retomar a ação depois da confirmação — ele guarda o
     * *referer*, não a rota do POST — então a senha é pedida **antes**, e só depois o
     * POST sai. O middleware continua sendo a garantia do servidor.
     */
    const confirmCancel = () => {
        password.ensure(() => {
            setCanceling(true);
            router.post(
                cancelSubscription.url(),
                {},
                {
                    preserveScroll: true,
                    onFinish: () => {
                        setCanceling(false);
                        setCancelOpen(false);
                    },
                },
            );
        });
    };

    const resume = () => {
        setResuming(true);
        router.post(
            resumeSubscription.url(),
            {},
            {
                preserveScroll: true,
                onFinish: () => setResuming(false),
            },
        );
    };

    return (
        <>
            <Head title="Plano e cobrança" />

            {checkout_error && (
                <BillingCallout
                    tone="danger"
                    role="alert"
                    icon={CircleAlert}
                    title="Não foi possível abrir o checkout"
                    actions={
                        can.manage && (
                            <Button
                                size="xs"
                                onClick={payNow}
                                disabled={paying}
                            >
                                {paying && <Spinner className="size-4" />}
                                Tentar de novo
                            </Button>
                        )
                    }
                >
                    {checkout_error} Nenhuma cobrança foi feita.
                </BillingCallout>
            )}

            {outcome === 'success' && (
                <BillingCallout
                    tone="info"
                    icon={Clock}
                    title="Estamos confirmando seu pagamento"
                >
                    O Mercado Pago informou que o pagamento foi concluído.{' '}
                    <b>A confirmação oficial é feita pelo provedor</b> e chega
                    pelo aviso automático dele, o que pode levar alguns
                    instantes. O plano é atualizado quando essa confirmação
                    chegar — até lá, o que você vê nesta página é o estado
                    anterior.
                </BillingCallout>
            )}

            {outcome === 'pending' && (
                <BillingCallout
                    tone="warning"
                    icon={Clock}
                    title="Pagamento em análise no Mercado Pago"
                >
                    Pix e boleto levam mais tempo para compensar. Assim que o
                    Mercado Pago confirmar, o plano é atualizado
                    automaticamente. Não é preciso pagar de novo.
                </BillingCallout>
            )}

            {outcome === 'failure' && (
                <BillingCallout
                    tone="danger"
                    role="alert"
                    icon={CircleAlert}
                    title="O pagamento não foi aprovado"
                    actions={
                        can.manage && (
                            <Button
                                size="xs"
                                onClick={payNow}
                                disabled={paying}
                            >
                                {paying && <Spinner className="size-4" />}
                                Tentar novamente
                            </Button>
                        )
                    }
                >
                    O Mercado Pago recusou ou cancelou a tentativa. Você pode
                    tentar de novo com outro meio de pagamento.
                </BillingCallout>
            )}

            {outcome === null && pending_checkout && (
                <BillingCallout
                    tone="info"
                    icon={Clock}
                    title="Estamos confirmando seu pagamento"
                >
                    Há um pagamento aguardando confirmação do Mercado Pago. A
                    confirmação é feita pelo provedor e pode levar alguns
                    instantes; o plano só muda quando ela chegar.
                </BillingCallout>
            )}

            {summary.state === 'past_due' && (
                <BillingCallout
                    tone="danger"
                    role="alert"
                    icon={TriangleAlert}
                    title="Pagamento em atraso — regularize para continuar enviando documentos"
                    actions={
                        can.manage && (
                            <Button
                                size="xs"
                                onClick={payNow}
                                disabled={paying}
                            >
                                {paying && <Spinner className="size-4" />}
                                Pagar agora
                            </Button>
                        )
                    }
                >
                    Enquanto o pagamento não é confirmado,{' '}
                    <b>novos envios ficam bloqueados</b>. Continuam liberados:
                    acessar e baixar documentos já concluídos, acompanhar os que
                    estão em andamento e a verificação pública. Sem pagamento, a
                    assinatura é cancelada e a organização volta ao plano
                    Grátis.
                </BillingCallout>
            )}

            {summary.state === 'expired' && (
                <BillingCallout
                    tone="warning"
                    icon={TriangleAlert}
                    title="Assinatura expirada — a organização está no plano Grátis"
                    actions={
                        can.manage && (
                            <Button asChild size="xs">
                                <Link href={plansIndex()}>Ver planos</Link>
                            </Button>
                        )
                    }
                >
                    Os limites do plano Grátis passam a valer. Os documentos já
                    assinados continuam válidos e disponíveis para download e
                    verificação.
                </BillingCallout>
            )}

            {quota === 'exhausted' && summary.state !== 'past_due' && (
                <BillingCallout
                    tone="warning"
                    icon={TriangleAlert}
                    title="Cota de documentos esgotada neste ciclo"
                    actions={
                        can.manage && (
                            <Button asChild size="xs">
                                <Link href={plansIndex()}>Ver planos</Link>
                            </Button>
                        )
                    }
                >
                    Você usou os {formatNumber(envelopes.limit)} documentos do
                    ciclo. Novos envios ficam bloqueados até{' '}
                    {periodEnd
                        ? `a renovação, em ${formatDateMedium(periodEnd)},`
                        : 'a renovação'}{' '}
                    ou até a troca de plano. Documentos já enviados seguem
                    normalmente.
                </BillingCallout>
            )}

            {quota === 'warning' && (
                <BillingCallout
                    tone="warning"
                    icon={Info}
                    title={`Restam ${formatNumber(left)} ${left === 1 ? 'documento' : 'documentos'} no ciclo`}
                    actions={
                        can.manage && (
                            <Button asChild variant="outline" size="xs">
                                <Link href={plansIndex()}>Ver planos</Link>
                            </Button>
                        )
                    }
                >
                    Ao chegar ao limite, novos envios ficam bloqueados até a
                    renovação do ciclo.
                </BillingCallout>
            )}

            {isSandboxPlan(plan) && <SandboxPriceNotice count={1} />}

            <div
                className="grid gap-4"
                style={{
                    gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))',
                }}
            >
                <CurrentPlanCard
                    subscription={subscription}
                    can={can}
                    onCancelRenewal={() => setCancelOpen(true)}
                    onResumeRenewal={resume}
                    resuming={resuming}
                />
                <CycleUsageCard
                    usage={usage}
                    periodStart={subscription.current_period_start}
                    periodEnd={periodEnd}
                />
            </div>

            <div
                className="grid gap-4"
                style={{
                    gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))',
                }}
            >
                <PaymentMethodCard
                    paymentMethod={payment_method}
                    lastPaidAt={
                        payments.data.find(
                            (payment) =>
                                payment.status === 'approved' &&
                                payment.paid_at !== null,
                        )?.paid_at ?? null
                    }
                />
                <BillingProfileCard
                    profile={billing_profile}
                    canManage={can.manage}
                />
            </div>

            {extended && (
                <p className="text-muted-foreground flex items-start gap-2 text-[12.5px] leading-[1.55]">
                    <CreditCard
                        aria-hidden
                        className="mt-px size-3.5 shrink-0"
                    />
                    <span>
                        {extended.methods.some(
                            (method) =>
                                method.offered && method.available === null,
                        )
                            ? 'Meios configurados para o checkout: '
                            : 'Meios aceitos no checkout: '}
                        <b className="text-text-secondary">
                            {extended.methods
                                .filter((method) => method.offered)
                                .map((method) => method.label)
                                .join(' · ') || 'Saldo em conta Mercado Pago'}
                        </b>
                        {extended.methods.some(
                            (method) =>
                                method.offered && method.available === null,
                        ) &&
                            ' (ainda não confirmados na conta do Mercado Pago)'}
                        . Pix e boleto gerados vencem em{' '}
                        {formatNumber(
                            Math.max(
                                1,
                                Math.round(
                                    extended.offline_expiration_hours / 24,
                                ),
                            ),
                        )}{' '}
                        {Math.round(extended.offline_expiration_hours / 24) <= 1
                            ? 'dia'
                            : 'dias'}
                        ; depois disso, basta pagar de novo.
                    </span>
                </p>
            )}

            <PaymentsTable
                payments={payments as Paginated<BillingPayment>}
                loading={loadingPayments}
                fiscal={fiscal_invoices !== null}
                onCancel={extended && can.manage ? setCancelPayment : undefined}
                onRequestRefund={
                    extended?.owner_refunds.allowed
                        ? (payment) =>
                              setRefundTarget({
                                  id: payment.id,
                                  label: payment.description,
                                  refundable_cents:
                                      payment.amount_cents -
                                      (payment.refunded_cents ?? 0),
                                  currency: payment.currency ?? 'BRL',
                                  fully_refundable:
                                      (payment.refunded_cents ?? 0) === 0,
                              })
                        : undefined
                }
            />

            {extended && (
                <>
                    <ConfirmDialog
                        open={cancelPayment !== null}
                        onOpenChange={(open) => !open && setCancelPayment(null)}
                        destructive
                        processing={cancelingPayment}
                        title="Cancelar este pagamento pendente?"
                        description="O Pix ou boleto gerado deixa de valer e nada é cobrado. Para pagar o plano depois, basta iniciar um novo pagamento."
                        confirmLabel="Cancelar pagamento"
                        cancelLabel="Voltar"
                        onConfirm={() => {
                            if (!cancelPayment) {
                                return;
                            }

                            setCancelingPayment(true);
                            router.post(
                                cancelPendingPayment.url(cancelPayment.id),
                                {},
                                {
                                    preserveScroll: true,
                                    onFinish: () => {
                                        setCancelingPayment(false);
                                        setCancelPayment(null);
                                    },
                                },
                            );
                        }}
                    />

                    <RefundDialog
                        target={refundTarget}
                        onOpenChange={(open) => !open && setRefundTarget(null)}
                        submitUrl={
                            refundTarget
                                ? requestPaymentRefund.url(refundTarget.id)
                                : null
                        }
                        allowPartial={false}
                        password={refundPassword}
                    />
                    {refundPassword.dialog}
                </>
            )}

            {!can.manage && (
                <p className="text-muted-foreground flex items-start gap-2 text-[12.5px]">
                    <CreditCard
                        aria-hidden
                        className="mt-px size-3.5 shrink-0"
                    />
                    Somente proprietários e administradores da organização podem
                    alterar o plano ou os dados de faturamento.
                </p>
            )}

            <ConfirmDialog
                open={cancelOpen}
                onOpenChange={setCancelOpen}
                destructive
                processing={canceling}
                title="Cancelar a renovação do plano?"
                description={
                    periodEnd
                        ? `O plano ${plan.name} continua ativo até ${formatDateMedium(periodEnd)}. Depois dessa data a organização volta ao plano Grátis e passa a valer o limite de documentos do Grátis. Documentos já assinados continuam válidos e disponíveis.`
                        : `O plano ${plan.name} deixa de ser renovado e a organização volta ao plano Grátis ao fim do ciclo. Documentos já assinados continuam válidos e disponíveis.`
                }
                confirmLabel="Cancelar renovação"
                cancelLabel="Manter o plano"
                onConfirm={confirmCancel}
            />

            {password.dialog}
        </>
    );
}

Billing.layout = {
    breadcrumbs: [{ title: 'Plano e cobrança', href: billingIndex() }],
};
