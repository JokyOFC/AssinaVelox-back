import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { BatchStatusBadge } from '@/components/affiliates/affiliate-badges';
import { SimplePagination } from '@/components/affiliates/simple-pagination';
import type { BatchStatus } from '@/components/affiliates/types';
import { DataTable, type DataTableColumn } from '@/components/data-table';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatCurrency, formatDate } from '@/lib/format';
import { index as adminAffiliates } from '@/routes/admin/affiliates';
import {
    index as payoutsIndex,
    show as showBatch,
    store as storeBatch,
} from '@/routes/admin/affiliates/payouts';
import type { Paginated } from '@/types';

interface BatchRow {
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
    external_reference: string | null;
}

interface Preview {
    currency: string;
    entries: number;
    affiliates: number;
    total_cents: number;
    eligible_affiliates: number;
    eligible_total_cents: number;
    without_payout_details: number;
}

interface Props {
    batches: Paginated<BatchRow>;
    preview: Preview[];
    currencies: string[];
    min_payout_cents: number;
}

const columns: DataTableColumn<BatchRow>[] = [
    {
        key: 'created',
        header: 'Montado em',
        width: '130px',
        cell: (row) => (
            <Link
                href={showBatch.url(row.id)}
                className="font-medium hover:underline"
            >
                {formatDate(row.created_at)}
            </Link>
        ),
    },
    {
        key: 'cutoff',
        header: 'Corte',
        width: '110px',
        cell: (row) => formatDate(row.cutoff_at),
    },
    {
        key: 'status',
        header: 'Estado',
        width: '220px',
        cell: (row) => (
            <BatchStatusBadge status={row.status} label={row.status_label} />
        ),
    },
    {
        key: 'affiliates',
        header: 'Afiliados',
        width: '90px',
        align: 'right',
        cell: (row) => row.affiliates_count,
    },
    {
        key: 'total',
        header: 'Total',
        width: '130px',
        align: 'right',
        cell: (row) => formatCurrency(row.total_cents, row.currency),
    },
    {
        key: 'paid',
        header: 'Repasse',
        width: 'minmax(160px, 1fr)',
        cell: (row) =>
            row.paid_at ? (
                <span className="text-[12.5px]">
                    {formatDate(row.paid_at)} · {row.paid_by ?? '—'} ·{' '}
                    {row.external_reference}
                </span>
            ) : (
                <span className="text-muted-foreground text-[12.5px]">—</span>
            ),
    },
];

/**
 * Painel interno › Afiliados › Lotes de repasse. Montar um lote não move dinheiro: o repasse é
 * feito fora da plataforma e registrado depois, com senha.
 */
export default function AffiliatePayoutsIndex({
    batches,
    preview,
    currencies,
    min_payout_cents: minPayout,
}: Props) {
    const form = useForm({ currency: currencies[0] ?? 'BRL', cutoff: '' });

    return (
        <>
            <Head title="Lotes de repasse" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    size="detail"
                    leading={
                        <Button variant="ghost" size="icon-sm" asChild>
                            <Link
                                href={adminAffiliates.url()}
                                aria-label="Voltar"
                            >
                                <ArrowLeft />
                            </Link>
                        </Button>
                    }
                    title="Lotes de repasse"
                    subtitle="O sistema calcula; o repasse é feito pela operadora fora da plataforma e registrado aqui."
                />

                <Alert>
                    <AlertTitle>Como funciona</AlertTitle>
                    <AlertDescription>
                        Entram lançamentos aprovados (inclusive estornos
                        negativos) de afiliados aprovados, com dados de repasse
                        e saldo a partir de {formatCurrency(minPayout)}. O
                        restante fica para o próximo lote.
                    </AlertDescription>
                </Alert>

                <div className="grid gap-4 lg:grid-cols-2">
                    {preview.map((row) => (
                        <Card key={row.currency}>
                            <CardHeader>
                                <CardTitle>
                                    Se o lote fosse montado agora (
                                    {row.currency})
                                </CardTitle>
                                <CardDescription>
                                    {row.entries} lançamentos aprovados sem
                                    lote, de {row.affiliates} afiliados.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <p className="text-[24px] font-semibold">
                                    {formatCurrency(
                                        row.eligible_total_cents,
                                        row.currency,
                                    )}
                                </p>
                                <p className="text-muted-foreground text-[12.5px]">
                                    {row.eligible_affiliates} afiliados
                                    elegíveis
                                    {row.without_payout_details > 0
                                        ? ` · ${row.without_payout_details} sem dados de repasse`
                                        : ''}
                                </p>
                            </CardContent>
                        </Card>
                    ))}

                    <Card>
                        <CardHeader>
                            <CardTitle>Montar lote</CardTitle>
                            <CardDescription>
                                Nada é pago automaticamente.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form
                                className="grid gap-4 sm:grid-cols-[160px_1fr_auto] sm:items-end"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    form.post(storeBatch.url(), {
                                        preserveScroll: true,
                                    });
                                }}
                            >
                                <div className="grid gap-1.5">
                                    <Label htmlFor="currency">Moeda</Label>
                                    <Select
                                        value={form.data.currency}
                                        onValueChange={(value) =>
                                            form.setData('currency', value)
                                        }
                                    >
                                        <SelectTrigger id="currency">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {currencies.map((currency) => (
                                                <SelectItem
                                                    key={currency}
                                                    value={currency}
                                                >
                                                    {currency}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="cutoff">
                                        Data de corte (opcional)
                                    </Label>
                                    <Input
                                        id="cutoff"
                                        type="date"
                                        value={form.data.cutoff}
                                        onChange={(e) =>
                                            form.setData(
                                                'cutoff',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                >
                                    Montar lote
                                </Button>
                                <div className="sm:col-span-3">
                                    <InputError
                                        message={
                                            form.errors.cutoff ??
                                            form.errors.currency
                                        }
                                    />
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Lotes</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <DataTable
                            columns={columns}
                            rows={batches.data}
                            rowKey={(row) => row.id}
                            empty={
                                <p className="text-muted-foreground px-5 py-10 text-center text-[13.5px]">
                                    Nenhum lote montado ainda.
                                </p>
                            }
                        />
                        <SimplePagination page={batches} />
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

AffiliatePayoutsIndex.layout = {
    breadcrumbs: [
        { title: 'Afiliados', href: adminAffiliates() },
        { title: 'Lotes de repasse', href: payoutsIndex() },
    ],
};
