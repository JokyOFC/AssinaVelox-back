import { Head, router } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { RiskNav } from '@/components/risk/risk-nav';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import {
    index as adminRiskIndex,
    precision as adminRiskPrecision,
} from '@/routes/admin/risk';

interface PrecisionRow {
    rule: string;
    label: string;
    max_status: string;
    signals: number;
    confirmed: number;
    watching: number;
    cleared: number;
    open: number;
    precision: number | null;
}

export interface AdminRiskPrecisionProps {
    report: {
        month: string;
        from: string;
        to: string;
        rows: PrecisionRow[];
        totals: {
            signals: number;
            confirmed: number;
            watching: number;
            cleared: number;
            open: number;
        };
    };
}

const percent = (value: number | null) =>
    value === null ? '—' : `${Math.round(value * 1000) / 10}%`;

/**
 * Painel interno › Antifraude › Precisão por regra: casos confirmados ×
 * liberados no mês, para ajustar limiares e pontuações.
 */
export default function AdminRiskPrecision({
    report,
}: AdminRiskPrecisionProps) {
    const changeMonth = (month: string) => {
        router.get(
            adminRiskPrecision.url({ query: { month: month || undefined } }),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Antifraude · Precisão por regra" />
            <PageHeader
                title="Precisão por regra"
                subtitle="Precisão = confirmados ÷ (confirmados + liberados), entre os casos decididos no mês. Um caso com duas regras conta para as duas."
                actions={
                    <Input
                        type="month"
                        aria-label="Mês"
                        value={report.month}
                        onChange={(e) => changeMonth(e.target.value)}
                        className="h-[34px] w-[170px] text-[13px]"
                    />
                }
            />
            <RiskNav current="precision" />
            <div className="border-border bg-card shadow-card overflow-x-auto rounded-xl border">
                <table className="w-full min-w-[820px] text-[13px]">
                    <thead>
                        <tr className="text-muted-foreground border-border border-b text-left text-[12px]">
                            <th className="px-4 py-2.5 font-medium">Regra</th>
                            <th className="px-3 py-2.5 text-right font-medium">
                                Sinais
                            </th>
                            <th className="px-3 py-2.5 text-right font-medium">
                                Confirmados
                            </th>
                            <th className="px-3 py-2.5 text-right font-medium">
                                Em observação
                            </th>
                            <th className="px-3 py-2.5 text-right font-medium">
                                Liberados
                            </th>
                            <th className="px-3 py-2.5 text-right font-medium">
                                Abertos
                            </th>
                            <th className="px-4 py-2.5 text-right font-medium">
                                Precisão
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-border divide-y">
                        {report.rows.map((row) => (
                            <tr key={row.rule}>
                                <td className="px-4 py-2.5">
                                    <span className="font-medium">
                                        {row.label}
                                    </span>
                                    {row.max_status !== 'restricted' && (
                                        <Badge variant="info" className="ml-2">
                                            não suspende envio
                                        </Badge>
                                    )}
                                </td>
                                <td className="tabular px-3 py-2.5 text-right">
                                    {row.signals}
                                </td>
                                <td className="tabular px-3 py-2.5 text-right">
                                    {row.confirmed}
                                </td>
                                <td className="tabular px-3 py-2.5 text-right">
                                    {row.watching}
                                </td>
                                <td className="tabular px-3 py-2.5 text-right">
                                    {row.cleared}
                                </td>
                                <td className="tabular px-3 py-2.5 text-right">
                                    {row.open}
                                </td>
                                <td className="tabular px-4 py-2.5 text-right font-medium">
                                    {percent(row.precision)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr className="border-border border-t font-medium">
                            <td className="px-4 py-2.5">Total</td>
                            <td className="tabular px-3 py-2.5 text-right">
                                {report.totals.signals}
                            </td>
                            <td className="tabular px-3 py-2.5 text-right">
                                {report.totals.confirmed}
                            </td>
                            <td className="tabular px-3 py-2.5 text-right">
                                {report.totals.watching}
                            </td>
                            <td className="tabular px-3 py-2.5 text-right">
                                {report.totals.cleared}
                            </td>
                            <td className="tabular px-3 py-2.5 text-right">
                                {report.totals.open}
                            </td>
                            <td className="px-4 py-2.5" />
                        </tr>
                    </tfoot>
                </table>
            </div>
        </>
    );
}

AdminRiskPrecision.layout = {
    breadcrumbs: [
        { title: 'Antifraude', href: adminRiskIndex() },
        { title: 'Precisão por regra', href: adminRiskPrecision() },
    ],
};
