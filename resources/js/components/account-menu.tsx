import { Link, router, usePage } from '@inertiajs/react';
import { ChevronsUpDown, CreditCard, LogOut, Shield, UserRound } from 'lucide-react';
import { AvatarInitials } from '@/components/avatar-initials';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useIsMobile } from '@/hooks/use-mobile';
import { logout } from '@/routes';
import { index as adminOrganizations } from '@/routes/admin/organizations';
import { index as billingIndex } from '@/routes/billing';
import { edit as profileEdit } from '@/routes/profile';

/**
 * Rodapé da sidebar: botão da conta + popover "Minha conta" (DESIGN §3.1 item 5).
 * Itens: Perfil e preferências · Plano e cobrança (oculto para member) ·
 * Painel interno (só platform admin) · Sair (destrutivo, POST logout).
 */
export function AccountMenu() {
    const { auth, organization } = usePage().props;
    const isMobile = useIsMobile();
    const user = auth.user;

    if (!user) {
        return null;
    }

    const canSeeBilling = organization?.permissions.manage_billing ?? false;

    const handleLogout = () => {
        router.flushAll();
        router.post(logout.url());
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className="flex w-full items-center gap-2.5 rounded-lg px-2 py-1.5 text-left transition-colors hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-primary/18 focus-visible:outline-none data-[state=open]:bg-accent"
                    data-test="sidebar-menu-button"
                >
                    <AvatarInitials
                        initials={user.initials}
                        tone="user"
                        size="sm"
                        className="text-[12px]"
                    />
                    <span className="min-w-0 flex-1">
                        <span className="block truncate text-[13px] font-semibold text-foreground">
                            {user.name}
                        </span>
                        <span className="block truncate text-[11.5px] text-muted-foreground">
                            {user.email}
                        </span>
                    </span>
                    <ChevronsUpDown className="size-3.5 shrink-0 text-muted-foreground" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                side={isMobile ? 'bottom' : 'top'}
                sideOffset={8}
                className="w-(--radix-dropdown-menu-trigger-width) min-w-[232px] rounded-[10px] p-1.5 shadow-popover"
            >
                <DropdownMenuLabel className="px-2.5 pt-2 pb-1.5 text-[11px] font-bold tracking-[.12em] text-muted-foreground uppercase">
                    Minha conta
                </DropdownMenuLabel>
                <DropdownMenuItem asChild className="gap-2.5 rounded-md px-2.5 py-2 text-[13.5px] focus:bg-accent-subtle">
                    <Link href={profileEdit()} prefetch>
                        <UserRound className="size-[15px]" />
                        Perfil e preferências
                    </Link>
                </DropdownMenuItem>
                {canSeeBilling && (
                    <DropdownMenuItem asChild className="gap-2.5 rounded-md px-2.5 py-2 text-[13.5px] focus:bg-accent-subtle">
                        <Link href={billingIndex()} prefetch>
                            <CreditCard className="size-[15px]" />
                            Plano e cobrança
                        </Link>
                    </DropdownMenuItem>
                )}
                {user.is_platform_admin && (
                    <DropdownMenuItem asChild className="gap-2.5 rounded-md px-2.5 py-2 text-[13.5px] focus:bg-accent-subtle">
                        <Link href={adminOrganizations()}>
                            <Shield className="size-[15px]" />
                            Painel interno
                        </Link>
                    </DropdownMenuItem>
                )}
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    variant="destructive"
                    onSelect={handleLogout}
                    className="gap-2.5 rounded-md px-2.5 py-2 text-[13.5px]"
                    data-test="logout-button"
                >
                    <LogOut className="size-[15px]" />
                    Sair
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
