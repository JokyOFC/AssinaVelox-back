import { Head, Link, router } from '@inertiajs/react';
import { Wallet } from 'lucide-react';
import { useState } from 'react';
import {
    AffiliateStatusBadge,
    ReferralStatusBadge,
    formatRateBp,
} from '@/components/affiliates/affiliate-badges';
import { ReasonDialog } from '@/components/affiliates/reason-dialog';
import { SimplePagination } from '@/components/affiliates/simple-pagination';
import { CommissionTotalsGrid } from '@/components/affiliates/totals-grid';
import type {
    AffiliateStatus,
    CurrencyTotals,
    ProgramSettings,
    ReferralRow,
} from '@/components/affiliates/types';
import { useConfirmsPassword } from '@/components/confirms-password';
import { DataTable, type DataTableColumn } from '@/components/data-table';
import { KpiCard, KpiGrid } from '@/components/kpi-card';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatCurrency, formatDate } from '@/lib/format';
import {
    approve as approveAffiliate,
    index as adminAffiliates,
    reject as rejectAffiliate,
    show as showAffiliate,
} from '@/routes/admin/affiliates';
import { index as payoutsIndex } from '@/routes/admin/affiliates/payouts';
import { review as reviewReferral } from '@/routes/admin/affiliates/referrals';
import type { Paginated } from '@/types';

interface AffiliateListRow {
    id: string;
    name: string;
    email: string | null;
    code: string | null;
    status: AffiliateStatus;
    status_label: string;
    commission_rate_bp: number;
    referrals_count: number;
    pending_cents: number;
    approved_cents: number;
    paid_cents: number;
    has_payout_details: boolean;
    applied_at: string;
}

interface Props {
    filters: { q: string; status: AffiliateStatus | 'all' };
    summary: {
        pending: number;
        approved: number;
        suspended: number;
        review_queue: number;
    };
    totals: CurrencyTotals[];
    affiliates: Paginated<AffiliateListRow>;
    review_queue: ReferralRow[];
    program: ProgramSettings;
}

const STATUS_FILTERS: { value: AffiliateStatus | 'all'; label: string }[] = [
    { value: 'all', label: 'Todos' },
    { value: 'pending', label: 'Pendentes' },
    { value: 'approved', label: 'Aprovados' },
    { value: 'suspended', label: 'Suspensos' },
    { value: 'rejected', label: 'Recusados' },
];

type Pending =
    | { type: 'approve'; row: AffiliateListRow }
    | { type: 'reject'; row: AffiliateListRow }
    | { type: 'review'; referral: ReferralRow; decision: 'release' | 'reject' }
    | null;

/**
 * Painel interno › Afiliados (Fase 3 §3.10). Aprovação de candidaturas, fila de revisão
 * humana de indicações barradas/seguradas (LGPD art. 20) e acesso aos lotes de repasse.
 */
