import { Head, Link, router } from '@inertiajs/react';
import {
    Copy as CopyIcon,
    Download,
    Mail,
    MoreHorizontal,
    RefreshCw,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { AvatarInitials, recipientTone } from '@/components/avatar-initials';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DataTable, type DataTableColumn } from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { FilterBar, SearchInput } from '@/components/filter-bar';
import { KpiCard, KpiGrid } from '@/components/kpi-card';
import { PageHeader } from '@/components/page-header';
import { UnderlineTabs } from '@/components/segmented-control';
import { RecipientStatusBadge } from '@/components/status/recipient-status-badge';
import { TablePagination } from '@/components/table-pagination';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    formatDuration,
    formatNumber,
    formatPercent,
    formatRelativeDateTime,
} from '@/lib/format';
import { authMethodLabels, recipientTabLabels } from '@/lib/labels';
import {
    evidence as envelopeEvidence,
    show as envelopeShow,
} from '@/routes/envelopes';
import { resend as resendRecipient } from '@/routes/envelopes/recipients';
import {
    exportMethod as recipientsExport,
    index as recipientsIndex,
    resend_pending as resendPending,
} from '@/routes/recipients';
import type { Paginated, RecipientListItem } from '@/types';

type RecipientTab = 'all' | 'pending' | 'signed' | 'refused' | 'expired';

export interface RecipientsIndexProps {
    filters: {
        status: RecipientTab;
        q: string;
        period_from: string | null;
        period_to: string | null;
        sort: 'recent' | 'oldest';
    };
    kpis: {
        signed_today: { value: number; delta_vs_yesterday: number };
        pending: { value: number; viewed: number };
        refused_30d: { value: number; pct_of_total: number | null };
        avg_minutes_to_sign: { value: number | null };
    };
    tabs: Record<RecipientTab, number>;
    recipients: Paginated<RecipientListItem>;
    can?: { resend_pending: boolean };
}

