import { useForm } from '@inertiajs/react';
import { useEffect, type FormEvent } from 'react';
import { toast } from 'sonner';
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
import { Textarea } from '@/components/ui/textarea';
import { store as storeRole, update as updateRole } from '@/routes/roles';
import type { PermissionCatalogGroup, RoleEntry } from './types';

/**
 * Criar/editar função personalizada. Só aparecem habilitadas as permissões
 * que quem edita já tem (o servidor confere de novo — regra anti-escalada).
 */
export function RoleDialog({
    open,
    onOpenChange,
    role,
    catalog,
    held,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    role: RoleEntry | null;
    catalog: PermissionCatalogGroup[];
    held: Record<string, boolean>;
}) {
    const form = useForm<{
        name: string;
        description: string;
        permissions: string[];
    }>({
        name: '',
        description: '',
        permissions: [],
    });

    useEffect(() => {
        if (open) {
            form.setData({
                name: role?.name ?? '',
                description: role?.description ?? '',
                permissions: role?.permissions ?? [],
            });
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, role]);

    const toggle = (key: string, on: boolean) => {
        form.setData(
            'permissions',
            on
                ? [...form.data.permissions.filter((p) => p !== key), key]
                : form.data.permissions.filter((p) => p !== key),
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(role ? 'Função atualizada' : 'Função criada');
                onOpenChange(false);
            },
        };

        if (role) {
            form.patch(updateRole(role.id).url, options);
        } else {
            form.post(storeRole.url(), options);
        }
    };

    const errors = form.errors as Record<string, string | undefined>;
    const permissionErrors = Object.entries(errors)
        .filter(([key]) => key.startsWith('permissions'))
        .map(([, message]) => message)
        .filter(Boolean)
        .join(' ');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[620px]">
                <DialogHeader>
                    <DialogTitle>
                        {role
                            ? `Editar função "${role.name}"`
                            : 'Nova função personalizada'}
                    </DialogTitle>
                    <DialogDescription>
                        Escolha o que quem tiver esta função pode fazer. Você só
                        pode conceder permissões que você mesmo tem.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="role-name">Nome</Label>
                            <Input
                                id="role-name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder="Ex.: Gerente, Somente leitura"
                                maxLength={80}
                                required
                                autoFocus
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="role-description">
                                Descrição (opcional)
                            </Label>
                            <Textarea
                                id="role-description"
                                rows={1}
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                                maxLength={255}
                                placeholder="Para que serve esta função"
                            />
                            <InputError message={errors.description} />
                        </div>
                    </div>

                    <div className="border-border max-h-[46vh] overflow-y-auto rounded-lg border">
                        {catalog.map((group) => (
                            <div key={group.key}>
                                <div className="bg-background text-muted-foreground border-muted sticky top-0 z-[1] border-b px-3 py-1.5 text-[11.5px] font-bold tracking-wide uppercase">
                                    {group.label}
                                </div>
                                {group.permissions.map((permission) => {
                                    const id = `perm-${permission.key}`;
                                    const allowed =
                                        permission.grantable &&
                                        held[permission.key] === true;

                                    return (
                                        <div
                                            key={permission.key}
                                            className="border-muted flex items-start gap-3 border-b px-3 py-2 last:border-b-0"
                                            title={
                                                !permission.grantable
                                                    ? 'Exclusiva do proprietário'
                                                    : !allowed
                                                      ? 'Você não tem esta permissão para conceder'
                                                      : undefined
                                            }
                                        >
                                            <Checkbox
                                                id={id}
                                                className="mt-0.5"
                                                checked={form.data.permissions.includes(
                                                    permission.key,
                                                )}
                                                disabled={
                                                    !allowed || form.processing
                                                }
                                                onCheckedChange={(v) =>
                                                    toggle(
                                                        permission.key,
                                                        v === true,
                                                    )
                                                }
                                            />
                                            <label
                                                htmlFor={id}
                                                className="min-w-0 flex-1 cursor-pointer"
                                            >
                                                <span className="block text-[13.5px] font-medium">
                                                    {permission.label}
                                                    {!permission.grantable && (
                                                        <span className="text-muted-foreground ml-1.5 text-[11.5px] font-semibold">
                                                            · só proprietário
                                                        </span>
                                                    )}
                                                </span>
                                                <span className="text-muted-foreground block text-[12px]">
                                                    {permission.description}
                                                </span>
                                            </label>
                                        </div>
                                    );
                                })}
                            </div>
                        ))}
                    </div>
                    <InputError message={permissionErrors || undefined} />

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
                            {role ? 'Salvar função' : 'Criar função'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
