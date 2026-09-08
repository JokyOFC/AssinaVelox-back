import { Head, Link, router } from '@inertiajs/react';
import { CreditCard, Download } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DataTable, type DataTableColumn } from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { ProgressMeter } from '@/components/progress-meter';
import { PaymentStatusBadge } from '@/components/status/payment-status-badge';
import { SubscriptionStatusBadge } from '@/components/status/subscription-status-badge';
import { TablePagination } from '@/components/table-pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatBytes, formatCurrency, formatCurrencyCompact, formatDateMedium, formatDayMonth } from '@/lib/format';
import { cancel as cancelSubscription, checkout as billingCheckout, index as billingIndex, resume as resumeSubscription } from '@/routes/billing';
import { index as plansIndex } from '@/routes/plans';
import type { BillingProfile, Paginated, Payment, PaymentMethod, PlanUsage, Subscription } from '@/types';

export interface BillingProps {
    subscription: Subscription;
    usage: PlanUsage;
    payment_method: PaymentMethod | null;
    billing_profile: BillingProfile | null;
    payments: Paginated<Payment>;
    can: { manage: boolean; cancel: boolean };
    pending_checkout: boolean;
}

/**
 * Plano e cobrança (ROUTES §2.15; DESIGN §6.11 aba Plano e cobrança) — versão
 * inicial funcional: card navy do plano, uso no ciclo, método do último
 * pagamento (somente leitura), dados de faturamento e lista de pagamentos.
 * Wave B completa (edição de dados de faturamento, banners de retorno do checkout).
 */
