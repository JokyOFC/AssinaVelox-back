import { Head, router } from '@inertiajs/react';
import { Activity } from 'lucide-react';
import { CopyButton } from '@/components/copy-button';
import { EmptyState } from '@/components/empty-state';
import { HttpStatusBadge, MethodBadge } from '@/components/integrations/badges';
import {
    IntegrationsCard,
    IntegrationsShell,
} from '@/components/integrations/integrations-shell';
import type {
    ApiRequestLogRow,
    IntegrationsNavigation,
} from '@/components/integrations/types';
import { SegmentedControl } from '@/components/segmented-control';
import { TablePagination } from '@/components/table-pagination';
import { Badge } from '@/components/ui/badge';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatNumber, formatRelativeDateTime } from '@/lib/format';
import {
    index as integrationsIndex,
    logs as logsRoute,
} from '@/routes/integrations';
import type { Paginated } from '@/types';

type Period = '24h' | '7d' | '30d';
type StatusFilter = 'success' | 'client_error' | 'server_error';

interface LogsProps {
    navigation: IntegrationsNavigation;
    logs: Paginated<ApiRequestLogRow>;
    filters: {
        token: string | null;
        status: StatusFilter | null;
        period: Period;
    };
    summary: {
        total: number;
        success: number;
        client_error: number;
        server_error: number;
    };
    tokens: { id: string; name: string }[];
    retention_days: number;
}

const ALL = 'all';

/**
 * Integrações → Logs: requisições da API v1 (só metadados — sem corpo,
 * cabeçalhos, IP ou dados pessoais; retenção curta).
 */
