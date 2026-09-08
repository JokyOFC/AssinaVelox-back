import { Link, usePage } from '@inertiajs/react';
import { ChevronRight, HelpCircle, PanelLeft, Shield } from 'lucide-react';
import { Fragment, type ReactNode } from 'react';
import { CommandSearch } from '@/components/command-search';
import { NotificationsPopover } from '@/components/notifications-popover';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
} from '@/components/ui/breadcrumb';
import { useSidebar } from '@/components/ui/sidebar';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { toUrl } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as adminOrganizations } from '@/routes/admin/organizations';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export const HELP_URL = 'https://ajuda.assinavelox.com.br';

/**
 * Header sticky translúcido (DESIGN §3.2): trigger da sidebar, divisor,
 * breadcrumb, cluster direito (busca ⌘K, ajuda, sino) + slot extra por tela.
 */
export function AppTopbar({
    breadcrumbs = [],
    mode = 'client',
    extra,
    hideSearch = false,
}: {
    breadcrumbs?: BreadcrumbItemType[];
    mode?: 'client' | 'admin';
    extra?: ReactNode;
    hideSearch?: boolean;
}) {
    const { organization } = usePage().props;
    const { toggleSidebar } = useSidebar();

    const root: BreadcrumbItemType =
        mode === 'admin'
            ? { title: 'Painel interno', href: adminOrganizations() }
            : {
                  title: organization?.name ?? 'AssinaVelox',
                  href: dashboard(),
              };

    const items = [root, ...breadcrumbs];

    return (
        <header
            className="border-border sticky top-0 z-10 flex h-14 shrink-0 items-center gap-3 border-b px-4 md:px-6"
            style={{
                background: 'rgba(251,252,254,.9)',
                backdropFilter: 'blur(8px)',
            }}
        >
            <button
                type="button"
                onClick={toggleSidebar}
                title="Recolher menu"
                aria-label="Alternar menu lateral"
                className="text-text-secondary hover:bg-accent hover:text-foreground flex size-8 shrink-0 items-center justify-center rounded-lg transition-colors"
            >
                <PanelLeft className="size-[17px]" />
            </button>
            <span aria-hidden className="bg-border h-[18px] w-px shrink-0" />

            <Breadcrumb className="min-w-0 flex-1">
                <BreadcrumbList className="flex-nowrap gap-2 text-[13.5px] sm:gap-2">
                    {items.map((item, index) => {
                        const isLast = index === items.length - 1;

                        return (
                            <Fragment key={`${toUrl(item.href)}-${index}`}>
                                <BreadcrumbItem
                                    className={
                                        isLast
                                            ? 'min-w-0'
                                            : 'hidden min-w-0 sm:inline-flex'
                                    }
                                >
                                    {isLast ? (
                                        <BreadcrumbPage className="text-foreground truncate font-semibold">
                                            {item.title}
                                        </BreadcrumbPage>
                                    ) : (
                                        <BreadcrumbLink asChild>
                                            <Link
                                                href={item.href}
                                                className="text-muted-foreground hover:text-primary truncate"
                                            >
                                                {item.title}
                                            </Link>
                                        </BreadcrumbLink>
                                    )}
                                </BreadcrumbItem>
                                {!isLast && (
                                    <li
                                        aria-hidden
                                        className="text-border-dashed hidden shrink-0 sm:block"
                                    >
                                        <ChevronRight className="size-3.5" />
                                    </li>
                                )}
                            </Fragment>
                        );
                    })}
                </BreadcrumbList>
            </Breadcrumb>

            <div className="ml-auto flex shrink-0 items-center gap-1.5">
                {extra}
                {mode === 'admin' && (
                    <span className="bg-warning-bg text-warning hidden h-[30px] items-center gap-1.5 rounded-md px-[10px] text-[12px] font-semibold lg:inline-flex">
                        <Shield className="size-[13px]" />
                        Acesso restrito · ações são auditadas
                    </span>
                )}
                {mode === 'client' && !hideSearch && (
                    <CommandSearch className="hidden md:flex" />
                )}
                <Tooltip>
                    <TooltipTrigger asChild>
                        <a
                            href={HELP_URL}
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label="Ajuda"
                            className="text-text-secondary hover:bg-accent hover:text-foreground hidden size-[34px] items-center justify-center rounded-lg transition-colors sm:flex"
                        >
                            <HelpCircle className="size-[17px]" />
                        </a>
                    </TooltipTrigger>
                    <TooltipContent>Central de ajuda</TooltipContent>
                </Tooltip>
                {mode === 'client' && <NotificationsPopover />}
            </div>
        </header>
    );
}
