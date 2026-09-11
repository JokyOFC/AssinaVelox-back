import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Check,
    Crown,
    FolderLock,
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
import { FolderAccessDialog } from '@/components/permissions/folder-access-dialog';
import { FolderAccessPicker } from '@/components/permissions/folder-access-picker';
import { RoleDialog } from '@/components/permissions/role-dialog';
import { RoleMatrix } from '@/components/permissions/role-matrix';
import { TeamDialog } from '@/components/permissions/team-dialog';
import { TeamsPanel } from '@/components/permissions/teams-panel';
import {
    rolePayload,
    roleValue,
    type FolderGrant,
    type FolderOption,
    type MemberRow,
    type PermissionCatalogGroup,
    type RoleEntry,
    type TeamEntry,
} from '@/components/permissions/types';
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
    folders as memberFolders,
    index as membersIndex,
    status as memberStatus,
    transfer_ownership as transferOwnership,
    update as updateMember,
} from '@/routes/members';
import { destroy as destroyRole, folders as roleFolders } from '@/routes/roles';
import { destroy as destroyTeam } from '@/routes/teams';
import type {
    Invitation,
    MembershipRole,
    PermissionMatrixRow,
    RoleDefinition,
    UserRef,
} from '@/types';

type Tab = 'members' | 'roles' | 'teams';

export interface MembersIndexProps {
    tab: Tab;
    seats: {
        used: number;
        limit: number | null;
        pending_invitations: number;
        available: number | null;
        plan_name: string;
    };
    filters: {
        q: string;
        role: string | null;
        status: 'active' | 'suspended' | 'invited' | null;
    };
    members: MemberRow[];
    invitations: (Invitation & { role_id?: string | null })[];
    roles: RoleDefinition[];
    permission_matrix: PermissionMatrixRow[];
    folders: FolderOption[];
    // Fase 2 — funções personalizadas, times e acesso por pasta.
    custom_roles_enabled: boolean;
    permission_catalog: PermissionCatalogGroup[];
    role_catalog: RoleEntry[];
    teams: TeamEntry[];
    can: {
        create_role: boolean;
        create_team: boolean;
        manage_folder_access: boolean;
    };
}

type Row =
    | { kind: 'member'; id: string; member: MemberRow }
    | {
          kind: 'invitation';
          id: string;
          invitation: MembersIndexProps['invitations'][number];
      };

type PendingAction =
    | { type: 'remove'; member: MemberRow }
    | { type: 'suspend'; member: MemberRow }
    | { type: 'transfer'; member: MemberRow }
    | { type: 'demote'; member: MemberRow; value: string }
    | { type: 'revoke'; invitation: Invitation }
    | { type: 'delete-role'; role: RoleEntry }
    | { type: 'delete-team'; team: TeamEntry }
    | null;

type FolderTarget = {
    title: string;
    description: string;
    url: string;
    initial: FolderGrant[];
} | null;

const ROLE_ORDER: MembershipRole[] = ['owner', 'admin', 'member'];

