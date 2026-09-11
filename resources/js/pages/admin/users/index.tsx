import { Head, Link, router } from '@inertiajs/react';
import { Ban, MoreHorizontal, ShieldCheck, Unlock } from 'lucide-react';
import { useState } from 'react';
import { AvatarInitials } from '@/components/avatar-initials';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { useConfirmsPassword } from '@/components/confirms-password';
import { DataTable, type DataTableColumn } from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { FilterBar, FilterChip, SearchInput } from '@/components/filter-bar';
import { KpiCard, KpiGrid } from '@/components/kpi-card';
import { PageHeader } from '@/components/page-header';
import { TablePagination } from '@/components/table-pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    formatDateMedium,
    formatNumber,
    formatRelativeDateTime,
} from '@/lib/format';
import { show as adminOrganizationShow } from '@/routes/admin/organizations';
import {
    block as blockUser,
    index as adminUsers,
    unblock as unblockUser,
} from '@/routes/admin/users';
import type { Paginated } from '@/types';

type Status = 'all' | 'active' | 'blocked' | 'platform_admin';

interface PlatformUser {
    id: string;
    name: string;
    email: string;
    initials: string;
    is_platform_admin: boolean;
    two_factor_enabled: boolean;
    email_verified: boolean;
    created_at: string | null;
    last_seen_at: string | null;
    blocked: boolean;
    blocked_at: string | null;
    blocked_reason: string | null;
    organizations: {
        id: string;
        name: string;
        role_label: string;
        active: boolean;
    }[];
    can: { block: boolean; unblock: boolean };
}

export interface AdminUsersProps {
    filters: { q: string; status: Status };
    summary: {
        total: number;
        blocked: number;
        platform_admins: number;
        without_2fa: number;
    };
    users: Paginated<PlatformUser>;
}

const STATUS_OPTIONS = [
    { value: 'active', label: 'Ativas' },
    { value: 'blocked', label: 'Bloqueadas' },
    { value: 'platform_admin', label: 'Equipe AssinaVelox' },
];

type Pending = { type: 'block' | 'unblock'; user: PlatformUser } | null;

/**
 * Painel interno › Usuários da plataforma (Fase 2 — flag `admin_users`).
 * Bloquear/desbloquear exige senha confirmada e motivo; tudo fica em
 * "Logs e auditoria". Sem acesso a conteúdo de documentos.
 */
