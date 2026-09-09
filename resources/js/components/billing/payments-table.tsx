import { Download, Receipt } from 'lucide-react';
import { DataTable, type DataTableColumn } from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { PaymentStatusBadge } from '@/components/status/payment-status-badge';
import { TablePagination } from '@/components/table-pagination';
import { Button } from '@/components/ui/button';
import { formatCurrency, formatDateMedium } from '@/lib/format';
import type { Paginated, Payment } from '@/types';

/**
 * Histórico de pagamentos (ROUTES §2.15). O "PDF" da coluna de ações é o
 * **recibo interno** — RECONCILIACAO §4 Q21: NF-e é Fase 2, e não existe API
 * pública do Mercado Pago para emissão fiscal (docs/integracoes/mercado-pago.md
 * §8). Por isso o botão "NF-e" do mock não é renderizado e a nota de rodapé diz
 * explicitamente que o recibo não é documento fiscal.
 */
export function PaymentsTable({
    payments,
    loading = false,
}: {
    payments: Paginated<Payment>;
    loading?: boolean;
}) {
    const columns: DataTableColumn<Payment>[] = [
        {
            key: 'date',
            header: 'Data',
            width: '1fr',
            cell: (payment) => (
                <span className="text-text-secondary tabular text-[13px]">
                    {formatDateMedium(payment.paid_at ?? payment.created_at)}
                </span>
            ),
        },
        {
            key: 'description',
            header: 'Descrição',
            width: 'minmax(0,2fr)',
            cell: (payment) => (
                <span className="truncate font-medium">
                    {payment.description}
                </span>
            ),
        },
        {
            key: 'amount',
            header: 'Valor',
            width: '1fr',
            cell: (payment) => (
                <span className="tabular font-semibold">
                    {formatCurrency(payment.amount_cents)}
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            width: '1fr',
            cell: (payment) => (
                <PaymentStatusBadge
                    status={payment.status}
                    label={payment.status_label}
                />
            ),
        },
        {
            key: 'receipt',
            header: 'Recibo',
            width: '116px',
            align: 'right',
            cell: (payment) =>
                payment.receipt_url ? (
                    <Button asChild variant="outline-sm" size="xxs">
                        <a
                            href={payment.receipt_url}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <Download aria-hidden className="size-3" />
                            Recibo
                        </a>
                    </Button>
                ) : (
                    <span className="text-muted-foreground text-[12px]">—</span>
                ),
        },
    ];

    return (
        <div className="border-border bg-card shadow-card rounded-xl border">
            <div className="px-5 pt-[18px] pb-3">
                <Heading
                    variant="small"
                    title="Pagamentos"
                    description="Cada ciclo é um pagamento avulso confirmado pelo Mercado Pago."
                />
            </div>

            <DataTable
                columns={columns}
                rows={payments.data}
                rowKey={(payment) => payment.id}
                loading={loading}
                minWidth={640}
                empty={
                    <EmptyState
                        variant="inline"
                        title="Nenhum pagamento ainda"
                        description="Os pagamentos aparecem aqui assim que o Mercado Pago confirmar o primeiro."
                    />
                }
            />

            {payments.meta.total > 0 && (
                <TablePagination
                    paginated={payments}
                    entity="pagamentos"
                    entitySingular="pagamento"
                    showPerPage={false}
                />
            )}

            <p className="border-muted text-muted-foreground flex items-start gap-2 border-t px-5 py-3 text-[12px] leading-[1.55]">
                <Receipt aria-hidden className="mt-px size-3.5 shrink-0" />
                <span>
                    O recibo é um comprovante interno de pagamento e{' '}
                    <b className="text-text-secondary">
                        não é documento fiscal
                    </b>
                    . A emissão de nota fiscal de serviço fica para a Fase 2.
                </span>
            </p>
        </div>
    );
}