export default function AdminAffiliatesIndex({
    filters,
    summary,
    totals,
    affiliates,
    review_queue: reviewQueue,
    program,
}: Props) {
    const [pending, setPending] = useState<Pending>(null);
    const [rate, setRate] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);
    const [q, setQ] = useState(filters.q);
    const password = useConfirmsPassword({
        description:
            'Aprovar um afiliado define a taxa de comissão. Confirme sua senha.',
    });

    const close = () => {
        setPending(null);
        setError(undefined);
        setRate('');
    };

    const submit = (
        url: string,
        data: Record<string, string | number | null>,
    ) => {
        setBusy(true);
        router.post(url, data, {
            preserveScroll: true,
            onSuccess: close,
            onError: (errors) => setError(Object.values(errors)[0]),
            onFinish: () => setBusy(false),
        });
    };

    const columns: DataTableColumn<AffiliateListRow>[] = [
        {
            key: 'name',
            header: 'Afiliado',
            width: 'minmax(200px, 1.4fr)',
            cell: (row) => (
                <div className="min-w-0">
                    <Link
                        href={showAffiliate.url(row.id)}
                        className="font-medium hover:underline"
                    >
                        {row.name}
                    </Link>
                    <p className="text-muted-foreground truncate text-[12px]">
                        {row.email ?? '—'} {row.code ? `· ${row.code}` : ''}
                    </p>
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Estado',
            width: '160px',
            cell: (row) => (
                <AffiliateStatusBadge
                    status={row.status}
                    label={row.status_label}
                />
            ),
        },
        {
            key: 'rate',
            header: 'Taxa',
            width: '80px',
            align: 'right',
            cell: (row) => formatRateBp(row.commission_rate_bp),
        },
        {
            key: 'referrals',
            header: 'Indicações',
            width: '100px',
            align: 'right',
            cell: (row) => row.referrals_count,
        },
        {
            key: 'pending',
            header: 'Pendentes',
            width: '120px',
            align: 'right',
            cell: (row) => formatCurrency(row.pending_cents),
        },
        {
            key: 'approved',
            header: 'A repassar',
            width: '120px',
            align: 'right',
            cell: (row) => formatCurrency(row.approved_cents),
        },
        {
            key: 'actions',
            header: '',
            width: '190px',
            align: 'right',
            cell: (row) =>
                row.status === 'pending' ? (
                    <div className="flex justify-end gap-2">
                        <Button
                            size="sm"
                            onClick={() => {
                                setRate(String(program.default_rate_bp));
                                setPending({ type: 'approve', row });
                            }}
                        >
                            Aprovar
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setPending({ type: 'reject', row })}
                        >
                            Recusar
                        </Button>
                    </div>
                ) : (
                    <Button size="sm" variant="outline" asChild>
                        <Link href={showAffiliate.url(row.id)}>Detalhes</Link>
                    </Button>
                ),
        },
    ];

    return (
        <>
            <Head title="Afiliados" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    eyebrow="Painel interno"
                    title="Afiliados"
                    subtitle="O sistema calcula as comissões; o repasse é feito fora da plataforma e registrado nos lotes."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={payoutsIndex.url()}>
                                <Wallet /> Lotes de repasse
                            </Link>
                        </Button>
                    }
                />

                <KpiGrid>
                    <KpiCard
                        variant="compact"
                        label="Candidaturas pendentes"
                        value={summary.pending}
                    />
                    <KpiCard
                        variant="compact"
                        label="Afiliados aprovados"
                        value={summary.approved}
                    />
                    <KpiCard
                        variant="compact"
                        label="Suspensos"
                        value={summary.suspended}
                    />
                    <KpiCard
                        variant="compact"
                        label="Indicações para revisão"
                        value={summary.review_queue}
                        captionTone={
                            summary.review_queue > 0 ? 'warning' : 'neutral'
                        }
                        caption="Decisões automáticas aguardando uma pessoa"
                    />
                </KpiGrid>

                <CommissionTotalsGrid totals={totals} />

                {reviewQueue.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                Indicações para revisão humana
                            </CardTitle>
                            <CardDescription>
                                Barradas ou seguradas por regra automática (LGPD
                                art. 20). A decisão exige justificativa e fica
                                na trilha.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <ul className="divide-border divide-y">
                                {reviewQueue.map((referral) => (
                                    <li
                                        key={referral.id}
                                        className="flex flex-wrap items-center justify-between gap-3 py-3"
                                    >
                                        <div className="min-w-0">
                                            <p className="font-medium">
                                                {referral.organization_name}{' '}
                                                <span className="text-muted-foreground font-normal">
                                                    ← {referral.affiliate?.name}{' '}
                                                    ({referral.affiliate?.code})
                                                </span>
                                            </p>
                                            <p className="text-muted-foreground text-[12.5px]">
                                                {referral.reasons
                                                    .map((r) => r.label)
                                                    .join('; ') ||
                                                    'Sem regra registrada'}{' '}
                                                ·{' '}
                                                {formatDate(
                                                    referral.attributed_at,
                                                )}
                                                {referral.review_requested_at
                                                    ? ' · revisão pedida pelo afiliado'
                                                    : ''}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <ReferralStatusBadge
                                                status={referral.status}
                                                label={referral.status_label}
                                            />
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    setPending({
                                                        type: 'review',
                                                        referral,
                                                        decision: 'release',
                                                    })
                                                }
                                            >
                                                Liberar
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setPending({
                                                        type: 'review',
                                                        referral,
                                                        decision: 'reject',
                                                    })
                                                }
                                            >
                                                Não elegível
                                            </Button>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader className="gap-3">
                        <CardTitle>Afiliados</CardTitle>
                        <div className="flex flex-wrap items-center gap-2">
                            {STATUS_FILTERS.map((option) => (
                                <Button
                                    key={option.value}
                                    size="sm"
                                    variant={
                                        filters.status === option.value
                                            ? 'default'
                                            : 'outline'
                                    }
                                    asChild
                                >
                                    <Link
                                        href={adminAffiliates.url({
                                            query: {
                                                ...(option.value === 'all'
                                                    ? {}
                                                    : { status: option.value }),
                                                ...(filters.q
                                                    ? { q: filters.q }
                                                    : {}),
                                            },
                                        })}
                                        preserveState
                                    >
                                        {option.label}
                                    </Link>
                                </Button>
                            ))}
                            <form
                                className="ml-auto flex gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    router.get(
                                        adminAffiliates.url({
                                            query: {
                                                ...(filters.status === 'all'
                                                    ? {}
                                                    : {
                                                          status: filters.status,
                                                      }),
                                                ...(q ? { q } : {}),
                                            },
                                        }),
                                        {},
                                        { preserveState: true, replace: true },
                                    );
                                }}
                            >
                                <Input
                                    value={q}
                                    onChange={(e) => setQ(e.target.value)}
                                    placeholder="Nome, e-mail ou código"
                                    className="h-8 w-56"
                                    maxLength={120}
                                />
                                <Button
                                    size="sm"
                                    variant="outline"
                                    type="submit"
                                >
                                    Buscar
                                </Button>
                            </form>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <DataTable
                            columns={columns}
                            rows={affiliates.data}
                            rowKey={(row) => row.id}
                            empty={
                                <p className="text-muted-foreground px-5 py-10 text-center text-[13.5px]">
                                    Nenhum afiliado encontrado.
                                </p>
                            }
                        />
                        <SimplePagination page={affiliates} />
                    </CardContent>
                </Card>
            </div>

            {pending?.type === 'approve' && (
                <ReasonlessApprove
                    name={pending.row.name}
                    rate={rate}
                    setRate={setRate}
                    maxRate={program.max_rate_bp}
                    error={error}
                    busy={busy}
                    onCancel={close}
                    onConfirm={() =>
                        password.ensure(() =>
                            submit(approveAffiliate.url(pending.row.id), {
                                commission_rate_bp:
                                    rate === '' ? null : Number(rate),
                            }),
                        )
                    }
                />
            )}

            <ReasonDialog
                open={pending?.type === 'reject'}
                onOpenChange={(open) => !open && close()}
                title="Recusar candidatura"
                description={
                    pending?.type === 'reject'
                        ? `A candidatura de ${pending.row.name} será recusada. O motivo aparece para a pessoa no portal.`
                        : undefined
                }
                confirmLabel="Recusar"
                destructive
                processing={busy}
                error={error}
                onConfirm={(reason) =>
                    pending?.type === 'reject' &&
                    submit(rejectAffiliate.url(pending.row.id), { reason })
                }
            />

            <ReasonDialog
                open={pending?.type === 'review'}
                onOpenChange={(open) => !open && close()}
                title={
                    pending?.type === 'review' && pending.decision === 'release'
                        ? 'Liberar indicação'
                        : 'Manter como não elegível'
                }
                description={
                    pending?.type === 'review'
                        ? pending.decision === 'release'
                            ? 'A indicação passa a gerar comissão; pagamentos já aprovados são calculados agora e seguem o prazo de estorno.'
                            : 'Comissões pendentes são revertidas e as já aprovadas ou pagas geram estorno negativo no próximo lote.'
                        : undefined
                }
                label="Justificativa"
                confirmLabel="Registrar decisão"
                destructive={
                    pending?.type === 'review' && pending.decision === 'reject'
                }
                processing={busy}
                error={error}
                onConfirm={(note) =>
                    pending?.type === 'review' &&
                    submit(reviewReferral.url(pending.referral.id), {
                        decision: pending.decision,
                        note,
                    })
                }
            />

            {password.dialog}
        </>
    );
}

