import { useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { toast } from 'sonner';
import { AvatarInitials } from '@/components/avatar-initials';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store as storeTeam, update as updateTeam } from '@/routes/teams';
import { FolderAccessPicker } from './folder-access-picker';
import type { FolderGrant, FolderOption, MemberRow, TeamEntry } from './types';

/**
 * Criar/editar time: nome, participantes e (com "Gerenciar pastas") as pastas
 * que o time libera. Times não dão permissões de conta — só acesso a pastas.
 */
export function TeamDialog({
    open,
    onOpenChange,
    team,
    members,
    folders,
    canManageFolders,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    team: TeamEntry | null;
    members: MemberRow[];
    folders: FolderOption[];
    canManageFolders: boolean;
}) {
    const [filter, setFilter] = useState('');
    const form = useForm<{
        name: string;
        description: string;
        members: number[];
        folders: FolderGrant[];
    }>({ name: '', description: '', members: [], folders: [] });

    useEffect(() => {
        if (open) {
            form.setData({
                name: team?.name ?? '',
                description: team?.description ?? '',
                members: (team?.member_ids ?? []).map(Number),
                folders: team?.folders ?? [],
            });
            form.clearErrors();
            setFilter('');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, team]);

    const visible = useMemo(() => {
        const q = filter.trim().toLowerCase();

        return members.filter(
            (m) =>
                m.status === 'active' &&
                (!q ||
                    m.user.name.toLowerCase().includes(q) ||
                    m.user.email.toLowerCase().includes(q)),
        );
    }, [members, filter]);

    const toggleMember = (id: number, on: boolean) => {
        form.setData(
            'members',
            on
                ? [...form.data.members.filter((m) => m !== id), id]
                : form.data.members.filter((m) => m !== id),
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            name: data.name,
            description: data.description || null,
            members: data.members,
            ...(canManageFolders
                ? {
                      folders: data.folders.map(({ folder, level }) => ({
                          folder,
                          level,
                      })),
                  }
                : {}),
        }));

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(team ? 'Time atualizado' : 'Time criado');
                onOpenChange(false);
            },
        };

        if (team) {
            form.patch(updateTeam(team.id).url, options);
        } else {
            form.post(storeTeam.url(), options);
        }
    };

    const errors = form.errors as Record<string, string | undefined>;
    const folderErrors = Object.entries(errors)
        .filter(([key]) => key.startsWith('folders'))
        .map(([, v]) => v)
        .filter(Boolean)
        .join(' ');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[560px]">
                <DialogHeader>
                    <DialogTitle>
                        {team ? `Editar time "${team.name}"` : 'Novo time'}
                    </DialogTitle>
                    <DialogDescription>
                        Quem participa do time vê os documentos das pastas
                        liberadas para ele.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="team-name">Nome</Label>
                            <Input
                                id="team-name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder="Ex.: Comercial"
                                maxLength={80}
                                required
                                autoFocus
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="team-description">
                                Descrição (opcional)
                            </Label>
                            <Input
                                id="team-description"
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                                maxLength={255}
                            />
                        </div>
                    </div>

                    <div className="grid gap-1.5">
                        <span className="text-[13px] font-semibold">
                            Participantes ({form.data.members.length})
                        </span>
                        <Input
                            value={filter}
                            onChange={(e) => setFilter(e.target.value)}
                            placeholder="Buscar por nome ou e-mail"
                            className="h-8"
                        />
                        <div className="border-border divide-muted max-h-48 divide-y overflow-y-auto rounded-lg border">
                            {visible.length === 0 ? (
                                <p className="text-muted-foreground px-3 py-3 text-[12.5px]">
                                    Nenhum usuário encontrado.
                                </p>
                            ) : (
                                visible.map((member, index) => {
                                    const id = Number(member.id);
                                    const htmlId = `team-member-${member.id}`;

                                    return (
                                        <div
                                            key={member.id}
                                            className="flex items-center gap-3 px-3 py-2"
                                        >
                                            <Checkbox
                                                id={htmlId}
                                                checked={form.data.members.includes(
                                                    id,
                                                )}
                                                onCheckedChange={(v) =>
                                                    toggleMember(id, v === true)
                                                }
                                            />
                                            <label
                                                htmlFor={htmlId}
                                                className="flex min-w-0 flex-1 cursor-pointer items-center gap-2"
                                            >
                                                <AvatarInitials
                                                    initials={
                                                        member.user.initials
                                                    }
                                                    index={index}
                                                />
                                                <span className="min-w-0">
                                                    <span className="block truncate text-[13px] font-medium">
                                                        {member.user.name}
                                                    </span>
                                                    <span className="text-muted-foreground block truncate text-[12px]">
                                                        {member.role_label}
                                                    </span>
                                                </span>
                                            </label>
                                        </div>
                                    );
                                })
                            )}
                        </div>
                        <InputError message={errors.members} />
                    </div>

                    {canManageFolders && (
                        <div className="grid gap-1.5">
                            <span className="text-[13px] font-semibold">
                                Pastas com acesso
                            </span>
                            <FolderAccessPicker
                                folders={folders}
                                value={form.data.folders}
                                onChange={(next) =>
                                    form.setData('folders', next)
                                }
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
                            disabled={form.processing || !form.data.name.trim()}
                        >
                            {form.processing && <Spinner />}
                            {team ? 'Salvar time' : 'Criar time'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
