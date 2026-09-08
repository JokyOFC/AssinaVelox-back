import { Link, router, usePage } from '@inertiajs/react';
import {
    ChevronsUpDown,
    CreditCard,
    LogOut,
    Shield,
    UserRound,
} from 'lucide-react';
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

    const canSeeBilling = organization?.permissions?.manage_billing ?? false;

    const handleLogout = () => {
        router.flushAll();
        router.post(logout.url());
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className="hover:bg-accent focus-visible:ring-primary/18 data-[state=open]:bg-accent flex w-full items-center gap-2.5 rounded-lg px-2 py-1.5 text-left transition-colors focus-visible:ring-[3px] focus-visible:outline-none"
                    data-test="sidebar-menu-button"
                >
                    <AvatarInitials
                        initials={user.initials}
                        tone="user"
                        size="sm"
                        className="text-[12px]"
                    />
                    <span className="min-w-0 flex-1">
                        <span className="text-foreground block truncate text-[13px] font-semibold">
                            {user.name}
                        </span>
                        <span className="text-muted-foreground block truncate text-[11.5px]">
                            {user.email}
                        </span>
                    </span>
                    <ChevronsUpDown className="text-muted-foreground size-3.5 shrink-0" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                side={isMobile ? 'bottom' : 'top'}
                sideOffset={8}
                className="shadow-popover w-(--radix-dropdown-menu-trigger-width) min-w-[232px] rounded-[10px] p-1.5"
            >
                <DropdownMenuLabel className="text-muted-foreground px-2.5 pt-2 pb-1.5 text-[11px] font-bold tracking-[.12em] uppercase">
                    Minha conta
                </DropdownMenuLabel>
                <DropdownMenuItem
                    asChild
                    className="focus:bg-accent-subtle gap-2.5 rounded-md px-2.5 py-2 text-[13.5px]"
                >
                    <Link href={profileEdit()} prefetch>
                        <UserRound className="size-[15px]" />
                        Perfil e preferências
                    </Link>
                </DropdownMenuItem>
                {canSeeBilling && (
                    <DropdownMenuItem
                        asChild
                        className="focus:bg-accent-subtle gap-2.5 rounded-md px-2.5 py-2 text-[13.5px]"
                    >
                        <Link href={billingIndex()} prefetch>
                            <CreditCard className="size-[15px]" />
                            Plano e cobrança
                        </Link>
                    </DropdownMenuItem>
                )}
                {user.is_platform_admin && (
                    <DropdownMenuItem
                        asChild
                        className="focus:bg-accent-subtle gap-2.5 rounded-md px-2.5 py-2 text-[13.5px]"
                    >
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