function ReasonlessApprove({
    name,
    rate,
    setRate,
    maxRate,
    error,
    busy,
    onCancel,
    onConfirm,
}: {
    name: string;
    rate: string;
    setRate: (value: string) => void;
    maxRate: number;
    error?: string;
    busy: boolean;
    onCancel: () => void;
    onConfirm: () => void;
}) {
    return (
        <Dialog open onOpenChange={(open) => !open && onCancel()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Aprovar {name}</DialogTitle>
                    <DialogDescription>
                        Um código único de indicação é gerado na aprovação. A
                        taxa pode ser alterada depois, com motivo registrado na
                        trilha.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-1.5">
                    <Label htmlFor="approve-rate">
                        Taxa de comissão (pontos-base)
                    </Label>
                    <Input
                        id="approve-rate"
                        type="number"
                        min={0}
                        max={maxRate}
                        value={rate}
                        onChange={(e) => setRate(e.target.value)}
                        aria-invalid={!!error}
                    />
                    <p className="text-muted-foreground text-[12px]">
                        {rate !== '' ? `${formatRateBp(Number(rate))} · ` : ''}
                        máximo {formatRateBp(maxRate)}
                    </p>
                    {error && (
                        <p className="text-danger text-[12.5px]">{error}</p>
                    )}
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onCancel}>
                        Cancelar
                    </Button>
                    <Button onClick={onConfirm} disabled={busy}>
                        Aprovar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

AdminAffiliatesIndex.layout = {
    breadcrumbs: [{ title: 'Afiliados', href: adminAffiliates() }],
};
