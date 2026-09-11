import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';

type HoldForm = {
    reason: string;
    ends_at: string;
    scope: 'organization' | 'folder';
    folder_id: string;
};

/**
 * Criar uma preservação (bloqueio de exclusão). No detalhe do documento
 * (`folders` ausente) o alvo é o próprio documento; em Configurações ›
 * Retenção a pessoa escolhe entre a organização inteira e uma pasta.
 */
export function PlaceHoldDialog({
    open,
    onOpenChange,
    action,
    title = 'Preservar documento',
    description = 'Enquanto a preservação estiver ativa, ninguém exclui este documento: nem a política de retenção, nem a exclusão manual, nem a exclusão da conta. A criação fica registrada com o seu nome.',
    folders,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    action: string;
    title?: string;
    description?: string;
    /** Presente só em Configurações: habilita a escolha da abrangência. */
    folders?: { id: string; name: string }[];
}) {
    const form = useForm<HoldForm>({
        reason: '',
        ends_at: '',
        scope: 'organization',
        folder_id: '',
    });

    const withScope = folders !== undefined;

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.transform((data) => {
            const payload: Record<string, string> = { reason: data.reason };

            if (data.ends_at) {
                payload.ends_at = data.ends_at;
            }

            if (withScope) {
                payload.scope = data.scope;

                if (data.scope === 'folder') {
                    payload.folder_id = data.folder_id;
                }
            }

            return payload;
        });

        form.post(action, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription>{description}</DialogDescription>
                    </DialogHeader>

                    {withScope && (
                        <div className="grid gap-1.5">
                            <Label htmlFor="hold-scope">Abrangência</Label>
                            <Select
                                value={form.data.scope}
                                onValueChange={(value) =>
                                    form.setData(
                                        'scope',
                                        value as HoldForm['scope'],
                                    )
                                }
                            >
                                <SelectTrigger id="hold-scope">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="organization">
                                        Todos os documentos da organização
                                    </SelectItem>
                                    <SelectItem
                                        value="folder"
                                        disabled={folders.length === 0}
                                    >
                                        Uma pasta (e as subpastas)
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.scope} />
                        </div>
                    )}

                    {withScope && form.data.scope === 'folder' && (
                        <div className="grid gap-1.5">
                            <Label htmlFor="hold-folder">Pasta</Label>
                            <Select
                                value={form.data.folder_id}
                                onValueChange={(value) =>
                                    form.setData('folder_id', value)
                                }
                            >
                                <SelectTrigger id="hold-folder">
                                    <SelectValue placeholder="Escolha a pasta" />
                                </SelectTrigger>
                                <SelectContent>
                                    {folders.map((folder) => (
                                        <SelectItem
                                            key={folder.id}
                                            value={folder.id}
                                        >
                                            {folder.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="text-muted-foreground text-[12px]">
                                Vale para os documentos que estiverem na pasta
                                ou numa subpasta no momento de cada exclusão.
                            </p>
                            <InputError message={form.errors.folder_id} />
                        </div>
                    )}

                    <div className="grid gap-1.5">
                        <Label htmlFor="hold-reason">Motivo</Label>
                        <Textarea
                            id="hold-reason"
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                            maxLength={1000}
                            rows={3}
                            placeholder="Ex.: processo 0001234-56.2026.8.26.0100; pedido do jurídico"
                            aria-invalid={!!form.errors.reason}
                        />
                        <p className="text-muted-foreground text-[12px]">
                            Evite dados pessoais desnecessários: o motivo fica
                            na trilha da conta.
                        </p>
                        <InputError message={form.errors.reason} />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="hold-ends-at">
                            Preservar até (opcional)
                        </Label>
                        <Input
                            id="hold-ends-at"
                            type="date"
                            value={form.data.ends_at}
                            onChange={(event) =>
                                form.setData('ends_at', event.target.value)
                            }
                            aria-invalid={!!form.errors.ends_at}
                        />
                        <p className="text-muted-foreground text-[12px]">
                            Sem data, a preservação vale até alguém com
                            permissão liberá-la.
                        </p>
                        <InputError message={form.errors.ends_at} />
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
                                form.processing ||
                                form.data.reason.trim().length < 5 ||
                                (withScope &&
                                    form.data.scope === 'folder' &&
                                    !form.data.folder_id)
                            }
                        >
                            {form.processing && <Spinner />}
                            Preservar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
