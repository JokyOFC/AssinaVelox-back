import { DataTable, type DataTableColumn } from '@/components/data-table';
import { formatCurrency, formatDate } from '@/lib/format';
import { CommissionStatusBadge, formatRateBp } from './affiliate-badges';
import type { CommissionRow } from './types';

const columns: DataTableColumn<CommissionRow>[] = [
    {
        key: 'date',
        header: 'Data',
        width: '110px',
        cell: (row) => formatDate(row.created_at),
    },
    {
        key: 'organization',
        header: 'Organização',
        width: 'minmax(160px, 1fr)',
        cell: (row) => (
            <div className="min-w-0">
                <p className="truncate font-medium">{row.organization_name}</p>
                <p className="text-muted-foreground text-[12px]">
                    {row.kind_label}
                    {row.reason_label ? ` · ${row.reason_label}` : ''}
                </p>
            </div>
        ),
    },
    {
        key: 'base',
        header: 'Base',
        width: '120px',
        align: 'right',
        cell: (row) => formatCurrency(row.base_amount_cents, row.currency),
    },
    {
        key: 'rate',
        header: 'Taxa',
        width: '80px',
        align: 'right',
        cell: (row) => formatRateBp(row.rate_bp),
    },
    {
        key: 'amount',
        header: 'Comissão',
        width: '120px',
        align: 'right',
        cell: (row) => (
            <span
                className={
                    row.amount_cents < 0
                        ? 'text-danger font-semibold'
                        : 'font-semibold'
                }
            >
                {formatCurrency(row.amount_cents, row.currency)}
            </span>
        ),
    },
    {
        key: 'status',
        header: 'Estado',
        width: '150px',
        cell: (row) => (
            <div className="flex flex-col gap-0.5">
                <CommissionStatusBadge
                    status={row.status}
                    label={row.status_label}
                />
                {row.status === 'pending' && row.available_at && (
                    <span className="text-muted-foreground text-[11.5px]">
                        Libera em {formatDate(row.available_at)}
                    </span>
                )}
                {row.status === 'paid' && row.paid_at && (
                    <span className="text-muted-foreground text-[11.5px]">
                        Pago em {formatDate(row.paid_at)}
                    </span>
                )}
            </div>
        ),
    },
];

/** Extrato de lançamentos (comissões, ajustes e estornos negativos). */
export function CommissionTable({ rows }: { rows: CommissionRow[] }) {
    return (
        <DataTable
            columns={columns}
            rows={rows}
            rowKey={(row) => row.id}
            dense
            empty={
                <p className="text-muted-foreground px-5 py-10 text-center text-[13.5px]">
                    Nenhum lançamento ainda.
                </p>
            }
        />
    );
}
