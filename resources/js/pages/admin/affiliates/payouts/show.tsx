import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Download } from 'lucide-react';
import { useState } from 'react';
import { BatchStatusBadge } from '@/components/affiliates/affiliate-badges';
import { ReasonDialog } from '@/components/affiliates/reason-dialog';
import { TrailList } from '@/components/affiliates/trail-list';
import type {
    BatchStatus,
    MaskedPayout,
    TrailEntry,
} from '@/components/affiliates/types';
import { useConfirmsPassword } from '@/components/confirms-password';
import { DataTable, type DataTableColumn } from '@/components/data-table';
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
import { Textarea } from '@/components/ui/textarea';
import { formatCurrency, formatDate, formatDateTime } from '@/lib/format';
import {
    index as adminAffiliates,
    show as showAffiliate,
} from '@/routes/admin/affiliates';
import {
    cancel as cancelBatch,
    exportMethod as exportBatch,
    index as payoutsIndex,
    paid as markBatchPaid,
    show as showBatch,
} from '@/routes/admin/affiliates/payouts';

interface Line {
    affiliate_id: string;
    code: string | null;
    name: string;
    payout: MaskedPayout | null;
    entries: number;
    total_cents: number;
}

interface Props {
    batch: {
        id: string;
        currency: string;
        status: BatchStatus;
        status_label: string;
        cutoff_at: string;
        total_cents: number;
        affiliates_count: number;
        entries_count: number;
        created_by: string | null;
        created_at: string | null;
        paid_by: string | null;
        paid_at: string | null;
        marked_paid_at: string | null;
        external_reference: string | null;
        notes: string | null;
        canceled_by: string | null;
        canceled_at: string | null;
    };
    lines: Line[];
    trail: TrailEntry[];
}