/** Usuários da conta (ROUTES §2.10; DESIGN §6.8; Fase 2 §2.14). */
export default function MembersIndex({
    tab,
    seats,
    filters,
    members,
    invitations,
    roles,
    permission_matrix,
    folders,
    custom_roles_enabled: customRoles,
    permission_catalog,
    role_catalog,
    teams,
    can,
}: MembersIndexProps) {
    const { organization } = usePage().props;
    const held = (organization?.permissions ?? {}) as unknown as Record<
        string,
        boolean
    >;

    const [currentTab, setCurrentTab] = useState<Tab>(
        tab === 'teams' && !customRoles ? 'members' : tab,
    );
    const [inviteOpen, setInviteOpen] = useState(false);
    const [pending, setPending] = useState<PendingAction>(null);
    const [busy, setBusy] = useState(false);
    const [roleDialog, setRoleDialog] = useState<{
        open: boolean;
        role: RoleEntry | null;
    }>({ open: false, role: null });
    const [teamDialog, setTeamDialog] = useState<{
        open: boolean;
        team: TeamEntry | null;
    }>({ open: false, team: null });
    const [folderTarget, setFolderTarget] = useState<FolderTarget>(null);

    /** Funções que aparecem no seletor (atribuíveis por quem está vendo). */
    const assignable = useMemo<
        { value: string; label: string; description: string }[]
    >(() => {
        if (!customRoles) {
            return roles
                .filter((r) => r.key !== 'owner')
                .map((r) => ({
                    value: r.key,
                    label: r.label,
                    description:
                        r.description || membershipRoleDescriptions[r.key],
                }));
        }

        return role_catalog
            .filter((r) => r.key !== 'owner' && r.can.assign)
            .map((r) => ({
                value: roleValue(r),
                label: r.name,
                description:
                    r.description ||
                    (r.key ? membershipRoleDescriptions[r.key] : '') ||
                    plural(r.permissions.length, 'permissão', 'permissões'),
            }));
    }, [customRoles, roles, role_catalog]);

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

    const memberValue = (member: MemberRow) => member.role_id ?? member.role;

    const changeRole = (member: MemberRow, value: string) => {
        if (value === memberValue(member)) {
            return;
        }

        if (member.role === 'admin' && value !== 'admin') {
            setPending({ type: 'demote', member, value });

            return;
        }

        router.patch(updateMember(Number(member.id)).url, rolePayload(value), {
            preserveScroll: true,
        });
    };

    const labelFor = (value: string) =>
        assignable.find((o) => o.value === value)?.label ??
        membershipRoleLabels[value as MembershipRole] ??
        value;

    const runPending = () => {
        if (!pending) {
            return;
        }

        setBusy(true);
        const options = {
            preserveScroll: true,
            // Recusas do servidor (ex.: excluir uma função que ainda tem pessoas ou convites)
            // chegam como erro de validação: sem isto a confirmação fecharia em silêncio.
            onError: (errors: Record<string, string>) => {
                const first = Object.values(errors)[0];

                if (first) {
                    toast.error(first);
                }
            },
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
                    rolePayload(pending.value),
                    options,
                );
                break;
            case 'revoke':
                router.delete(
                    revokeInvitation(pending.invitation.id).url,
                    options,
                );
                break;
            case 'delete-role':
                router.delete(destroyRole(pending.role.id).url, options);
                break;
            case 'delete-team':
                router.delete(destroyTeam(pending.team.id).url, options);
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

    const openMemberFolders = (member: MemberRow) =>
        setFolderTarget({
            title: `Pastas com acesso — ${member.user.name}`,
            description:
                'Além dos próprios documentos e do que a função e os times já liberam, a pessoa verá os documentos destas pastas.',
            url: memberFolders(Number(member.id)).url,
            initial: member.folders ?? [],
        });

    const openRoleFolders = (role: RoleEntry) =>
        setFolderTarget({
            title: `Pastas com acesso — ${role.name}`,
            description:
                'Todos que têm esta função passam a ver os documentos destas pastas.',
            url: roleFolders(role.id).url,
            initial: role.folders,
        });

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
                            {customRoles &&
                                (row.member.teams?.length ?? 0) > 0 && (
                                    <span className="text-text-secondary block truncate text-[12px]">
                                        {row.member
                                            .teams!.map((t) => t.name)
                                            .join(', ')}
                                    </span>
                                )}
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
                const value = memberValue(member);
                const selectable =
                    member.role !== 'owner' &&
                    member.can.change_role &&
                    assignable.length > 0;

                if (!selectable) {
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

                const options = assignable.some((o) => o.value === value)
                    ? assignable
                    : [
                          { value, label: member.role_label, description: '' },
                          ...assignable,
                      ];

                return (
                    <Select
                        value={value}
                        onValueChange={(v) => changeRole(member, v)}
                    >
                        <SelectTrigger
                            size="sm"
                            className="h-7 max-w-full rounded-md px-2.5 text-[12px] font-semibold"
                            aria-label={`Função de ${member.user.name}`}
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {options.map((o) => (
                                <SelectItem key={o.value} value={o.value}>
                                    {o.label}
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
                                {row.member.can.manage_folders && (
                                    <DropdownMenuItem
                                        onSelect={() =>
                                            openMemberFolders(row.member)
                                        }
                                    >
                                        <FolderLock className="size-3.5" />
                                        Pastas com acesso
                                        {(row.member.folders?.length ?? 0) >
                                            0 &&
                                            ` (${row.member.folders!.length})`}
                                    </DropdownMenuItem>
                                )}
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
                                    !row.member.can.remove &&
                                    !row.member.can.manage_folders && (
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

    const roleFilterOptions = customRoles
        ? role_catalog.map((r) => ({ value: roleValue(r), label: r.name }))
        : ROLE_ORDER.map((r) => ({ value: r, label: membershipRoleLabels[r] }));

    const tabOptions: { value: Tab; label: string }[] = [
        { value: 'members', label: `Membros (${members.length})` },
        { value: 'roles', label: 'Funções e permissões' },
        ...(customRoles
            ? [{ value: 'teams' as Tab, label: `Times (${teams.length})` }]
            : []),
    ];

    const pendingCopy = pendingDialogCopy(pending, labelFor);

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
                options={tabOptions}
            />

            {currentTab === 'members' && (
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
                            onChange={(role) => applyFilters({ role })}
                            options={roleFilterOptions}
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
            )}

            {currentTab === 'roles' &&
                (customRoles ? (
                    <RoleMatrix
                        catalog={permission_catalog}
                        roles={role_catalog}
                        held={held}
                        canCreate={can.create_role}
                        onCreate={() =>
                            setRoleDialog({ open: true, role: null })
                        }
                        onEdit={(role) => setRoleDialog({ open: true, role })}
                        onDelete={(role) =>
                            setPending({ type: 'delete-role', role })
                        }
                        onFolders={openRoleFolders}
                    />
                ) : (
                    <SystemRolesMatrix
                        roles={roles}
                        matrix={permission_matrix}
                    />
                ))}

            {currentTab === 'teams' && customRoles && (
                <TeamsPanel
                    teams={teams}
                    members={members}
                    canCreate={can.create_team}
                    onCreate={() => setTeamDialog({ open: true, team: null })}
                    onEdit={(team) => setTeamDialog({ open: true, team })}
                    onDelete={(team) =>
                        setPending({ type: 'delete-team', team })
                    }
                />
            )}

            <InviteDialog
                open={inviteOpen}
                onOpenChange={setInviteOpen}
                seats={seats}
                options={assignable}
                roleCatalog={customRoles ? role_catalog : []}
                folders={folders}
                showFolders={customRoles && can.manage_folder_access}
            />

            {customRoles && (
                <>
                    <RoleDialog
                        open={roleDialog.open}
                        onOpenChange={(open) =>
                            setRoleDialog((s) => ({ ...s, open }))
                        }
                        role={roleDialog.role}
                        catalog={permission_catalog}
                        held={held}
                    />
                    <TeamDialog
                        open={teamDialog.open}
                        onOpenChange={(open) =>
                            setTeamDialog((s) => ({ ...s, open }))
                        }
                        team={teamDialog.team}
                        members={members}
                        folders={folders}
                        canManageFolders={can.manage_folder_access}
                    />
                    <FolderAccessDialog
                        open={folderTarget !== null}
                        onOpenChange={(open) => !open && setFolderTarget(null)}
                        title={folderTarget?.title ?? ''}
                        description={folderTarget?.description ?? ''}
                        url={folderTarget?.url ?? ''}
                        folders={folders}
                        initial={folderTarget?.initial ?? []}
                    />
                </>
            )}

            <ConfirmDialog
                open={pending !== null}
                onOpenChange={(open) => !open && setPending(null)}
                processing={busy}
                destructive={pendingCopy.destructive}
                title={pendingCopy.title}
                description={pendingCopy.description}
                confirmLabel={pendingCopy.confirmLabel}
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

function pendingDialogCopy(
    pending: PendingAction,
    labelFor: (value: string) => string,
): {
    title: string;
    description: string;
    confirmLabel: string;
    destructive: boolean;
} {
    switch (pending?.type) {
        case 'remove':
            return {
                title: `Remover ${pending.member.user.name}?`,
                description:
                    'O usuário perde o acesso imediatamente. Os documentos criados por ele permanecem na organização.',
                confirmLabel: 'Remover',
                destructive: true,
            };
        case 'suspend':
            return pending.member.status === 'active'
                ? {
                      title: `Suspender o acesso de ${pending.member.user.name}?`,
                      description:
                          'O usuário não conseguirá entrar até ser reativado. Nada é excluído.',
                      confirmLabel: 'Suspender',
                      destructive: true,
                  }
                : {
                      title: `Reativar o acesso de ${pending.member.user.name}?`,
                      description:
                          'O usuário volta a acessar a organização normalmente.',
                      confirmLabel: 'Reativar',
                      destructive: false,
                  };
        case 'transfer':
            return {
                title: `Transferir a propriedade para ${pending.member.user.name}?`,
                description:
                    'Você deixará de ser o proprietário e passará a Administrador. Esta ação exige confirmação de senha.',
                confirmLabel: 'Transferir propriedade',
                destructive: false,
            };
        case 'demote':
            return {
                title: `Mudar ${pending.member.user.name} para ${labelFor(pending.value)}?`,
                description:
                    'Administradores que mudam de função perdem o que a nova função não concede — por exemplo, acesso a usuários, configurações e cobrança.',
                confirmLabel: 'Confirmar',
                destructive: false,
            };
        case 'revoke':
            return {
                title: `Revogar o convite de ${pending.invitation.email}?`,
                description:
                    'O link do convite deixa de funcionar. Você pode convidar novamente depois.',
                confirmLabel: 'Revogar convite',
                destructive: true,
            };
        case 'delete-role':
            return {
                title: `Excluir a função "${pending.role.name}"?`,
                description:
                    pending.role.members_count > 0
                        ? `${plural(pending.role.members_count, 'usuário ainda tem', 'usuários ainda têm')} esta função. Mude a função dessas pessoas antes de excluir: ninguém passa para Operador automaticamente, para não receber permissões que esta função não dava.`
                        : 'As pastas liberadas por esta função deixam de valer. Convites pendentes com esta função precisam ser revogados antes.',
                confirmLabel: 'Excluir função',
                destructive: true,
            };
        case 'delete-team':
            return {
                title: `Excluir o time "${pending.team.name}"?`,
                description:
                    'Os participantes continuam na conta, mas deixam de ver os documentos das pastas liberadas pelo time.',
                confirmLabel: 'Excluir time',
                destructive: true,
            };
        default:
            return {
                title: '',
                description: '',
                confirmLabel: 'Confirmar',
                destructive: false,
            };
    }
}

/** Matriz somente leitura dos papéis de sistema (flag `custom_roles` desligada). */
function SystemRolesMatrix({
    roles,
    matrix,
}: {
    roles: RoleDefinition[];
    matrix: PermissionMatrixRow[];
}) {
    const columns = `minmax(0,2.4fr) repeat(${ROLE_ORDER.length}, minmax(0,1fr))`;

    return (
        <div className="border-border bg-card shadow-card rounded-xl border">
            <div className="flex flex-wrap items-start justify-between gap-3 px-5 pt-[18px] pb-3">
                <div>
                    <div className="text-[15px] font-semibold">
                        Funções e permissões
                    </div>
                    <div className="text-muted-foreground mt-1 text-[13px]">
                        Funções padrão do sistema. Funções personalizadas, times
                        e acesso por pasta não estão disponíveis no plano desta
                        conta.
                    </div>
                </div>
                <Badge variant="phase">
                    Funções personalizadas · outro plano
                </Badge>
            </div>
            <div className="overflow-x-auto">
                <div style={{ minWidth: 640 }}>
                    <div
                        className="border-muted bg-background text-muted-foreground grid h-10 items-center border-y px-5 text-[12px] font-semibold"
                        style={{ gridTemplateColumns: columns }}
                    >
                        <span>Permissão</span>
                        {ROLE_ORDER.map((role) => (
                            <span key={role} className="text-center">
                                {membershipRoleLabels[role]}
                            </span>
                        ))}
                    </div>
                    {matrix.map((row) => (
                        <div
                            key={row.key}
                            className="border-muted hover:bg-row-hover grid items-center border-b px-5 py-[11px] last:border-b-0"
                            style={{ gridTemplateColumns: columns }}
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
    );
}

function InviteDialog({
    open,
    onOpenChange,
    seats,
    options,
    roleCatalog,
    folders,
    showFolders,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    seats: MembersIndexProps['seats'];
    options: { value: string; label: string; description: string }[];
    roleCatalog: RoleEntry[];
    folders: FolderOption[];
    showFolders: boolean;
}) {
    const form = useForm<{
        emails: string;
        role: string;
        folders: FolderGrant[];
    }>({
        emails: '',
        role: 'member',
        folders: [],
    });

    const selectedRole = roleCatalog.find(
        (r) => roleValue(r) === form.data.role,
    );
    const seesEverything =
        selectedRole?.permissions.includes('view_all_envelopes') ??
        form.data.role === 'admin';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const emails = form.data.emails
            .split(/[,\s;]+/)
            .map((e) => e.trim())
            .filter(Boolean);

        form.transform((data) => ({
            emails,
            ...rolePayload(data.role),
            ...(showFolders && !seesEverything && data.folders.length > 0
                ? {
                      folders: data.folders.map(({ folder, level }) => ({
                          folder,
                          level,
                      })),
                  }
                : {}),
        }));
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
    const folderErrors = Object.entries(errors)
        .filter(([key]) => key.startsWith('folders'))
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
                        <div className="grid max-h-64 gap-2 overflow-y-auto sm:grid-cols-2">
                            {options.map((option) => {
                                const selected =
                                    form.data.role === option.value;

                                return (
                                    <button
                                        key={option.value}
                                        type="button"
                                        onClick={() =>
                                            form.setData('role', option.value)
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
                                            {option.label}
                                        </span>
                                        <span className="text-muted-foreground block text-[12px] leading-[1.45]">
                                            {option.description}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                        <InputError message={errors.role || errors.role_id} />
                    </div>
                    {showFolders && (
                        <div className="grid gap-1.5">
                            <span className="text-[13px] font-semibold">
                                Pastas com acesso
                            </span>
                            <FolderAccessPicker
                                variant="chips"
                                folders={folders}
                                value={form.data.folders}
                                onChange={(next) =>
                                    form.setData('folders', next)
                                }
                                allFolders={seesEverything}
                            />
                            <InputError message={folderErrors || undefined} />
                        </div>
                    )}
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
