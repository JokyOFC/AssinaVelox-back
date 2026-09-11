import {
    Folder,
    MoreHorizontal,
    Pencil,
    Plus,
    Trash2,
    Users,
} from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { plural } from '@/lib/format';
import { accessLevelLabels, type MemberRow, type TeamEntry } from './types';

/** Aba "Times": lista, participantes e pastas liberadas. */
export function TeamsPanel({
    teams,
    members,
    canCreate,
    onCreate,
    onEdit,
    onDelete,
}: {
    teams: TeamEntry[];
    members: MemberRow[];
    canCreate: boolean;
    onCreate: () => void;
    onEdit: (team: TeamEntry) => void;
    onDelete: (team: TeamEntry) => void;
}) {
    const names = new Map(members.map((m) => [m.id, m.user.name]));

    return (
        <div className="border-border bg-card shadow-card rounded-xl border">
            <div className="flex flex-wrap items-start justify-between gap-3 px-5 pt-[18px] pb-3">
                <div>
                    <div className="text-[15px] font-semibold">Times</div>
                    <div className="text-muted-foreground mt-1 text-[13px]">
                        Agrupe pessoas para liberar pastas de uma vez. Times não
                        mudam a função de ninguém.
                    </div>
                </div>
                {canCreate && (
                    <Button variant="outline" size="sm" onClick={onCreate}>
                        <Plus className="size-4" />
                        Novo time
                    </Button>
                )}
            </div>

            {teams.length === 0 ? (
                <EmptyState
                    variant="inline"
                    title="Nenhum time criado"
                    description="Crie um time para liberar pastas para várias pessoas de uma vez."
                />
            ) : (
                <div className="border-muted divide-muted divide-y border-t">
                    {teams.map((team) => {
                        const memberNames = team.member_ids
                            .map((id) => names.get(id))
                            .filter(Boolean) as string[];

                        return (
                            <div
                                key={team.id}
                                className="hover:bg-row-hover flex flex-wrap items-start gap-3 px-5 py-3"
                            >
                                <span className="bg-primary-soft text-primary flex size-9 shrink-0 items-center justify-center rounded-lg">
                                    <Users className="size-4" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="text-[13.5px] font-semibold">
                                        {team.name}
                                    </div>
                                    {team.description && (
                                        <div className="text-muted-foreground text-[12.5px]">
                                            {team.description}
                                        </div>
                                    )}
                                    <div className="text-text-secondary mt-1 text-[12.5px]">
                                        {team.member_ids.length === 0
                                            ? 'Sem participantes'
                                            : `${plural(team.member_ids.length, 'participante')}: ${memberNames.slice(0, 4).join(', ')}${memberNames.length > 4 ? '…' : ''}`}
                                    </div>
                                    <div className="mt-1.5 flex flex-wrap gap-1.5">
                                        {team.folders.length === 0 ? (
                                            <span className="text-muted-foreground text-[12px]">
                                                Nenhuma pasta liberada
                                            </span>
                                        ) : (
                                            team.folders.map((grant) => (
                                                <span
                                                    key={grant.folder}
                                                    className="border-border text-text-secondary inline-flex h-6 items-center gap-1 rounded-full border bg-white px-2 text-[12px] font-medium"
                                                >
                                                    <Folder className="size-3" />
                                                    {grant.name}
                                                    <span className="text-muted-foreground">
                                                        ·{' '}
                                                        {
                                                            accessLevelLabels[
                                                                grant.level
                                                            ]
                                                        }
                                                    </span>
                                                </span>
                                            ))
                                        )}
                                    </div>
                                </div>
                                {(team.can.update || team.can.delete) && (
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button
                                                variant="ghost"
                                                size="icon-xs"
                                                aria-label={`Ações do time ${team.name}`}
                                            >
                                                <MoreHorizontal className="text-muted-foreground size-4" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            {team.can.update && (
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        onEdit(team)
                                                    }
                                                >
                                                    <Pencil className="size-3.5" />
                                                    Editar time
                                                </DropdownMenuItem>
                                            )}
                                            {team.can.delete && (
                                                <>
                                                    <DropdownMenuSeparator />
                                                    <DropdownMenuItem
                                                        variant="destructive"
                                                        onSelect={() =>
                                                            onDelete(team)
                                                        }
                                                    >
                                                        <Trash2 className="size-3.5" />
                                                        Excluir time
                                                    </DropdownMenuItem>
                                                </>
                                            )}
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                )}
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