function today(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

/**
 * Detalhe do lote de repasse. "Marcar como pago" apenas REGISTRA um repasse feito fora da
 * plataforma (quem, quando, referência externa) — o sistema não paga ninguém.
 */
export default function AffiliatePayoutShow({ batch, lines, trail }: Props) {
    const [paidOpen, setPaidOpen] = useState(false);
    const [cancelOpen, setCancelOpen] = useState(false);
    const [paidAt, setPaidAt] = useState(today());
    const [reference, setReference] = useState('');
    const [notes, setNotes] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const password = useConfirmsPassword({
        description:
            'Registrar um repasse como pago é uma ação protegida. Confirme sua senha.',
    });

    const columns: DataTableColumn<Line>[] = [
        {
            key: 'affiliate',
            header: 'Afiliado',
            width: 'minmax(180px, 1fr)',
            cell: (row) => (
                <div className="min-w-0">
                    <Link
                        href={showAffiliate.url(row.affiliate_id)}
                        className="font-medium hover:underline"
                    >
                        {row.name}
                    </Link>
                    <p className="text-muted-foreground text-[12px]">
                        {row.code ?? '—'}
                    </p>
                </div>
            ),
        },
        {
            key: 'payout',
            header: 'Repasse (mascarado)',
            width: 'minmax(220px, 1.2fr)',
            cell: (row) =>
                row.payout ? (
                    <span className="text-[12.5px]">
                        {row.payout.method} {row.payout.pix_key_type_label}:{' '}
                        {row.payout.pix_key} · {row.payout.holder_name} ·{' '}
                        {row.payout.holder_tax_id}
                    </span>
                ) : (
                    '—'
                ),
        },
        {
            key: 'entries',
            header: 'Lançamentos',
            width: '110px',
            align: 'right',
            cell: (row) => row.entries,
        },
        {
            key: 'total',
            header: 'Valor',
            width: '130px',
            align: 'right',
            cell: (row) => (
                <span className="font-semibold">
                    {formatCurrency(row.total_cents, batch.currency)}
                </span>
            ),
        },
    ];

    const markPaid = () =>
        password.ensure(() => {
            setBusy(true);
            router.post(
                markBatchPaid.url(batch.id),
                { paid_at: paidAt, external_reference: reference, notes },
                {
                    preserveScroll: true,
                    onSuccess: () => setPaidOpen(false),
                    onError: (e) => setErrors(e),
                    onFinish: () => setBusy(false),
                },
            );
        });

    return (
        <>
            <Head title="Lote de repasse" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    size="detail"
                    leading={
                        <Button variant="ghost" size="icon-sm" asChild>
                            <Link href={payoutsIndex.url()} aria-label="Voltar">
                                <ArrowLeft />
                            </Link>
                        </Button>
                    }
                    title={`Lote de ${formatDate(batch.created_at)} · ${formatCurrency(batch.total_cents, batch.currency)}`}
                    subtitle={`${batch.affiliates_count} afiliados · ${batch.entries_count} lançamentos · corte em ${formatDate(batch.cutoff_at)} · montado por ${batch.created_by ?? '—'}`}
                    badge={
                        <BatchStatusBadge
                            status={batch.status}
                            label={batch.status_label}
                        />
                    }
                    actions={
                        <div className="flex gap-2">
                            <Button variant="outline" asChild>
                                <a href={exportBatch.url(batch.id)}>
                                    <Download /> CSV
                                </a>
                            </Button>
                            {batch.status === 'draft' && (
                                <>
                                    <Button
                                        variant="outline"
                                        onClick={() => setCancelOpen(true)}
                                    >
                                        Cancelar lote
                                    </Button>
                                    <Button onClick={() => setPaidOpen(true)}>
                                        Registrar pagamento
                                    </Button>
                                </>
                            )}
                        </div>
                    }
                />

                {batch.status === 'paid' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Repasse registrado</CardTitle>
                            <CardDescription>
                                Pago em {formatDate(batch.paid_at)} · registrado
                                por {batch.paid_by ?? '—'} em{' '}
                                {formatDateTime(batch.marked_paid_at)}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="text-[13px]">
                            Referência externa:{' '}
                            <strong>{batch.external_reference}</strong>
                            {batch.notes ? ` · ${batch.notes}` : ''}
                        </CardContent>
                    </Card>
                )}

                {batch.status === 'canceled' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Lote cancelado</CardTitle>
                            <CardDescription>
                                Por {batch.canceled_by ?? '—'} em{' '}
                                {formatDateTime(batch.canceled_at)}. Os
                                lançamentos voltaram para o próximo lote.
                            </CardDescription>
                        </CardHeader>
                        {batch.notes && (
                            <CardContent className="text-[13px]">
                                {batch.notes}
                            </CardContent>
                        )}
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Linhas por afiliado</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <DataTable
                            columns={columns}
                            rows={lines}
                            rowKey={(row) => row.affiliate_id}
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Trilha do lote</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <TrailList entries={trail} />
                    </CardContent>
                </Card>
            </div>

            <Dialog open={paidOpen} onOpenChange={setPaidOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Registrar pagamento do lote</DialogTitle>
                        <DialogDescription>
                            Use depois de fazer o repasse fora da plataforma. O
                            AssinaVelox não transfere dinheiro.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4">
                        <div className="grid gap-1.5">
                            <Label htmlFor="paid_at">Data do repasse</Label>
                            <Input
                                id="paid_at"
                                type="date"
                                value={paidAt}
                                max={today()}
                                onChange={(e) => setPaidAt(e.target.value)}
                            />
                            {errors.paid_at && (
                                <p className="text-danger text-[12.5px]">
                                    {errors.paid_at}
                                </p>
                            )}
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="external_reference">
                                Referência externa
                            </Label>
                            <Input
                                id="external_reference"
                                value={reference}
                                maxLength={120}
                                placeholder="Ex.: identificador E2E do PIX"
                                onChange={(e) => setReference(e.target.value)}
                            />
                            {errors.external_reference && (
                                <p className="text-danger text-[12.5px]">
                                    {errors.external_reference}
                                </p>
                            )}
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="notes">Observação (opcional)</Label>
                            <Textarea
                                id="notes"
                                value={notes}
                                rows={2}
                                maxLength={500}
                                onChange={(e) => setNotes(e.target.value)}
                            />
                            {(errors.notes ?? errors.batch) && (
                                <p className="text-danger text-[12.5px]">
                                    {errors.notes ?? errors.batch}
                                </p>
                            )}
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setPaidOpen(false)}
                        >
                            Cancelar
                        </Button>
                        <Button
                            onClick={markPaid}
                            disabled={busy || reference.trim().length < 3}
                        >
                            Registrar como pago
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <ReasonDialog
                open={cancelOpen}
                onOpenChange={setCancelOpen}
                title="Cancelar lote"
                description="Os lançamentos voltam para o próximo lote. O cancelamento fica na trilha."
                confirmLabel="Cancelar lote"
                destructive
                processing={busy}
                error={errors.reason ?? errors.batch}
                onConfirm={(reason) => {
                    setBusy(true);
                    router.post(
                        cancelBatch.url(batch.id),
                        { reason },
                        {
                            onError: (e) => setErrors(e),
                            onFinish: () => setBusy(false),
                        },
                    );
                }}
            />

            {password.dialog}
        </>
    );
}

AffiliatePayoutShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Afiliados', href: adminAffiliates() },
        { title: 'Lotes de repasse', href: payoutsIndex() },
        {
            title: formatDate(props.batch.created_at),
            href: showBatch(props.batch.id),
        },
    ],
});