export default function Billing({ subscription, usage, payment_method, billing_profile, payments, can, pending_checkout }: BillingProps) {
    const [cancelOpen, setCancelOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const plan = subscription.plan;

    const confirmCancel = () => {
        setBusy(true);
        router.post(cancelSubscription.url(), {}, {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setCancelOpen(false);
            },
        });
    };

    const columns: DataTableColumn<Payment>[] = [
        {
            key: 'date',
            header: 'Data',
            width: '1fr',
            cell: (p) => <span className="text-[13px] text-text-secondary tabular">{formatDateMedium(p.paid_at ?? p.created_at)}</span>,
        },
        { key: 'description', header: 'Descrição', width: 'minmax(0,2fr)', cell: (p) => <span className="truncate font-medium">{p.description}</span> },
        { key: 'amount', header: 'Valor', width: '1fr', cell: (p) => <span className="font-semibold tabular">{formatCurrency(p.amount_cents)}</span> },
        { key: 'status', header: 'Status', width: '1fr', cell: (p) => <PaymentStatusBadge status={p.status} label={p.status_label} /> },
        {
            key: 'actions',
            header: '',
            width: '100px',
            align: 'right',
            cell: (p) =>
                p.receipt_url ? (
                    <Button asChild variant="outline-sm" size="xxs">
                        <a href={p.receipt_url} target="_blank" rel="noopener noreferrer">
                            <Download className="size-3" /> PDF
                        </a>
                    </Button>
                ) : null,
        },
    ];

    return (
        <>
            <Head title="Plano e cobrança" />

            {subscription.status === 'past_due' && (
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-[10px] border border-warning-border bg-warning-bg p-3.5 text-[13px] text-warning">
                    <span className="font-semibold">Pagamento em atraso — regularize para continuar enviando documentos.</span>
                    {can.manage && (
                        <Button size="xs" onClick={() => router.post(billingCheckout.url(), { plan: plan.key, interval: subscription.interval ?? 'monthly' })}>
                            Pagar agora
                        </Button>
                    )}
                </div>
            )}
            {pending_checkout && (
                <div className="rounded-[10px] border border-primary-soft-border bg-primary-soft p-3.5 text-[13px] font-medium text-primary">
                    Aguardando confirmação do pagamento pelo Mercado Pago. Isso pode levar alguns minutos.
                </div>
            )}

            <div className="grid gap-4" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))' }}>
                <div className="flex flex-col gap-3 rounded-xl bg-navy p-5 text-white">
                    <div className="flex items-center justify-between gap-3">
                        <span className="text-[11px] font-bold tracking-[.16em] text-on-navy-muted uppercase">Plano atual</span>
                        <SubscriptionStatusBadge status={subscription.status} label={subscription.status_label} cancelAtPeriodEnd={subscription.cancel_at_period_end} solid />
                    </div>
                    <div>
                        <div className="text-[26px] leading-none font-extrabold italic uppercase">{plan.name}</div>
                        <div className="mt-1.5 text-[13.5px] text-on-navy-secondary">
                            {plan.price_cents_monthly === 0 ? 'Grátis' : `${formatCurrencyCompact(plan.price_cents_monthly)}/mês`}
                            {subscription.current_period_end && ` · renova em ${formatDayMonth(subscription.current_period_end)}`}
                        </div>
                    </div>
                    {plan.features.length > 0 && (
                        <p className="text-[13px] leading-[1.55] text-on-navy-secondary">{plan.features.join(' · ')}</p>
                    )}
                    {can.manage && (
                        <div className="mt-1 flex flex-wrap gap-2">
                            <Button asChild variant="onNavy" size="sm">
                                <Link href={plansIndex()}>Alterar plano</Link>
                            </Button>
                            {can.cancel && plan.key !== 'free' && !subscription.cancel_at_period_end && (
                                <Button variant="ghostOnNavy" size="sm" onClick={() => setCancelOpen(true)}>
                                    Cancelar renovação
                                </Button>
                            )}
                            {subscription.cancel_at_period_end && (
                                <Button variant="ghostOnNavy" size="sm" onClick={() => router.post(resumeSubscription.url(), {}, { preserveScroll: true })}>
                                    Reativar renovação
                                </Button>
                            )}
                        </div>
                    )}
                </div>

                <div className="flex flex-col gap-3.5 rounded-xl border border-border bg-card p-5 shadow-card">
                    <Heading
                        variant="small"
                        title="Uso no ciclo"
                        description={
                            subscription.current_period_start && subscription.current_period_end
                                ? `${formatDayMonth(subscription.current_period_start)} → ${formatDayMonth(subscription.current_period_end)}`
                                : 'Ciclo atual'
                        }
                    />
                    <ProgressMeter label="Documentos" used={usage.envelopes.used} limit={usage.envelopes.limit} />
                    <ProgressMeter label="Usuários" used={usage.members.used} limit={usage.members.limit} />
                    <ProgressMeter
                        label="Armazenamento"
                        used={usage.storage.used_bytes}
                        limit={usage.storage.limit_bytes}
                        usedLabel={formatBytes(usage.storage.used_bytes)}
                        limitLabel={usage.storage.limit_bytes ? formatBytes(usage.storage.limit_bytes) : undefined}
                    />
                </div>
            </div>

            <div className="grid gap-4" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))' }}>
                <div className="flex flex-col gap-3 rounded-xl border border-border bg-card p-5 shadow-card">
                    <Heading variant="small" title="Forma de pagamento" description="Método do último pagamento aprovado (Mercado Pago)." />
                    {payment_method ? (
                        <div className="flex items-center gap-3">
                            <span className="flex h-[30px] w-11 items-center justify-center rounded-md bg-navy text-white">
                                <CreditCard className="size-4" />
                            </span>
                            <span>
                                <span className="block text-[13.5px] font-semibold">
                                    {payment_method.label}
                                    {payment_method.last_four && ` •••• ${payment_method.last_four}`}
                                </span>
                                <span className="block text-[12.5px] text-muted-foreground">Cobrado via Checkout Pro a cada ciclo.</span>
                            </span>
                        </div>
                    ) : (
                        <p className="text-[13px] text-muted-foreground">Nenhum pagamento realizado ainda. Cartão, Pix e boleto via Mercado Pago.</p>
                    )}
                </div>
                <div className="flex flex-col gap-3 rounded-xl border border-border bg-card p-5 shadow-card">
                    <Heading
                        variant="small"
                        title="Dados de faturamento"
                        action={<Badge variant="phase">Edição · Wave B</Badge>}
                    />
                    {billing_profile ? (
                        <p className="text-[13px] leading-[1.7] text-text-secondary">
                            <b className="text-foreground">{billing_profile.legal_name}</b>
                            <br />
                            CNPJ/CPF {billing_profile.document_number}
                            <br />
                            {billing_profile.address_line} · {billing_profile.city}/{billing_profile.state} · {billing_profile.postal_code}
                            <br />
                            {billing_profile.email}
                        </p>
                    ) : (
                        <p className="text-[13px] text-muted-foreground">Nenhum dado de faturamento cadastrado.</p>
                    )}
                </div>
            </div>

            <div className="rounded-xl border border-border bg-card shadow-card">
                <div className="flex flex-wrap items-center justify-between gap-3 px-5 pt-[18px] pb-3">
                    <Heading variant="small" title="Pagamentos" description="Recibos internos em PDF. Nota fiscal chega na Fase 2." />
                </div>
                <DataTable
                    columns={columns}
                    rows={payments.data}
                    rowKey={(p) => p.id}
                    minWidth={620}
                    empty={<EmptyState variant="inline" title="Nenhum pagamento ainda" />}
                />
                {payments.meta.total > 0 && <TablePagination paginated={payments} entity="pagamentos" entitySingular="pagamento" showPerPage={false} />}
            </div>

            <ConfirmDialog
                open={cancelOpen}
                onOpenChange={setCancelOpen}
                destructive
                processing={busy}
                title="Cancelar renovação do plano?"
                description={`Seu plano ${plan.name} continua ativo até ${formatDateMedium(subscription.current_period_end)}. Depois disso a organização volta ao plano Grátis. Esta ação exige confirmação de senha.`}
                confirmLabel="Cancelar renovação"
                onConfirm={confirmCancel}
            />
        </>
    );
}

Billing.layout = {
    breadcrumbs: [{ title: 'Plano e cobrança', href: billingIndex() }],
};
