import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Check,
    Crown,
    MoreHorizontal,
    RefreshCw,
    UserPlus,
    XCircle,
} from 'lucide-react';
import { useMemo, useState, type FormEvent } from 'react';
import { toast } from 'sonner';
import { AvatarInitials } from '@/components/avatar-initials';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DataTable, type DataTableColumn } from '@/components/data-table';
import { FilterBar, FilterChip, SearchInput } from '@/components/filter-bar';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { SegmentedControl } from '@/components/segmented-control';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatDateMedium, formatRelativeDateTime, plural } from '@/lib/format';
import {
    invitationStatusTones,
    membershipRoleDescriptions,
    membershipRoleLabels,
    membershipStatusTones,
} from '@/lib/labels';
import { cn } from '@/lib/utils';
import { index as billingIndex } from '@/routes/billing';
import {
    destroy as revokeInvitation,
    resend as resendInvitation,
    store as storeInvitation,
} from '@/routes/invitations';
import {
    destroy as removeMember,
    index as membersIndex,
    status as memberStatus,
    transfer_ownership as transferOwnership,
    update as updateMember,
} from '@/routes/members';
import type {
    Invitation,
    Membership,
    MembershipRole,
    PermissionMatrixRow,
    RoleDefinition,
    UserRef,
} from '@/types';

export interface MembersIndexProps {
    tab: 'members' | 'roles';
    seats: {
        used: number;
        limit: number | null;
        pending_invitations: number;
        available: number | null;
        plan_name: string;
    };
    filters: {
        q: string;
        role: MembershipRole | null;
        status: 'active' | 'suspended' | 'invited' | null;
    };
    members: Membership[];
    invitations: Invitation[];
    roles: RoleDefinition[];
    permission_matrix: PermissionMatrixRow[];
    folders: { id: string; name: string }[];
}

type Row =
    | { kind: 'member'; id: string; member: Membership }
    | { kind: 'invitation'; id: string; invitation: Invitation };

type PendingAction =
    | { type: 'remove'; member: Membership }
    | { type: 'suspend'; member: Membership }
    | { type: 'transfer'; member: Membership }
    | { type: 'demote'; member: Membership; role: MembershipRole }
    | { type: 'revoke'; invitation: Invitation }
    | null;

const ROLE_ORDER: MembershipRole[] = ['owner', 'admin', 'member'];

