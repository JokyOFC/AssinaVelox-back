import { Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    BarChart3,
    Building2,
    ClipboardList,
    Code,
    CreditCard,
    FileText,
    Handshake,
    History,
    LayoutDashboard,
    LayoutTemplate,
    PenLine,
    Plus,
    ShieldAlert,
    ShieldCheck,
    SlidersHorizontal,
    Tablet,
    type LucideIcon,
    Users,
} from 'lucide-react';
import { AccountMenu } from '@/components/account-menu';
import AppLogo from '@/components/app-logo';
import { OrgSwitcher } from '@/components/org-switcher';
import { Badge } from '@/components/ui/badge';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    useSidebar,
} from '@/components/ui/sidebar';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import type { RouteDefinition } from '@/wayfinder';
import { dashboard } from '@/routes';
import { index as affiliatesIndex } from '@/routes/affiliates';
import { index as adminAffiliates } from '@/routes/admin/affiliates';
import { index as adminAudit } from '@/routes/admin/audit';
import { index as adminBilling } from '@/routes/admin/billing';
import { index as adminOrganizations } from '@/routes/admin/organizations';
import { index as adminRisk } from '@/routes/admin/risk';
import { index as adminSettings } from '@/routes/admin/settings';
import { index as adminUsers } from '@/routes/admin/users';
import { index as billingIndex } from '@/routes/billing';
import {
    create as envelopesCreate,
    index as envelopesIndex,
} from '@/routes/envelopes';
import { create as inPersonCreate } from '@/routes/in_person';
import { index as integrationsIndex } from '@/routes/integrations';
import { index as membersIndex } from '@/routes/members';
import { create as organizationsCreate } from '@/routes/organizations';
import { index as plansIndex } from '@/routes/plans';
import { edit as profileEdit } from '@/routes/profile';
import { index as publicFormsIndex } from '@/routes/public_forms';
import { index as recipientsIndex } from '@/routes/recipients';
import { index as reportsIndex } from '@/routes/reports';
import { edit as securityEdit } from '@/routes/security';
import {
    audit as settingsAudit,
    general as settingsGeneral,
    notifications as settingsNotifications,
    signing as settingsSigning,
    tags as settingsTags,
} from '@/routes/settings';
import { index as templatesIndex } from '@/routes/templates';

export type SidebarMode = 'client' | 'admin';

type NavEntry = {
    key: string;
    title: string;
    href: RouteDefinition<'get'> | string;
    icon: LucideIcon;
    /** Prefixos de URL que marcam o item como ativo. */
    activePrefixes?: string[];
    badge?: number;
    phase2?: boolean;
    disabled?: boolean;
    hidden?: boolean;
};

type NavGroup = { label: string; items: NavEntry[] };

function NavItem({
    item,
    onNavigate,
}: {
    item: NavEntry;
    onNavigate: () => void;
}) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const href = toUrl(item.href);
    const prefixes = item.activePrefixes ?? [href];
    const active = prefixes.some((prefix) => isCurrentOrParentUrl(prefix));

    const classes = cn(
        'flex h-[34px] w-full items-center gap-[10px] rounded-lg px-[10px] text-[13.5px] transition-colors',
        active
            ? 'bg-primary-soft text-primary font-semibold'
            : 'text-text-secondary hover:bg-accent hover:text-foreground font-medium',
        item.disabled &&
            'hover:text-text-secondary cursor-not-allowed opacity-70 hover:bg-transparent',
    );

    const content = (
        <>
            <item.icon className="size-4 shrink-0" strokeWidth={2} />
            <span className="min-w-0 flex-1 truncate">{item.title}</span>
            {item.badge !== undefined && item.badge > 0 && (
                <Badge variant="count">{item.badge}</Badge>
            )}
            {item.phase2 && <Badge variant="phase">Fase 2</Badge>}
        </>
    );

    if (item.disabled) {
        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <span role="link" aria-disabled className={classes}>
                        {content}
                    </span>
                </TooltipTrigger>
                <TooltipContent side="right">
                    Disponível na Fase 2
                </TooltipContent>
            </Tooltip>
        );
    }

    return (
        <Link
            href={href}
            prefetch
            onClick={onNavigate}
            className={classes}
            aria-current={active ? 'page' : undefined}
        >
            {content}
        </Link>
    );
}

/**
 * Sidebar da aplicação (DESIGN §3.1; ROUTES §5). `mode` é derivado da rota
 * atual (`/admin/*` → admin). Vira Sheet abaixo de `md` (shadcn Sidebar).
 */
