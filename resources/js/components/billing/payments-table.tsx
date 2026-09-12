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
 * Campos que o servidor só envia com as flags da Fase 2, onda D ligadas
 * (`extended_payments`, `fiscal_invoices`). Desligadas, a linha é a da Fase 1
 * e a tabela fica idêntica.
 */
export type BillingPayment = Payment & {
    currency?: string;
    refunded_cents?: number;
    method_label?: string | null;
    expires_at?: string | null;
    can_cancel?: boolean;
    can_request_refund?: boolean;
    fiscal?: { status: string; label: string } | null;
};

/**
 * Histórico de pagamentos (ROUTES §2.15). O "PDF" da coluna de ações é o
 * **recibo interno** — RECONCILIACAO §4 Q21: não existe API pública do Mercado
 * Pago para emissão fiscal (docs/integracoes/mercado-pago.md §9). O botão
 * "NF-e" do mock não é renderizado e a nota de rodapé diz explicitamente que o
 * recibo não é documento fiscal.
 *
 * Fase 2, onda D: com `fiscal`, cada pagamento pago mostra a situação da NFS-e
 * ("não emitida — integração fiscal pendente" enquanto não houver provedor);
 * com `onCancel`/`onRequestRefund`, ganha as ações de cancelar pendente e pedir
 * estorno — sempre decididas pelo servidor (`can_*`).
 */
export function PaymentsTable({
    payments,
    loading = false,
    fiscal = false,
    onCancel,
    onRequestRefund,
}: {
    payments: Paginated<BillingPayment>;
    loading?: boolean;
    fiscal?: boolean;
    onCancel?: (payment: BillingPayment) => void;
    onRequestRefund?: (payment: BillingPayment) => void;
}) {
    const hasActions =
        (onCancel !== undefined || onRequestRefund !== undefined) &&
        payments.data.some(
            (payment) => payment.can_cancel || payment.can_request_refund,
        );

    const columns: DataTableColumn<BillingPayment>[] = [
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
                <span className="flex min-w-0 flex-col">
                    <span className="truncate font-medium">
                        {payment.description}
                    </span>
                    {payment.method_label && (
                        <span className="text-muted-foreground truncate text-[12px]">
                            {payment.method_label}
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'amount',
            header: 'Valor',
            width: '1fr',
            cell: (payment) => (
                <span className="flex flex-col">
                    <span className="tabular font-semibold">
                        {formatCurrency(
                            payment.amount_cents,
                            payment.currency ?? 'BRL',
                        )}
                    </span>
                    {(payment.refunded_cents ?? 0) > 0 && (
                        <span className="text-info tabular text-[12px]">
                            Estornado{' '}
                            {formatCurrency(
                                payment.refunded_cents ?? 0,
                                payment.currency ?? 'BRL',
                            )}
                        </span>
                    )}
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
        ...(fiscal
            ? [
                  {
                      key: 'fiscal',
                      header: 'Nota fiscal',
                      width: 'minmax(0,1.6fr)',
                      cell: (payment: BillingPayment) =>
                          payment.fiscal ? (
                              <span className="text-muted-foreground text-[12px] leading-[1.45]">
                                  {payment.fiscal.label}
                              </span>
                          ) : (
                              <span className="text-muted-foreground text-[12px]">
                                  —
                              </span>
                          ),
                  } satisfies DataTableColumn<BillingPayment>,
              ]
            : []),
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
        ...(hasActions
            ? [
                  {
                      key: 'actions',
                      header: 'Ações',
                      width: '150px',
                      align: 'right',
                      cell: (payment: BillingPayment) => (
                          <span className="flex justify-end gap-1.5">
                              {payment.can_cancel && onCancel && (
                                  <Button
                                      variant="outline-sm"
                                      size="xxs"
                                      onClick={() => onCancel(payment)}
                                  >
                                      Cancelar
                                  </Button>
                              )}
                              {payment.can_request_refund &&
                                  onRequestRefund && (
                                      <Button
                                          variant="outline-sm"
                                          size="xxs"
                                          onClick={() =>
                                              onRequestRefund(payment)
                                          }
                                      >
                                          Pedir estorno
                                      </Button>
                                  )}
                          </span>
                      ),
                  } satisfies DataTableColumn<BillingPayment>,
              ]
            : []),
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
                minWidth={fiscal || hasActions ? 860 : 640}
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
                {fiscal ? (
                    <span>
                        O recibo é um comprovante interno de pagamento e{' '}
                        <b className="text-text-secondary">
                            não é documento fiscal
                        </b>
                        . A emissão de NFS-e depende da integração fiscal da
                        operadora, que ainda está pendente.
                    </span>
                ) : (
                    <span>
                        O recibo é um comprovante interno de pagamento e{' '}
                        <b className="text-text-secondary">
                            não é documento fiscal
                        </b>
                        . A emissão de nota fiscal de serviço fica para a Fase
                        2.
                    </span>
                )}
            </p>
        </div>
    );
}
