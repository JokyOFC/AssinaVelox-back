import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { AppSidebar, type SidebarMode } from '@/components/app-sidebar';
import { AppTopbar } from '@/components/app-topbar';
import { FlashToaster } from '@/components/flash-toaster';
import { SidebarInset, SidebarProvider } from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { setTimeZone } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

export type AppLayoutProps = {
    children: ReactNode;
    /** Itens após a raiz (organização › …). Definidos via `Page.layout = { breadcrumbs }`. */
    breadcrumbs?: BreadcrumbItem[];
    /** Slot extra do cluster direito do header (autosave, status etc.). */
    topbarExtra?: ReactNode;
    hideSearch?: boolean;
    /** Remove o padding padrão do conteúdo (ex.: wizard com largura própria). */
    fullBleed?: boolean;
    contentClassName?: string;
};

/**
 * Shell autenticado (DESIGN §3 / §3.4): Sidebar (offcanvas; Sheet abaixo de md)
 * + header sticky + conteúdo `p-6 gap-5`. O modo da sidebar é derivado da URL
 * (`/admin/*` → admin).
 */
export default function AppLayout({
    breadcrumbs = [],
    children,
    topbarExtra,
    hideSearch,
    fullBleed = false,
    contentClassName,
}: AppLayoutProps) {
    const { sidebarOpen, auth, organization } = usePage().props;
    const { currentUrl } = useCurrentUrl();
    const mode: SidebarMode = currentUrl.startsWith('/admin') ? 'admin' : 'client';

    setTimeZone(organization?.timezone ?? auth.user?.timezone);

    return (
        <SidebarProvider defaultOpen={sidebarOpen ?? true}>
            <AppSidebar mode={mode} />
            <SidebarInset className="min-w-0 overflow-x-clip bg-background">
                <AppTopbar
                    breadcrumbs={breadcrumbs}
                    mode={mode}
                    extra={topbarExtra}
                    hideSearch={hideSearch}
                />
                <div
                    className={cn(
                        'flex min-w-0 flex-1 flex-col',
                        !fullBleed && 'gap-5 p-4 md:p-6',
                        contentClassName,
                    )}
                >
                    {children}
                </div>
            </SidebarInset>
            <FlashToaster />
        </SidebarProvider>
    );
}