/** Usuários da conta (ROUTES §2.10; DESIGN §6.8). */
export default function MembersIndex({
    tab,
    seats,
    filters,
    members,
    invitations,
    roles,
    permission_matrix,
}: MembersIndexProps) {
    const [currentTab, setCurrentTab] = useState<'members' | 'roles'>(tab);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [pending, setPending] = useState<PendingAction>(null);
    const [busy, setBusy] = useState(false);

    const applyFilters = (next: Partial<MembersIndexProps['filters']>) => {
        router.get(
            membersIndex.url({
                query: { ...filters, ...next, tab: 'members' },
            }),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const rows = useMemo<Row[]>(() => {
        const list: Row[] = members.map((m) => ({
            kind: 'member',
            id: `m-${m.id}`,
            member: m,
        }));

        if (!filters.status || filters.status === 'invited') {
            invitations.forEach((i) =>
                list.push({
                    kind: 'invitation',
                    id: `i-${i.id}`,
                    invitation: i,
                }),
            );
        }

        if (filters.status === 'invited') {
            return list.filter((r) => r.kind === 'invitation');
        }

        return list;
    }, [members, invitations, filters.status]);

    const changeRole = (member: Membership, role: MembershipRole) => {
        if (role === member.role) {
            return;
        }

        if (member.role === 'admin' && role === 'member') {
            setPending({ type: 'demote', member, role });

            return;
        }

        router.patch(
            updateMember(Number(member.id)).url,
            { role },
            { preserveScroll: true },
        );
    };

    const runPending = () => {
        if (!pending) {
            return;
        }

        setBusy(true);
        const options = {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setPending(null);
            },
        };

        switch (pending.type) {
            case 'remove':
                router.delete(
                    removeMember(Number(pending.member.id)).url,
                    options,
                );
                break;
            case 'suspend':
                router.patch(
                    memberStatus(Number(pending.member.id)).url,
                    {
                        status:
                            pending.member.status === 'active'
                                ? 'suspended'
                                : 'active',
                    },
                    options,
                );
                break;
            case 'transfer':
                router.post(
                    transferOwnership(Number(pending.member.id)).url,
                    {},
                    options,
                );
                break;
            case 'demote':
                router.patch(
                    updateMember(Number(pending.member.id)).url,
                    { role: pending.role },
                    options,
                );
                break;
            case 'revoke':
                router.delete(
                    revokeInvitation(pending.invitation.id).url,
                    options,
                );
                break;
        }
    };

    const resend = (invitation: Invitation) => {
        router.post(
            resendInvitation(invitation.id).url,
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(`Convite reenviado para ${invitation.email}`),
            },
        );
    };

    const columns: DataTableColumn<Row>[] = [
        {
            key: 'user',
            header: 'Usuário',
            width: 'minmax(0,2.2fr)',
            cell: (row, index) =>
                row.kind === 'member' ? (
                    <div className="flex min-w-0 items-center gap-3">
                        <AvatarInitials
                            initials={row.member.user.initials}
                            index={index}
                            size="lg"
                        />
                        <span className="min-w-0">
                            <span className="block truncate font-semibold">
                                {row.member.user.name}
                                {row.member.is_me && (
                                    <span className="text-muted-foreground ml-1 font-medium">
                                        (você)
                                    </span>
                                )}
                            </span>
                            <span className="text-muted-foreground block truncate text-[12.5px]">
                                {row.member.user.email}
                            </span>
                        </span>
                    </div>
                ) : (
                    <div className="flex min-w-0 items-center gap-3">
                        <AvatarInitials initials="@" tone="neutral" size="lg" />
                        <span className="min-w-0">
                            <span className="block truncate font-semibold">
                                {row.invitation.email}
                            </span>
                            <span className="text-muted-foreground block truncate text-[12.5px]">
                                Convidado por {row.invitation.invited_by.name}
                            </span>
                        </span>
                    </div>
                ),
        },
        {
            key: 'role',
            header: 'Função',
            width: '1.2fr',
            cell: (row) => {
                if (row.kind === 'invitation') {
                    return (
                        <span className="text-text-secondary text-[13px]">
                            {row.invitation.role_label}
                        </span>
                    );
                }

                const { member } = row;

                if (member.role === 'owner' || !member.can.change_role) {
                    return (
                        <Badge
                            variant={
                                member.role === 'owner'
                                    ? 'planEnterprise'
                                    : 'draft'
                            }
                            className="gap-1"
                        >
                            {member.role === 'owner' && (
                                <Crown className="size-3" />
                            )}
                            {member.role_label}
                        </Badge>
                    );
                }

                return (
                    <Select
                        value={member.role}
                        onValueChange={(v) =>
                            changeRole(member, v as MembershipRole)
                        }
                    >
                        <SelectTrigger
                            size="sm"
                            className="h-7 rounded-md px-2.5 text-[12px] font-semibold"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {roles
                                .filter((r) => r.key !== 'owner')
                                .map((r) => (
                                    <SelectItem key={r.key} value={r.key}>
                                        {r.label}
                                    </SelectItem>
                                ))}
                        </SelectContent>
                    </Select>
                );
            },
        },
        {
            key: 'status',
            header: 'Status',
            width: '1.2fr',
            cell: (row) =>
                row.kind === 'member' ? (
                    <Badge
                        variant={membershipStatusTones[row.member.status]}
                        dot
                    >
                        {row.member.status_label}
                    </Badge>
                ) : (
                    <Badge variant={invitationStatusTones.pending} dot>
                        Convite pendente
                    </Badge>
                ),
        },
        {
            key: 'tfa',
            header: '2FA',
            width: '.9fr',
            cell: (row) =>
                row.kind === 'invitation' ? (
                    <span className="text-muted-foreground text-[13px]">—</span>
                ) : (
                    <span
                        className={cn(
                            'text-[13px] font-semibold',
                            row.member.two_factor_enabled
                                ? 'text-success'
                                : 'text-danger',
                        )}
                    >
                        {row.member.two_factor_enabled ? 'Ativa' : 'Inativa'}
                    </span>
                ),
        },
        {
            key: 'last',
            header: 'Último acesso',
            width: '1.1fr',
            cell: (row) => (
                <span className="text-text-secondary tabular text-[13px] whitespace-nowrap">
                    {row.kind === 'member'
                        ? formatRelativeDateTime(row.member.last_seen_at)
                        : `Convite enviado ${formatDateMedium(row.invitation.sent_at)}`}
                </span>
            ),
        },
        {
            key: 'actions',
            header: '',
            width: '44px',
            align: 'right',
            cell: (row) => (
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
                        {row.kind === 'invitation' ? (
                            <>
                                <DropdownMenuItem
                                    onSelect={() => resend(row.invitation)}
                                >
                                    <RefreshCw className="size-3.5" />
                                    Reenviar convite
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    onSelect={() =>
                                        setPending({
                                            type: 'revoke',
                                            invitation: row.invitation,
                                        })
                                    }
                                >
                                    <XCircle className="size-3.5" />
                                    Revogar convite
                                </DropdownMenuItem>
                            </>
                        ) : (
                            <>
                                {row.member.can.suspend && (
                                    <DropdownMenuItem
                                        onSelect={() =>
                                            setPending({
                                                type: 'suspend',
                                                member: row.member,
                                            })
                                        }
                                    >
                                        {row.member.status === 'active'
                                            ? 'Suspender acesso'
                                            : 'Reativar acesso'}
                                    </DropdownMenuItem>
                                )}
                                {row.member.can.transfer_ownership && (
                                    <DropdownMenuItem
                                        onSelect={() =>
                                            setPending({
                                                type: 'transfer',
                                                member: row.member,
                                            })
                                        }
                                    >
                                        <Crown className="size-3.5" />
                                        Transferir propriedade
                                    </DropdownMenuItem>
                                )}
                                {row.member.can.remove && (
                                    <>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            variant="destructive"
                                            onSelect={() =>
                                                setPending({
                                                    type: 'remove',
                                                    member: row.member,
                                                })
                                            }
                                        >
                                            Remover da organização
                                        </DropdownMenuItem>
                                    </>
                                )}
                                {!row.member.can.suspend &&
                                    !row.member.can.transfer_ownership &&
                                    !row.member.can.remove && (
                                        <DropdownMenuItem disabled>
                                            Nenhuma ação disponível
                                        </DropdownMenuItem>
                                    )}
                            </>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            ),
        },
    ];

    const subtitle = [
        seats.limit !== null
            ? `${seats.used} de ${seats.limit} assentos do plano ${seats.plan_name} em uso`
            : `${plural(seats.used, 'usuário')} · plano ${seats.plan_name}`,
        seats.pending_invitations > 0
            ? plural(
                  seats.pending_invitations,
                  'convite pendente',
                  'convites pendentes',
              )
            : null,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <>
            <Head title="Usuários" />
            <PageHeader
                title="Usuários da conta"
                subtitle={subtitle}
                actions={
                    <Button
                        onClick={() => setInviteOpen(true)}
                        disabled={seats.available === 0}
                    >
                        <UserPlus className="size-[15px]" />
                        Convidar usuário
                    </Button>
                }
            />

            <SegmentedControl
                value={currentTab}
                onChange={setCurrentTab}
                options={[
                    { value: 'members', label: `Membros (${members.length})` },
                    { value: 'roles', label: 'Funções e permissões' },
                ]}
            />

            {currentTab === 'members' ? (
                <div className="border-border bg-card shadow-card rounded-xl border">
                    <FilterBar>
                        <SearchInput
                            value={filters.q}
                            onChange={(q) => applyFilters({ q })}
                            placeholder="Buscar por nome ou e-mail"
                        />
                        <FilterChip
                            label="Função"
                            value={filters.role}
                            onChange={(role) =>
                                applyFilters({
                                    role: role as MembershipRole | null,
                                })
                            }
                            options={ROLE_ORDER.map((r) => ({
                                value: r,
                                label: membershipRoleLabels[r],
                            }))}
                            allLabel="Todas"
                        />
                        <FilterChip
                            label="Status"
                            value={filters.status}
                            onChange={(status) =>
                                applyFilters({
                                    status: status as MembersIndexProps['filters']['status'],
                                })
                            }
                            options={[
                                { value: 'active', label: 'Ativo' },
                                { value: 'suspended', label: 'Inativo' },
                                { value: 'invited', label: 'Convite pendente' },
                            ]}
                        />
                    </FilterBar>
                    <DataTable
                        columns={columns}
                        rows={rows}
                        rowKey={(r) => r.id}
                        minWidth={760}
                    />
                    <div className="text-muted-foreground flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-[12.5px]">
                        <span>
                            {plural(members.length, 'usuário')}
                            {seats.available !== null &&
                                ` · ${plural(seats.available, 'assento disponível', 'assentos disponíveis')}`}
                        </span>
                        <Link
                            href={billingIndex()}
                            className="text-primary hover:text-primary-hover font-semibold"
                        >
                            Adicionar assentos ao plano
                        </Link>
                    </div>
                </div>
            ) : (
                <div className="border-border bg-card shadow-card rounded-xl border">
                    <div className="flex flex-wrap items-start justify-between gap-3 px-5 pt-[18px] pb-3">
                        <div>
                            <div className="text-[15px] font-semibold">
                                Funções e permissões
                            </div>
                            <div className="text-muted-foreground mt-1 text-[13px]">
                                Funções padrão do sistema. Funções
                                personalizadas chegam na Fase 2.
                            </div>
                        </div>
                        <Badge variant="phase">
                            Funções personalizadas · Fase 2
                        </Badge>
                    </div>
                    <div className="overflow-x-auto">
                        <div style={{ minWidth: 640 }}>
                            <div
                                className="border-muted bg-background text-muted-foreground grid h-10 items-center border-y px-5 text-[12px] font-semibold"
                                style={{
                                    gridTemplateColumns: `minmax(0,2.4fr) repeat(${ROLE_ORDER.length}, minmax(0,1fr))`,
                                }}
                            >
                                <span>Permissão</span>
                                {ROLE_ORDER.map((role) => (
                                    <span key={role} className="text-center">
                                        {membershipRoleLabels[role]}
                                    </span>
                                ))}
                            </div>
                            {permission_matrix.map((row) => (
                                <div
                                    key={row.key}
                                    className="border-muted hover:bg-row-hover grid items-center border-b px-5 py-[11px] last:border-b-0"
                                    style={{
                                        gridTemplateColumns: `minmax(0,2.4fr) repeat(${ROLE_ORDER.length}, minmax(0,1fr))`,
                                    }}
                                >
                                    <span className="min-w-0 pr-3">
                                        <span className="block text-[13.5px] font-medium">
                                            {row.label}
                                        </span>
                                        <span className="text-muted-foreground block text-[12px]">
                                            {row.description}
                                        </span>
                                    </span>
                                    {ROLE_ORDER.map((role) => (
                                        <span
                                            key={role}
                                            className="flex justify-center"
                                        >
                                            <span
                                                className={cn(
                                                    'flex size-[22px] items-center justify-center rounded-md text-[12px] font-bold',
                                                    row.grants[role]
                                                        ? 'bg-success-bg text-success'
                                                        : 'bg-muted text-muted-foreground',
                                                )}
                                            >
                                                {row.grants[role] ? (
                                                    <Check className="size-3.5 stroke-[2.5]" />
                                                ) : (
                                                    '–'
                                                )}
                                            </span>
                                        </span>
                                    ))}
                                </div>
                            ))}
                        </div>
                    </div>
                    <div className="grid gap-3 px-5 py-4 sm:grid-cols-3">
                        {roles.map((role) => (
                            <div
                                key={role.key}
                                className="border-border rounded-[10px] border p-3"
                            >
                                <div className="text-[13px] font-semibold">
                                    {role.label}
                                </div>
                                <div className="text-muted-foreground mt-0.5 text-[12px] leading-[1.5]">
                                    {role.description ||
                                        membershipRoleDescriptions[role.key]}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            <InviteDialog
                open={inviteOpen}
                onOpenChange={setInviteOpen}
                seats={seats}
                roles={roles}
            />

            <ConfirmDialog
                open={pending !== null}
                onOpenChange={(open) => !open && setPending(null)}
                processing={busy}
                destructive={
                    pending?.type === 'remove' ||
                    pending?.type === 'revoke' ||
                    (pending?.type === 'suspend' &&
                        pending.member.status === 'active')
                }
                title={
                    pending?.type === 'remove'
                        ? `Remover ${pending.member.user.name}?`
                        : pending?.type === 'suspend'
                          ? pending.member.status === 'active'
                              ? `Suspender o acesso de ${pending.member.user.name}?`
                              : `Reativar o acesso de ${pending.member.user.name}?`
                          : pending?.type === 'transfer'
                            ? `Transferir a propriedade para ${pending.member.user.name}?`
                            : pending?.type === 'demote'
                              ? `Rebaixar ${pending.member.user.name} para ${membershipRoleLabels[pending.role]}?`
                              : pending?.type === 'revoke'
                                ? `Revogar o convite de ${pending.invitation.email}?`
                                : ''
                }
                description={
                    pending?.type === 'remove'
                        ? 'O usuário perde o acesso imediatamente. Os documentos criados por ele permanecem na organização.'
                        : pending?.type === 'suspend'
                          ? pending.member.status === 'active'
                              ? 'O usuário não conseguirá entrar até ser reativado. Nada é excluído.'
                              : 'O usuário volta a acessar a organização normalmente.'
                          : pending?.type === 'transfer'
                            ? 'Você deixará de ser o proprietário e passará a Administrador. Esta ação exige confirmação de senha.'
                            : pending?.type === 'demote'
                              ? 'Administradores rebaixados perdem acesso a usuários, configurações e cobrança.'
                              : pending?.type === 'revoke'
                                ? 'O link do convite deixa de funcionar. Você pode convidar novamente depois.'
                                : ''
                }
                confirmLabel={
                    pending?.type === 'remove'
                        ? 'Remover'
                        : pending?.type === 'suspend'
                          ? pending.member.status === 'active'
                              ? 'Suspender'
                              : 'Reativar'
                          : pending?.type === 'transfer'
                            ? 'Transferir propriedade'
                            : pending?.type === 'revoke'
                              ? 'Revogar convite'
                              : 'Confirmar'
                }
                confirmText={
                    pending?.type === 'transfer'
                        ? pending.member.user.email
                        : undefined
                }
                onConfirm={runPending}
            />
        </>
    );
}

function InviteDialog({
    open,
    onOpenChange,
    seats,
    roles,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    seats: MembersIndexProps['seats'];
    roles: RoleDefinition[];
}) {
    const form = useForm<{ emails: string; role: 'admin' | 'member' }>({
        emails: '',
        role: 'member',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const emails = form.data.emails
            .split(/[,\s;]+/)
            .map((e) => e.trim())
            .filter(Boolean);

        form.transform((data) => ({ ...data, emails }));
        form.post(storeInvitation.url(), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(
                    `Convite enviado para ${plural(emails.length, 'e-mail', 'e-mails')}`,
                );
                form.reset();
                onOpenChange(false);
            },
        });
    };

    const errors = form.errors as Record<string, string | undefined>;
    const emailErrors = Object.entries(errors)
        .filter(([key]) => key.startsWith('emails'))
        .map(([, value]) => value)
        .filter(Boolean)
        .join(' ');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Convidar usuário</DialogTitle>
                    <DialogDescription>
                        O convite expira em 7 dias.
                        {seats.available !== null &&
                            ` Você tem ${plural(seats.available, 'assento disponível', 'assentos disponíveis')}.`}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="invite-emails">E-mails</Label>
                        <Textarea
                            id="invite-emails"
                            rows={2}
                            value={form.data.emails}
                            onChange={(e) =>
                                form.setData('emails', e.target.value)
                            }
                            placeholder="nome@empresa.com.br, outro@empresa.com.br"
                            autoFocus
                            required
                            aria-invalid={!!emailErrors}
                        />
                        <span className="text-muted-foreground text-[12px]">
                            Separe vários e-mails por vírgula.
                        </span>
                        <InputError message={emailErrors || undefined} />
                        <InputError message={errors.seats} />
                        {errors.seats && (
                            <Link
                                href={billingIndex()}
                                className="text-primary text-[12.5px] font-semibold hover:underline"
                            >
                                Adicionar assentos ao plano
                            </Link>
                        )}
                    </div>
                    <div className="grid gap-1.5">
                        <span className="text-[13px] font-semibold">
                            Função
                        </span>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {roles
                                .filter((r) => r.key !== 'owner')
                                .map((role) => {
                                    const selected =
                                        form.data.role === role.key;

                                    return (
                                        <button
                                            key={role.key}
                                            type="button"
                                            onClick={() =>
                                                form.setData(
                                                    'role',
                                                    role.key as
                                                        | 'admin'
                                                        | 'member',
                                                )
                                            }
                                            aria-pressed={selected}
                                            className={cn(
                                                'rounded-lg border p-2.5 text-left transition-colors',
                                                selected
                                                    ? 'border-primary bg-primary-soft'
                                                    : 'border-border hover:bg-accent-subtle bg-white',
                                            )}
                                        >
                                            <span className="block text-[13px] font-semibold">
                                                {role.label}
                                            </span>
                                            <span className="text-muted-foreground block text-[12px] leading-[1.45]">
                                                {role.description ||
                                                    membershipRoleDescriptions[
                                                        role.key
                                                    ]}
                                            </span>
                                        </button>
                                    );
                                })}
                        </div>
                        <InputError message={errors.role} />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            disabled={form.processing}
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                form.processing || !form.data.emails.trim()
                            }
                        >
                            {form.processing && <Spinner />}
                            Enviar convite
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export type { UserRef };

MembersIndex.layout = {
    breadcrumbs: [{ title: 'Usuários', href: membersIndex() }],
};