export default function AdminUsersIndex({
    filters,
    summary,
    users,
}: AdminUsersProps) {
    const [pending, setPending] = useState<Pending>(null);
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);
    const password = useConfirmsPassword({
        description:
            'Bloquear ou desbloquear contas é uma ação protegida. Confirme sua senha para continuar.',
    });

    const apply = (next: Partial<AdminUsersProps['filters']>) => {
        router.get(
            adminUsers.url({ query: { ...filters, ...next, page: undefined } }),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const close = () => {
        setPending(null);
        setReason('');
        setError(undefined);
    };

    const confirm = () => {
        if (!pending) {
            return;
        }

        const target = pending;

        password.ensure(() => {
            setBusy(true);
            router.post(
                target.type === 'block'
                    ? blockUser.url(Number(target.user.id))
                    : unblockUser.url(Number(target.user.id)),
                { reason },
                {
                    preserveScroll: true,
                    onSuccess: close,
                    onError: (errors) => setError(errors.reason),
                    onFinish: () => setBusy(false),
                },
            );
        });
    };

    const columns: DataTableColumn<PlatformUser>[] = [
        {
            key: 'user',
            header: 'Usuário',
            width: 'minmax(0,2.2fr)',
            cell: (user, index) => (
                <div className="flex min-w-0 items-center gap-3">
                    <AvatarInitials
                        initials={user.initials}
                        index={index}
                        size="lg"
                        tone={user.blocked ? 'neutral' : 'palette'}
                    />
                    <span className="min-w-0">
                        <span className="flex items-center gap-1.5">
                            <span className="truncate font-semibold">
                                {user.name}
                            </span>
                            {user.is_platform_admin && (
                                <ShieldCheck
                                    className="text-primary size-3.5 shrink-0"
                                    aria-label="Equipe AssinaVelox"
                                />
                            )}
                        </span>
                        <span className="text-muted-foreground block truncate text-[12.5px]">
                            {user.email}
                        </span>
                    </span>
                </div>
            ),
        },
        {
            key: 'orgs',
            header: 'Organizações',
            width: 'minmax(0,1.6fr)',
            cell: (user) =>
                user.organizations.length === 0 ? (
                    <span className="text-muted-foreground text-[13px]">—</span>
                ) : (
                    <span className="flex min-w-0 flex-col gap-0.5 text-[12.5px]">
                        {user.organizations.slice(0, 2).map((org) => (
                            <Link
                                key={org.id}
                                href={adminOrganizationShow(org.id)}
                                className="text-text-secondary hover:text-primary truncate"
                            >
                                {org.name}{' '}
                                <span className="text-muted-foreground">
                                    · {org.role_label}
                                    {!org.active && ' (inativo)'}
                                </span>
                            </Link>
                        ))}
                        {user.organizations.length > 2 && (
                            <span className="text-muted-foreground">
                                +{user.organizations.length - 2}
                            </span>
                        )}
                    </span>
                ),
        },
        {
            key: 'last',
            header: 'Último acesso',
            width: '1fr',
            cell: (user) => (
                <span className="text-text-secondary tabular text-[13px]">
                    {formatRelativeDateTime(user.last_seen_at)}
                </span>
            ),
        },
        {
            key: 'tfa',
            header: '2FA',
            width: '.7fr',
            cell: (user) => (
                <span
                    className={
                        user.two_factor_enabled
                            ? 'text-success text-[13px] font-semibold'
                            : 'text-danger text-[13px] font-semibold'
                    }
                >
                    {user.two_factor_enabled ? 'Ativa' : 'Inativa'}
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            width: '1fr',
            cell: (user) =>
                user.blocked ? (
                    <span className="flex flex-col gap-0.5">
                        <Badge variant="danger" dot>
                            Bloqueada
                        </Badge>
                        {user.blocked_at && (
                            <span
                                className="text-muted-foreground truncate text-[11.5px]"
                                title={user.blocked_reason ?? undefined}
                            >
                                desde {formatDateMedium(user.blocked_at)}
                            </span>
                        )}
                    </span>
                ) : (
                    <Badge variant="success" dot>
                        Ativa
                    </Badge>
                ),
        },
        {
            key: 'actions',
            header: '',
            width: '44px',
            align: 'right',
            cell: (user) =>
                (user.can.block || user.can.unblock) && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon-xs"
                                aria-label="Ações"
                            >
                                <MoreHorizontal className="text-muted-foreground size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            {user.can.block && (
                                <DropdownMenuItem
                                    variant="destructive"
                                    onSelect={() =>
                                        setPending({ type: 'block', user })
                                    }
                                >
                                    <Ban className="size-3.5" />
                                    Bloquear conta
                                </DropdownMenuItem>
                            )}
                            {user.can.unblock && (
                                <DropdownMenuItem
                                    onSelect={() =>
                                        setPending({ type: 'unblock', user })
                                    }
                                >
                                    <Unlock className="size-3.5" />
                                    Desbloquear conta
                                </DropdownMenuItem>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                ),
        },
    ];

    return (
        <>
            <Head title="Usuários da plataforma" />
            <PageHeader
                title="Usuários da plataforma"
                subtitle="Todas as contas, com organizações, último acesso, 2FA e bloqueio."
            />

            <KpiGrid min={150}>
                <KpiCard
                    variant="compact"
                    label="Contas"
                    value={formatNumber(summary.total)}
                />
                <KpiCard
                    variant="compact"
                    label="Bloqueadas"
                    value={formatNumber(summary.blocked)}
                    captionTone="danger"
                    caption={summary.blocked > 0 ? 'sem acesso' : 'nenhuma'}
                />
                <KpiCard
                    variant="compact"
                    label="Sem 2FA"
                    value={formatNumber(summary.without_2fa)}
                    captionTone="warning"
                    caption="autenticação em duas etapas inativa"
                />
                <KpiCard
                    variant="compact"
                    label="Equipe AssinaVelox"
                    value={formatNumber(summary.platform_admins)}
                />
            </KpiGrid>

            <div className="border-border bg-card shadow-card rounded-xl border">
                <FilterBar>
                    <SearchInput
                        value={filters.q}
                        onChange={(q) => apply({ q })}
                        placeholder="Buscar por nome ou e-mail"
                    />
                    <FilterChip
                        label="Status"
                        value={filters.status === 'all' ? null : filters.status}
                        options={STATUS_OPTIONS}
                        onChange={(status) =>
                            apply({ status: (status ?? 'all') as Status })
                        }
                        allLabel="Todas"
                    />
                </FilterBar>
                <DataTable
                    columns={columns}
                    rows={users.data}
                    rowKey={(user) => user.id}
                    minWidth={900}
                    empty={
                        <EmptyState
                            variant="inline"
                            title="Nenhuma conta encontrada."
                        />
                    }
                />
                <TablePagination
                    paginated={users}
                    entity="contas"
                    entitySingular="conta"
                    gender="f"
                    showPerPage={false}
                />
            </div>

            <ConfirmDialog
                open={pending !== null}
                onOpenChange={(open) => !open && close()}
                destructive={pending?.type === 'block'}
                processing={busy}
                disabled={pending?.type === 'block' && reason.trim().length < 5}
                title={
                    pending?.type === 'block'
                        ? `Bloquear a conta de ${pending.user.name}?`
                        : `Desbloquear a conta de ${pending?.user.name ?? ''}?`
                }
                description={
                    pending?.type === 'block'
                        ? 'A pessoa é desconectada de todas as sessões e não consegue entrar até ser desbloqueada. Documentos e organizações não mudam.'
                        : 'A pessoa volta a conseguir entrar. O desbloqueio fica registrado.'
                }
                confirmLabel={
                    pending?.type === 'block'
                        ? 'Bloquear conta'
                        : 'Desbloquear conta'
                }
                onConfirm={confirm}
            >
                <div className="grid gap-1.5">
                    <Label htmlFor="block-reason">
                        {pending?.type === 'block'
                            ? 'Motivo (obrigatório)'
                            : 'Observação (opcional)'}
                    </Label>
                    <Textarea
                        id="block-reason"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        rows={3}
                        maxLength={500}
                        aria-invalid={!!error}
                    />
                    {error && (
                        <p className="text-danger text-[12.5px]">{error}</p>
                    )}
                </div>
            </ConfirmDialog>

            {password.dialog}
        </>
    );
}

AdminUsersIndex.layout = {
    breadcrumbs: [{ title: 'Usuários da plataforma', href: adminUsers() }],
};
