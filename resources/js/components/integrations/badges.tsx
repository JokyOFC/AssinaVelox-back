import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { ApiTokenState } from './types';

const METHOD_CLASSES: Record<string, string> = {
    GET: 'bg-primary-soft text-primary',
    POST: 'bg-success-bg text-success',
    PUT: 'bg-warning-bg text-warning',
    PATCH: 'bg-warning-bg text-warning',
    DELETE: 'bg-danger-bg text-danger',
};

/** Método HTTP (mock "App - API": pílula 11px/700). */
export function MethodBadge({
    method,
    className,
}: {
    method: string;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex min-w-[54px] justify-center rounded-md px-2 py-[3px] text-[11px] font-bold tracking-[.06em]',
                METHOD_CLASSES[method] ?? 'bg-muted text-muted-foreground',
                className,
            )}
        >
            {method}
        </span>
    );
}

/** Código HTTP de resposta com a cor da classe (2xx, 4xx, 5xx). */
export function HttpStatusBadge({ status }: { status: number | null }) {
    if (status === null) {
        return <span className="text-muted-foreground text-[12.5px]">—</span>;
    }

    const tone =
        status < 300
            ? 'bg-success-bg text-success'
            : status < 500
              ? 'bg-warning-bg text-warning'
              : 'bg-danger-bg text-danger';

    return (
        <span
            className={cn(
                'tabular rounded-md px-[7px] py-[2px] text-[11.5px] font-bold',
                tone,
            )}
        >
            {status}
        </span>
    );
}

const TOKEN_STATE: Record<
    ApiTokenState,
    { label: string; variant: 'success' | 'warning' | 'neutral' }
> = {
    active: { label: 'Ativa', variant: 'success' },
    expired: { label: 'Expirada', variant: 'warning' },
    revoked: { label: 'Revogada', variant: 'neutral' },
};

export function TokenStateBadge({ state }: { state: ApiTokenState }) {
    const config = TOKEN_STATE[state] ?? TOKEN_STATE.revoked;

    return <Badge variant={config.variant}>{config.label}</Badge>;
}

/** Situação de uma entrega de webhook (rótulo vem do servidor). */
export function DeliveryStatusBadge({
    status,
    label,
}: {
    status: string;
    label: string;
}) {
    const variant =
        status === 'delivered'
            ? 'success'
            : status === 'exhausted'
              ? 'danger'
              : status === 'failed'
                ? 'warning'
                : status === 'canceled'
                  ? 'neutral'
                  : 'info';

    return <Badge variant={variant}>{label}</Badge>;
}

export function EndpointStatusBadge({
    status,
    label,
}: {
    status: 'active' | 'paused';
    label: string;
}) {
    return (
        <Badge variant={status === 'active' ? 'success' : 'neutral'}>
            <span className="size-1.5 rounded-full bg-current" />
            {label}
        </Badge>
    );
}