export default function IntegrationsLogs({
    navigation,
    logs,
    filters,
    summary,
    tokens,
    retention_days,
}: LogsProps) {
    const apply = (next: Partial<LogsProps['filters']>) => {
        const merged = { ...filters, ...next };
        const query: Record<string, string> = { period: merged.period };

        if (merged.status) {
            query.status = merged.status;
        }

        if (merged.token) {
            query.token = merged.token;
        }

        router.get(
            logsRoute.url({ query }),
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Logs da API" />
            <IntegrationsShell
                active="logs"
                navigation={navigation}
                title="Logs da API"
                subtitle={`Requisições feitas com as chaves da conta. Guardamos só metadados, por ${retention_days} dias.`}
            >
                <IntegrationsCard>
                    <div className="flex flex-wrap items-center justify-between gap-3 px-5 pt-4 pb-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <SegmentedControl<Period>
                                ariaLabel="Período"
                                value={filters.period}
                                onChange={(period) => apply({ period })}
                                options={[
                                    { value: '24h', label: '24 horas' },
                                    { value: '7d', label: '7 dias' },
                                    { value: '30d', label: '30 dias' },
                                ]}
                            />
                            <Select
                                value={filters.status ?? ALL}
                                onValueChange={(value) =>
                                    apply({
                                        status:
                                            value === ALL
                                                ? null
                                                : (value as StatusFilter),
                                    })
                                }
                            >
                                <SelectTrigger
                                    className="h-[34px] w-[190px]"
                                    aria-label="Resultado"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        Todos os resultados
                                    </SelectItem>
                                    <SelectItem value="success">
                                        Sucesso (2xx/3xx)
                                    </SelectItem>
                                    <SelectItem value="client_error">
                                        Erro do cliente (4xx)
                                    </SelectItem>
                                    <SelectItem value="server_error">
                                        Erro do servidor (5xx)
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            {tokens.length > 0 && (
                                <Select
                                    value={filters.token ?? ALL}
                                    onValueChange={(value) =>
                                        apply({
                                            token: value === ALL ? null : value,
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        className="h-[34px] w-[210px]"
                                        aria-label="Chave"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL}>
                                            Todas as chaves
                                        </SelectItem>
                                        {tokens.map((token) => (
                                            <SelectItem
                                                key={token.id}
                                                value={token.id}
                                            >
                                                {token.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            )}
                        </div>
                        <dl className="text-text-secondary flex flex-wrap gap-4 text-[12.5px]">
                            <Summary label="Total" value={summary.total} />
                            <Summary
                                label="Sucesso"
                                value={summary.success}
                                className="text-success"
                            />
                            <Summary
                                label="4xx"
                                value={summary.client_error}
                                className="text-warning"
                            />
                            <Summary
                                label="5xx"
                                value={summary.server_error}
                                className="text-danger"
                            />
                        </dl>
                    </div>

                    {logs.data.length === 0 ? (
                        <EmptyState
                            icon={Activity}
                            title="Nenhuma requisição no período"
                            description="Quando um sistema ou conector usar uma chave da conta, cada chamada aparece aqui com o status, a duração e o id de correlação para o suporte."
                        />
                    ) : (
                        <div className="overflow-x-auto">
                            <div className="min-w-[760px]">
                                <div className="text-muted-foreground bg-background border-border grid h-[38px] grid-cols-[1fr_minmax(0,2.2fr)_.7fr_.7fr_1fr_1.2fr] items-center gap-3 border-y px-5 text-[12px] font-semibold">
                                    <span>Quando</span>
                                    <span>Requisição</span>
                                    <span>Status</span>
                                    <span>Duração</span>
                                    <span>Chave</span>
                                    <span>Correlação</span>
                                </div>
                                <ul className="divide-border divide-y">
                                    {logs.data.map((log) => (
                                        <li
                                            key={log.id}
                                            className="hover:bg-row-hover grid grid-cols-[1fr_minmax(0,2.2fr)_.7fr_.7fr_1fr_1.2fr] items-center gap-3 px-5 py-2.5 text-[13px]"
                                        >
                                            <span className="text-text-secondary tabular text-[12.5px] whitespace-nowrap">
                                                {formatRelativeDateTime(
                                                    log.occurred_at,
                                                )}
                                            </span>
                                            <span className="flex min-w-0 items-center gap-2">
                                                <MethodBadge
                                                    method={log.method}
                                                />
                                                <code className="truncate font-mono text-[12.5px]">
                                                    {log.path ??
                                                        log.route ??
                                                        '—'}
                                                </code>
                                                {log.idempotent_replay && (
                                                    <Badge
                                                        variant="info"
                                                        title="Repetição com a mesma Idempotency-Key: a resposta original foi devolvida"
                                                    >
                                                        repetição
                                                    </Badge>
                                                )}
                                            </span>
                                            <span>
                                                <HttpStatusBadge
                                                    status={log.status}
                                                />
                                            </span>
                                            <span className="text-text-secondary tabular text-[12.5px]">
                                                {formatNumber(log.duration_ms)}{' '}
                                                ms
                                            </span>
                                            <span className="text-text-secondary truncate text-[12.5px]">
                                                {log.token?.name ?? '—'}
                                            </span>
                                            <span className="flex min-w-0 items-center gap-1">
                                                <code className="text-muted-foreground truncate font-mono text-[11.5px]">
                                                    {log.correlation_id ?? '—'}
                                                </code>
                                                {log.correlation_id && (
                                                    <CopyButton
                                                        value={
                                                            log.correlation_id
                                                        }
                                                        label="Copiar id de correlação"
                                                    />
                                                )}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </div>
                    )}

                    <TablePagination
                        paginated={logs}
                        entity="requisições"
                        entitySingular="requisição"
                        gender="f"
                        showPerPage={false}
                    />
                </IntegrationsCard>
            </IntegrationsShell>
        </>
    );
}

IntegrationsLogs.layout = {
    breadcrumbs: [
        { title: 'API e integrações', href: integrationsIndex() },
        { title: 'Logs', href: logsRoute() },
    ],
};

function Summary({
    label,
    value,
    className,
}: {
    label: string;
    value: number;
    className?: string;
}) {
    return (
        <div className="flex items-baseline gap-1.5">
            <dt>{label}</dt>
            <dd className={`tabular font-semibold ${className ?? ''}`}>
                {formatNumber(value)}
            </dd>
        </div>
    );
}
