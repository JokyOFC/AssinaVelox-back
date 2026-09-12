import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { PageHeader } from '@/components/page-header';
import { cn } from '@/lib/utils';
import {
    index as integrationsIndex,
    keys as keysRoute,
    logs as logsRoute,
} from '@/routes/integrations';
import { index as webhooksIndex } from '@/routes/integrations/webhooks';
import type { SharedProps } from '@/types';
import type { IntegrationsNavigation, IntegrationsTab } from './types';

type FeatureFlags = SharedProps['features'];

/**
 * Abas quando a página não recebe `navigation` do servidor (telas de webhooks, do motor
 * D-HOOK): a aba atual sempre aparece; Chaves e Logs dependem da flag compartilhada.
 */
function fallbackNavigation(features: FeatureFlags): IntegrationsNavigation {
    const api = features?.api_integrations ?? false;

    return {
        docs: true,
        keys: api,
        webhooks: true,
        logs: api,
        rest_hooks: features?.rest_hooks ?? false,
    };
}

/**
 * Cabeçalho comum de "API e integrações": título, subtítulo e o segmented
 * control com as abas (DESIGN §4.5b) — Documentação, Chaves, Webhooks, Logs.
 * Só aparecem as abas que a organização tem ligadas.
 */
export function IntegrationsShell({
    active,
    title,
    subtitle,
    navigation,
    actions,
    children,
}: {
    active: IntegrationsTab;
    title: ReactNode;
    subtitle?: ReactNode;
    navigation?: IntegrationsNavigation;
    actions?: ReactNode;
    children: ReactNode;
}) {
    const { features } = usePage().props as unknown as Pick<
        SharedProps,
        'features'
    >;
    const nav = navigation ?? fallbackNavigation(features as FeatureFlags);

    const tabs: { key: IntegrationsTab; label: string; href: string }[] = [
        { key: 'docs', label: 'Documentação', href: integrationsIndex.url() },
        { key: 'keys', label: 'Chaves', href: keysRoute.url() },
        { key: 'webhooks', label: 'Webhooks', href: webhooksIndex.url() },
        { key: 'logs', label: 'Logs', href: logsRoute.url() },
    ];

    const visible = tabs.filter((tab) => nav[tab.key] || tab.key === active);

    return (
        <>
            <PageHeader
                title={title}
                subtitle={subtitle}
                actions={
                    <>
                        {actions}
                        <nav
                            aria-label="Seções de API e integrações"
                            className="bg-accent inline-flex max-w-full gap-0.5 overflow-x-auto rounded-lg p-[3px]"
                        >
                            {visible.map((tab) => {
                                const current = tab.key === active;

                                return (
                                    <Link
                                        key={tab.key}
                                        href={tab.href}
                                        aria-current={
                                            current ? 'page' : undefined
                                        }
                                        className={cn(
                                            'inline-flex items-center rounded-md px-3 py-[5px] text-[12.5px] whitespace-nowrap transition-colors',
                                            current
                                                ? 'text-foreground shadow-segment bg-white font-semibold'
                                                : 'text-text-secondary hover:text-foreground font-medium',
                                        )}
                                    >
                                        {tab.label}
                                    </Link>
                                );
                            })}
                        </nav>
                    </>
                }
            />
            {children}
        </>
    );
}

/** Cartão padrão das telas de integrações (DESIGN §4.1). */
export function IntegrationsCard({
    title,
    description,
    actions,
    children,
    className,
    id,
}: {
    title?: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    children: ReactNode;
    className?: string;
    id?: string;
}) {
    return (
        <section
            id={id}
            className={cn(
                'border-border bg-card rounded-xl border shadow-[0_1px_2px_rgba(11,31,66,.04)]',
                className,
            )}
        >
            {(title || actions) && (
                <div className="flex flex-wrap items-center justify-between gap-3 px-5 pt-[18px] pb-3">
                    <div className="min-w-0">
                        {title && (
                            <h2 className="text-[15px] font-semibold">
                                {title}
                            </h2>
                        )}
                        {description && (
                            <p className="text-muted-foreground mt-1 text-[13px]">
                                {description}
                            </p>
                        )}
                    </div>
                    {actions && (
                        <div className="flex flex-wrap items-center gap-2">
                            {actions}
                        </div>
                    )}
                </div>
            )}
            {children}
        </section>
    );
}
