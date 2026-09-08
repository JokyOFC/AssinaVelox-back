import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren, ReactNode } from 'react';
import { PageHeader } from '@/components/page-header';
import { RailNavButton } from '@/components/segmented-control';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { toUrl } from '@/lib/utils';
import type { RouteDefinition } from '@/wayfinder';
import { index as billingIndex } from '@/routes/billing';
import { index as plansIndex } from '@/routes/plans';
import { edit as profileEdit } from '@/routes/profile';
import { edit as securityEdit } from '@/routes/security';
import {
    general as settingsGeneral,
    notifications as settingsNotifications,
    signing as settingsSigning,
} from '@/routes/settings';

type RailItem = {
    key: string;
    title: string;
    href: RouteDefinition<'get'>;
    prefixes?: string[];
    hidden?: boolean;
};

type RailGroup = { label: string; items: RailItem[] };

export type SettingsLayoutProps = PropsWithChildren<{
    /** Substitui o cabeçalho padrão "Configurações". */
    header?: ReactNode;
}>;

/**
 * Layout de Configurações (DESIGN §6.11): rail vertical de 200px com itens
 * Geral · Segurança da conta · Padrões de assinatura · Notificações · Plano e
 * cobrança (+ Perfil), e coluna de conteúdo `max-w-[820px] gap-4`.
 * No mobile, o rail vira um Select.
 */
export default function SettingsLayout({
    children,
    header,
}: SettingsLayoutProps) {
    const { organization } = usePage().props;
    const { isCurrentOrParentUrl, currentUrl } = useCurrentUrl();
    const permissions = organization?.permissions;

    const groups: RailGroup[] = [
        {
            label: 'Minha conta',
            items: [
                { key: 'profile', title: 'Perfil', href: profileEdit() },
                {
                    key: 'security',
                    title: 'Segurança da conta',
                    href: securityEdit(),
                },
            ],
        },
        {
            label: 'Organização',
            items: [
                {
                    key: 'general',
                    title: 'Geral e segurança',
                    href: settingsGeneral(),
                    hidden: !(permissions?.manage_settings ?? false),
                },
                {
                    key: 'signing',
                    title: 'Padrões de assinatura',
                    href: settingsSigning(),
                    hidden: !(permissions?.manage_settings ?? false),
                },
                {
                    key: 'notifications',
                    title: 'Notificações',
                    href: settingsNotifications(),
                },
                {
                    key: 'billing',
                    title: 'Plano e cobrança',
                    href: billingIndex(),
                    prefixes: [billingIndex.url(), plansIndex.url()],
                    hidden: !(permissions?.manage_billing ?? false),
                },
            ],
        },
    ];

    const isActive = (item: RailItem) =>
        (item.prefixes ?? [toUrl(item.href)]).some((prefix) =>
            prefix === settingsGeneral.url()
                ? currentUrl === prefix
                : isCurrentOrParentUrl(prefix),
        );

    const visibleItems = groups.flatMap((g) =>
        g.items.filter((i) => !i.hidden),
    );
    const activeItem = visibleItems.find(isActive);

    return (
        <>
            {header ?? (
                <PageHeader
                    title="Configurações"
                    subtitle="Conta, padrões de assinatura, notificações e plano."
                />
            )}

            <div className="flex flex-wrap items-start gap-5">
                <aside className="hidden w-[200px] shrink-0 flex-col gap-4 md:flex">
                    {groups.map((group) => {
                        const items = group.items.filter(
                            (item) => !item.hidden,
                        );

                        if (items.length === 0) {
                            return null;
                        }

                        return (
                            <nav
                                key={group.label}
                                aria-label={group.label}
                                className="flex flex-col gap-0.5"
                            >
                                <span className="text-muted-foreground flex h-7 items-center px-3 text-[10.5px] font-bold tracking-[.14em] uppercase">
                                    {group.label}
                                </span>
                                {items.map((item) => (
                                    <Link
                                        key={item.key}
                                        href={item.href}
                                        prefetch
                                    >
                                        <RailNavButton
                                            active={isActive(item)}
                                            asChild
                                        >
                                            {item.title}
                                        </RailNavButton>
                                    </Link>
                                ))}
                            </nav>
                        );
                    })}
                </aside>

                <div className="w-full md:hidden">
                    <Select
                        value={activeItem?.key}
                        onValueChange={(key) => {
                            const target = visibleItems.find(
                                (i) => i.key === key,
                            );

                            if (target) {
                                window.location.assign(toUrl(target.href));
                            }
                        }}
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue placeholder="Seção" />
                        </SelectTrigger>
                        <SelectContent>
                            {visibleItems.map((item) => (
                                <SelectItem key={item.key} value={item.key}>
                                    {item.title}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <section className="flex min-w-0 flex-[1_1_480px] flex-col gap-4 md:max-w-[820px]">
                    {children}
                </section>
            </div>
        </>
    );
}
