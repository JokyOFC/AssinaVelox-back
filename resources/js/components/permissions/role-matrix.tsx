import { router } from '@inertiajs/react';
import {
    Check,
    FolderLock,
    Lock,
    MoreHorizontal,
    Pencil,
    Plus,
    Trash2,
} from 'lucide-react';
import { Fragment } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { plural } from '@/lib/format';
import { cn } from '@/lib/utils';
import { update as updateRole } from '@/routes/roles';
import type { PermissionCatalogGroup, RoleEntry } from './types';

/**
 * Matriz "Funções e permissões" (mock App - Usuarios, aba de funções):
 * colunas = funções; papéis de sistema somente leitura (cadeado), funções
 * personalizadas editáveis clicando na célula.
 */
export function RoleMatrix({
    catalog,
    roles,
    held,
    canCreate,
    onCreate,
    onEdit,
    onDelete,
    onFolders,
}: {
    catalog: PermissionCatalogGroup[];
    roles: RoleEntry[];
    held: Record<string, boolean>;
    canCreate: boolean;
    onCreate: () => void;
    onEdit: (role: RoleEntry) => void;
    onDelete: (role: RoleEntry) => void;
    onFolders: (role: RoleEntry) => void;
}) {
    const columns = `minmax(220px,2.4fr) repeat(${roles.length}, minmax(112px,1fr))`;

    const toggle = (role: RoleEntry, key: string) => {
        const next = role.permissions.includes(key)
            ? role.permissions.filter((p) => p !== key)
            : [...role.permissions, key];

        router.patch(
            updateRole(role.id).url,
            { permissions: next },
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <div className="border-border bg-card shadow-card rounded-xl border">
            <div className="flex flex-wrap items-start justify-between gap-3 px-5 pt-[18px] pb-3">
                <div>
                    <div className="text-[15px] font-semibold">
                        Funções e permissões
                    </div>
                    <div className="text-muted-foreground mt-1 text-[13px]">
                        Funções do sistema ficam fixas. Clique numa célula de
                        uma função personalizada para conceder ou retirar a
                        permissão — vale na hora.
                    </div>
                </div>
                {canCreate && (
                    <Button variant="outline" size="sm" onClick={onCreate}>
                        <Plus className="size-4" />
                        Nova função personalizada
                    </Button>
                )}
            </div>
            <div className="overflow-x-auto">
                <div style={{ minWidth: 220 + roles.length * 112 }}>
                    <div
                        className="border-muted bg-background text-muted-foreground grid min-h-10 items-center border-y px-5 py-1.5 text-[12px] font-semibold"
                        style={{ gridTemplateColumns: columns }}
                    >
                        <span>Permissão</span>
                        {roles.map((role) => (
                            <div
                                key={role.id}
                                className="flex items-center justify-center gap-1 text-center"
                            >
                                <span className="min-w-0">
                                    <span className="text-foreground flex items-center justify-center gap-1 truncate">
                                        {role.is_system && (
                                            <Lock
                                                className="text-muted-foreground size-3 shrink-0"
                                                aria-label="Função do sistema"
                                            />
                                        )}
                                        <span className="truncate">
                                            {role.name}
                                        </span>
                                    </span>
                                    <span className="block text-[11px] font-medium">
                                        {plural(role.members_count, 'usuário')}
                                    </span>
                                </span>
                                {(role.can.update ||
                                    role.can.delete ||
                                    role.can.manage_folders) && (
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button
                                                variant="ghost"
                                                size="icon-xs"
                                                aria-label={`Ações da função ${role.name}`}
                                            >
                                                <MoreHorizontal className="size-3.5" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            {role.can.update && (
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        onEdit(role)
                                                    }
                                                >
                                                    <Pencil className="size-3.5" />
                                                    Editar função
                                                </DropdownMenuItem>
                                            )}
                                            {role.can.manage_folders && (
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        onFolders(role)
                                                    }
                                                >
                                                    <FolderLock className="size-3.5" />
                                                    Pastas com acesso
                                                    {role.folders.length > 0 &&
                                                        ` (${role.folders.length})`}
                                                </DropdownMenuItem>
                                            )}
                                            {role.can.delete && (
                                                <>
                                                    <DropdownMenuSeparator />
                                                    <DropdownMenuItem
                                                        variant="destructive"
                                                        onSelect={() =>
                                                            onDelete(role)
                                                        }
                                                    >
                                                        <Trash2 className="size-3.5" />
                                                        Excluir função
                                                    </DropdownMenuItem>
                                                </>
                                            )}
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                )}
                            </div>
                        ))}
                    </div>
                    {catalog.map((group) => (
                        <Fragment key={group.key}>
                            <div className="bg-background text-muted-foreground border-muted border-b px-5 py-1.5 text-[11.5px] font-bold tracking-wide uppercase">
                                {group.label}
                            </div>
                            {group.permissions.map((permission) => (
                                <div
                                    key={permission.key}
                                    className="border-muted hover:bg-row-hover grid items-center border-b px-5 py-[11px]"
                                    style={{ gridTemplateColumns: columns }}
                                >
                                    <span className="min-w-0 pr-3">
                                        <span className="block text-[13.5px] font-medium">
                                            {permission.label}
                                        </span>
                                        <span className="text-muted-foreground block text-[12px]">
                                            {permission.description}
                                        </span>
                                    </span>
                                    {roles.map((role) => {
                                        const granted =
                                            role.permissions.includes(
                                                permission.key,
                                            );
                                        const editable =
                                            !role.is_system &&
                                            role.can.update &&
                                            permission.grantable &&
                                            held[permission.key] === true;
                                        const mark = (
                                            <span
                                                className={cn(
                                                    'flex size-[22px] items-center justify-center rounded-md text-[12px] font-bold',
                                                    granted
                                                        ? 'bg-success-bg text-success'
                                                        : 'bg-muted text-muted-foreground',
                                                )}
                                            >
                                                {granted ? (
                                                    <Check className="size-3.5 stroke-[2.5]" />
                                                ) : (
                                                    '–'
                                                )}
                                            </span>
                                        );

                                        return (
                                            <span
                                                key={role.id}
                                                className="flex justify-center"
                                            >
                                                {editable ? (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            toggle(
                                                                role,
                                                                permission.key,
                                                            )
                                                        }
                                                        aria-pressed={granted}
                                                        aria-label={`${granted ? 'Retirar' : 'Conceder'} "${permission.label}" para ${role.name}`}
                                                        className="hover:ring-primary/40 rounded-md transition-shadow hover:ring-2"
                                                    >
                                                        {mark}
                                                    </button>
                                                ) : (
                                                    mark
                                                )}
                                            </span>
                                        );
                                    })}
                                </div>
                            ))}
                        </Fragment>
                    ))}
                </div>
            </div>
        </div>
    );
}