export function AppSidebar({ mode }: { mode: SidebarMode }) {
    const { auth, organization, counts, features } = usePage().props;
    const { isMobile, setOpenMobile } = useSidebar();
    const onNavigate = () => {
        if (isMobile) {
            setOpenMobile(false);
        }
    };

    const permissions = organization?.permissions;
    const isMember = organization?.role === 'member';

    const clientGroups: NavGroup[] = [
        {
            label: 'Plataforma',
            items: [
                {
                    key: 'dashboard',
                    title: 'Dashboard',
                    href: dashboard(),
                    icon: LayoutDashboard,
                },
                {
                    key: 'envelopes',
                    title: 'Documentos',
                    href: envelopesIndex(),
                    icon: FileText,
                    badge: counts?.pending_envelopes ?? 0,
                },
                {
                    key: 'recipients',
                    title: 'Assinaturas',
                    href: recipientsIndex(),
                    icon: PenLine,
                },
                {
                    key: 'templates',
                    title: 'Modelos',
                    href: templatesIndex(),
                    icon: LayoutTemplate,
                    // Com a flag ligada o item deixa de ser placeholder.
                    phase2: !(features?.templates ?? false),
                },
                {
                    key: 'public_forms',
                    title: 'Formulários',
                    href: publicFormsIndex(),
                    icon: ClipboardList,
                    // Fase 2 §2.2: só com a flag `public_forms` e `manage_templates`.
                    hidden: !(
                        (features?.public_forms ?? false) &&
                        (permissions?.manage_templates ?? false)
                    ),
                },
                {
                    key: 'in_person',
                    title: 'Presencial',
                    href: inPersonCreate(),
                    icon: Tablet,
                    // Fase 2 §2.6: só com a flag `in_person` e `send_envelopes`.
                    hidden: !(
                        (features?.in_person ?? false) &&
                        (permissions?.send_envelopes ?? false)
                    ),
                },
                {
                    key: 'reports',
                    title: 'Relatórios',
                    href: reportsIndex(),
                    icon: BarChart3,
                    // Fase 2 §2.14: só com a flag `reports` e a permissão `view_reports`.
                    hidden: !(
                        (features?.reports ?? false) &&
                        (permissions?.view_reports ?? false)
                    ),
                },
            ],
        },
        {
            label: 'Conta',
            items: [
                {
                    key: 'members',
                    title: 'Usuários',
                    href: membersIndex(),
                    icon: Users,
                    hidden: !(permissions?.manage_members ?? false),
                },
                {
                    // Fase 3 §3.10: portal do afiliado (conta do usuário, não da organização).
                    key: 'affiliates',
                    title: 'Programa de afiliados',
                    href: affiliatesIndex(),
                    icon: Handshake,
                    hidden: !(features?.affiliates ?? false),
                },
                {
                    key: 'integrations',
                    title: 'API e integrações',
                    href: integrationsIndex(),
                    icon: Code,
                    // Fase 2 §2.15–§2.17: sem a tag quando a API ou os webhooks
                    // estão ligados para a organização.
                    phase2: !(
                        (features?.api_integrations ?? false) ||
                        (features?.outbound_webhooks ?? false)
                    ),
                },
                {
                    key: 'settings',
                    title: 'Configurações',
                    href: isMember
                        ? settingsNotifications()
                        : settingsGeneral(),
                    icon: SlidersHorizontal,
                    activePrefixes: [
                        settingsGeneral.url(),
                        settingsSigning.url(),
                        settingsNotifications.url(),
                        settingsTags.url(),
                        settingsAudit.url(),
                        billingIndex.url(),
                        plansIndex.url(),
                        profileEdit.url(),
                        securityEdit.url(),
                    ],
                },
            ],
        },
    ];

    const adminGroups: NavGroup[] = [
        {
            label: 'Operação',
            items: [
                {
                    key: 'admin-organizations',
                    title: 'Clientes',
                    href: adminOrganizations(),
                    icon: Building2,
                },
                {
                    key: 'admin-billing',
                    title: 'Planos e faturamento',
                    href: adminBilling(),
                    icon: CreditCard,
                    // Fase 2 §2.20 (onda D): a página real só existe com
                    // `extended_payments` ligada; desligada, o placeholder da Fase 1.
                    phase2: !(features?.extended_payments ?? false),
                    disabled: !(features?.extended_payments ?? false),
                },
                {
                    // Fase 3 §3.7: fila de revisão humana do antifraude.
                    key: 'admin-risk',
                    title: 'Antifraude',
                    href: adminRisk(),
                    icon: ShieldAlert,
                    hidden: !(features?.antifraud ?? false),
                },
                {
                    // Fase 3 §3.10: afiliados, taxas e lotes de repasse (o sistema calcula, não paga).
                    key: 'admin-affiliates',
                    title: 'Afiliados',
                    href: adminAffiliates(),
                    icon: Handshake,
                    hidden: !(features?.affiliates ?? false),
                },
                {
                    key: 'admin-users',
                    title: 'Usuários da plataforma',
                    href: adminUsers(),
                    icon: Users,
                    phase2: !(features?.admin_users ?? false),
                    disabled: !(features?.admin_users ?? false),
                },
                {
                    key: 'admin-audit',
                    title: 'Logs e auditoria',
                    href: adminAudit(),
                    icon: History,
                    phase2: !(features?.admin_audit ?? false),
                    disabled: !(features?.admin_audit ?? false),
                },
            ],
        },
        {
            label: 'Sistema',
            items: [
                {
                    key: 'admin-settings',
                    title: 'Configurações globais',
                    href: adminSettings(),
                    icon: SlidersHorizontal,
                    phase2: true,
                    disabled: true,
                },
                {
                    key: 'back',
                    title: 'Voltar ao app',
                    href: dashboard(),
                    icon: ArrowLeft,
                    activePrefixes: ['/__never__'],
                    // Sem organização, o dashboard só devolveria ao painel.
                    hidden: !organization,
                },
                {
                    // Administrador da plataforma sem organização: a ação que faz
                    // sentido aqui é criar uma, não "voltar" a um app que ele não tem.
                    key: 'create-organization',
                    title: 'Criar organização',
                    href: organizationsCreate(),
                    icon: Plus,
                    hidden: !!organization,
                },
            ],
        },
    ];

    const groups = mode === 'admin' ? adminGroups : clientGroups;

    return (
        <Sidebar
            collapsible="offcanvas"
            variant="sidebar"
            className="border-border border-r"
        >
            <SidebarHeader className="gap-0 p-0">
                <div className="px-4 pt-[18px] pb-2.5">
                    <Link
                        href={dashboard()}
                        onClick={onNavigate}
                        className="inline-block"
                    >
                        <AppLogo height={30} />
                    </Link>
                </div>

                {mode === 'client' ? (
                    <>
                        {organization && (
                            <div className="px-3 pt-1.5 pb-1">
                                <OrgSwitcher />
                            </div>
                        )}
                        <div className="px-3 pt-1.5 pb-2.5">
                            <Link
                                href={envelopesCreate()}
                                onClick={onNavigate}
                                className="bg-primary shadow-primary hover:bg-primary-hover flex h-9 w-full items-center justify-center gap-2 rounded-lg text-[13.5px] font-semibold text-white transition-colors"
                            >
                                <Plus
                                    className="size-[15px]"
                                    strokeWidth={2.5}
                                />
                                Nova solicitação
                            </Link>
                        </div>
                    </>
                ) : (
                    <div className="bg-navy mx-3 mt-1.5 mb-2.5 flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-white">
                        <ShieldCheck className="text-primary-bright size-4 shrink-0" />
                        <span className="min-w-0 flex-1">
                            <span className="block text-[12.5px] font-semibold">
                                Painel interno
                            </span>
                            <span className="text-on-navy-subtle block text-[11px]">
                                Equipe AssinaVelox
                            </span>
                        </span>
                    </div>
                )}
            </SidebarHeader>

            <SidebarContent className="gap-0 px-3 py-1">
                <nav
                    className="flex flex-col gap-3.5"
                    aria-label="Navegação principal"
                >
                    {groups.map((group) => {
                        const visible = group.items.filter(
                            (item) => !item.hidden,
                        );

                        if (visible.length === 0) {
                            return null;
                        }

                        return (
                            <div key={group.label}>
                                <div className="text-muted-foreground flex h-7 items-center px-[10px] text-[10.5px] font-bold tracking-[.14em] uppercase">
                                    {group.label}
                                </div>
                                <ul className="flex flex-col gap-0.5">
                                    {visible.map((item) => (
                                        <li key={item.key}>
                                            <NavItem
                                                item={item}
                                                onNavigate={onNavigate}
                                            />
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        );
                    })}
                </nav>
            </SidebarContent>

            <SidebarFooter className="border-border border-t p-3">
                {auth.user && <AccountMenu />}
            </SidebarFooter>
        </Sidebar>
    );
}