/** Assinaturas (ROUTES §2.9; DESIGN §6.6) — versão inicial funcional com dados reais. */
export default function RecipientsIndex({
    filters,
    kpis,
    tabs,
    recipients,
    can,
}: RecipientsIndexProps) {
    const [confirmAll, setConfirmAll] = useState(false);
    const [busy, setBusy] = useState(false);

    const apply = (next: Partial<RecipientsIndexProps['filters']>) => {
        router.get(
            recipientsIndex.url({
                query: { ...filters, ...next, page: undefined },
            }),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const resendOne = (r: RecipientListItem) => {
        router.post(
            resendRecipient({ envelope: r.envelope_id, recipient: r.id }).url,
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(`Convite reenviado para ${r.name}`),
                onError: () =>
                    toast.error('Aguarde alguns minutos antes de reenviar'),
            },
        );
    };

    const resendAllPending = () => {
        setBusy(true);
        router.post(
            resendPending.url(),
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(
                        `Reenviando convites para ${formatNumber(tabs.pending)} signatários pendentes`,
                    ),
                onFinish: () => {
                    setBusy(false);
                    setConfirmAll(false);
                },
            },
        );
    };

    const columns: DataTableColumn<RecipientListItem>[] = [
        {
            key: 'recipient',
            header: 'Signatário',
            width: 'minmax(0,1.6fr)',
            cell: (r) => (
                <div className="flex min-w-0 items-center gap-3">
                    <AvatarInitials
                        initials={r.initials}
                        tone={recipientTone(r.status)}
                        size="md"
                    />
                    <span className="min-w-0">
                        <span className="block truncate font-semibold">
                            {r.name}
                            {r.role && (
                                <span className="text-muted-foreground ml-1 font-medium">
                                    · {r.role}
                                </span>
                            )}
                        </span>
                        <span className="text-muted-foreground block truncate text-[12.5px]">
                            {r.email}
                        </span>
                    </span>
                </div>
            ),
        },
        {
            key: 'envelope',
            header: 'Documento',
            width: 'minmax(0,1.8fr)',
            cell: (r) => (
                <span className="min-w-0">
                    <Link
                        href={envelopeShow(r.envelope_id)}
                        className="hover:text-primary block truncate font-medium"
                    >
                        {r.envelope.title}
                    </Link>
                    <span className="text-muted-foreground tabular block text-[12px]">
                        {r.envelope.display_code}
                    </span>
                </span>
            ),
        },
        {
            key: 'channel',
            header: 'Canal · Autenticação',
            width: '.9fr',
            cell: (r) => (
                <span className="inline-flex flex-wrap gap-1">
                    <span className="bg-muted text-text-secondary inline-flex items-center gap-1 rounded-[5px] px-1.5 py-0.5 text-[11px] font-semibold">
                        <Mail className="size-3" /> E-mail
                    </span>
                    {r.auth_methods.map((m) => (
                        <span
                            key={m}
                            className="bg-muted text-text-secondary inline-flex items-center gap-1 rounded-[5px] px-1.5 py-0.5 text-[11px] font-semibold"
                        >
                            {authMethodLabels[m]}
                        </span>
                    ))}
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            width: '1.3fr',
            cell: (r) => (
                <span className="flex min-w-0 flex-col gap-1">
                    <RecipientStatusBadge
                        status={r.status}
                        label={r.status_label}
                    />
                    {r.note && (
                        <span className="text-muted-foreground truncate text-[12px]">
                            {r.note}
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'when',
            header: 'Quando',
            width: '1fr',
            cell: (r) => (
                <span className="text-text-secondary tabular text-[13px] whitespace-nowrap">
                    {formatRelativeDateTime(r.when)}
                </span>
            ),
        },
        {
            key: 'actions',
            header: '',
            width: '44px',
            align: 'right',
            cell: (r) => (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon-xs"
                            aria-label="Ações"
                        >
                            <MoreHorizontal className="text-muted-foreground size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem asChild>
                            <Link href={envelopeShow(r.envelope_id)}>
                                Abrir documento
                            </Link>
                        </DropdownMenuItem>
                        {r.can_resend && (
                            <DropdownMenuItem onSelect={() => resendOne(r)}>
                                <RefreshCw className="size-3.5" /> Reenviar
                                convite
                            </DropdownMenuItem>
                        )}
                        {r.status === 'signed' && (
                            <DropdownMenuItem asChild>
                                <Link href={envelopeEvidence(r.envelope_id)}>
                                    <ShieldCheck className="size-3.5" /> Ver
                                    evidências
                                </Link>
                            </DropdownMenuItem>
                        )}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            onSelect={() => {
                                void navigator.clipboard?.writeText(r.email);
                                toast.success('E-mail copiado');
                            }}
                        >
                            <CopyIcon className="size-3.5" /> Copiar e-mail
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            ),
        },
    ];

    return (
        <>
            <Head title="Assinaturas" />
            <PageHeader
                title="Assinaturas"
                subtitle="Todos os signatários dos seus documentos, com status e evidências."
                actions={
                    <>
                        <Button asChild variant="outline">
                            <a href={recipientsExport.url({ query: filters })}>
                                <Download className="size-[15px]" /> Exportar
                                CSV
                            </a>
                        </Button>
                        {(can?.resend_pending ?? true) && (
                            <Button
                                variant="outline"
                                onClick={() => setConfirmAll(true)}
                                disabled={tabs.pending === 0}
                            >
                                <Mail className="size-[15px]" /> Lembrar todos
                                os pendentes
                            </Button>
                        )}
                    </>
                }
            />

            <KpiGrid min={150}>
                <KpiCard
                    variant="compact"
                    label="Assinadas hoje"
                    value={formatNumber(kpis.signed_today.value)}
                    caption={`${kpis.signed_today.delta_vs_yesterday >= 0 ? '+' : ''}${kpis.signed_today.delta_vs_yesterday} vs. ontem`}
                    captionTone={
                        kpis.signed_today.delta_vs_yesterday >= 0
                            ? 'success'
                            : 'danger'
                    }
                />
                <KpiCard
                    variant="compact"
                    label="Pendentes"
                    value={formatNumber(kpis.pending.value)}
                    caption={`${formatNumber(kpis.pending.viewed)} já visualizaram`}
                    captionTone="warning"
                />
                <KpiCard
                    variant="compact"
                    label="Recusadas (30 d)"
                    value={formatNumber(kpis.refused_30d.value)}
                    caption={
                        kpis.refused_30d.pct_of_total !== null
                            ? `${formatPercent(kpis.refused_30d.pct_of_total)} do total`
                            : '—'
                    }
                    captionTone="danger"
                />
                <KpiCard
                    variant="compact"
                    label="Tempo médio para assinar"
                    value={formatDuration(kpis.avg_minutes_to_sign.value)}
                    caption="Do convite ao aceite"
                />
            </KpiGrid>

            <div className="border-border bg-card shadow-card rounded-xl border">
                <UnderlineTabs
                    value={filters.status}
                    onChange={(status) => apply({ status })}
                    options={(
                        Object.keys(recipientTabLabels) as RecipientTab[]
                    ).map((key) => ({
                        value: key,
                        label: recipientTabLabels[key],
                        count: tabs[key],
                    }))}
                />
                <FilterBar
                    trailing={
                        <Select
                            value={filters.sort}
                            onValueChange={(sort) =>
                                apply({ sort: sort as 'recent' | 'oldest' })
                            }
                        >
                            <SelectTrigger
                                size="sm"
                                className="h-[34px] text-[13px]"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="recent">
                                    Mais recentes
                                </SelectItem>
                                <SelectItem value="oldest">
                                    Mais antigas
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    }
                >
                    <SearchInput
                        value={filters.q}
                        onChange={(q) => apply({ q })}
                        placeholder="Buscar por nome, e-mail ou documento"
                    />
                </FilterBar>
                <DataTable
                    columns={columns}
                    rows={recipients.data}
                    rowKey={(r) => r.id}
                    minWidth={960}
                    dense
                    empty={
                        <EmptyState
                            variant="inline"
                            title="Nenhuma assinatura encontrada para este filtro."
                        />
                    }
                />
                <TablePagination
                    paginated={recipients}
                    entity="assinaturas"
                    entitySingular="assinatura"
                />
            </div>

            <ConfirmDialog
                open={confirmAll}
                onOpenChange={setConfirmAll}
                processing={busy}
                title="Reenviar convites para todos os pendentes?"
                description={`${formatNumber(tabs.pending)} signatários receberão um novo e-mail com o link de assinatura. Limite: uma vez por hora.`}
                confirmLabel="Reenviar convites"
                onConfirm={resendAllPending}
            />
        </>
    );
}

RecipientsIndex.layout = {
    breadcrumbs: [{ title: 'Assinaturas', href: recipientsIndex() }],
};
