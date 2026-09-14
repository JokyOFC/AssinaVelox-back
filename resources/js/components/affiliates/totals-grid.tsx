import { KpiCard, KpiGrid } from '@/components/kpi-card';
import { formatCurrency } from '@/lib/format';
import type { CurrencyTotals } from './types';

/**
 * Comissões por estado, uma linha de KPIs por moeda. "A receber" é o saldo aprovado líquido
 * (estornos negativos já abatidos) ainda não incluído em lote pago.
 */
export function CommissionTotalsGrid({ totals }: { totals: CurrencyTotals[] }) {
    return (
        <div className="grid gap-3">
            {totals.map((row) => (
                <KpiGrid key={row.currency}>
                    <KpiCard
                        variant="compact"
                        label={`Pendentes (${row.currency})`}
                        value={formatCurrency(row.pending_cents, row.currency)}
                        caption="Aguardando o prazo de estorno"
                    />
                    <KpiCard
                        variant="compact"
                        label={`A receber (${row.currency})`}
                        value={formatCurrency(row.approved_cents, row.currency)}
                        caption="Aprovadas, ainda não repassadas"
                        captionTone="success"
                    />
                    <KpiCard
                        variant="compact"
                        label={`Pagas (${row.currency})`}
                        value={formatCurrency(row.paid_cents, row.currency)}
                        caption="Repasse registrado pela equipe"
                    />
                    <KpiCard
                        variant="compact"
                        label={`Revertidas (${row.currency})`}
                        value={formatCurrency(row.reversed_cents, row.currency)}
                        caption="Estorno ou contestação do pagamento"
                        captionTone={
                            row.reversed_cents > 0 ? 'warning' : 'neutral'
                        }
                    />
                </KpiGrid>
            ))}
        </div>
    );
}
