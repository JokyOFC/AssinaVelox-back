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
import { store } from '@/routes/public_forms';
import type { TemplateOption } from './types';

/**
 * "Novo formulário": escolhe o modelo e cria um rascunho. A configuração
 * (quem preenche o quê, destino, limites) é feita na tela seguinte.
 */
export function CreateFormDialog({
    open,
    onOpenChange,
    templates,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    templates: TemplateOption[];
}) {
    const form = useForm<{ template: string; title: string }>({
        template: templates[0]?.id ?? '',
        title: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(store.url(), {
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[480px]">
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Novo formulário público</DialogTitle>
                        <DialogDescription>
                            Quem receber o link preenche os dados, confirma o
                            e-mail e o documento é gerado a partir do modelo.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="public-form-template">Modelo</Label>
                        <Select
                            value={form.data.template || undefined}
                            onValueChange={(value) =>
                                form.setData('template', value)
                            }
                        >
                            <SelectTrigger
                                id="public-form-template"
                                className="w-full"
                                aria-invalid={
                                    form.errors.template ? true : undefined
                                }
                            >
                                <SelectValue placeholder="Escolha um modelo" />
                            </SelectTrigger>
                            <SelectContent>
                                {templates.map((template) => (
                                    <SelectItem
                                        key={template.id}
                                        value={template.id}
                                    >
                                        {template.name} ·{' '}
                                        {template.source_label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.template} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="public-form-title">
                            Título do formulário (opcional)
                        </Label>
                        <Input
                            id="public-form-title"
                            value={form.data.title}
                            maxLength={160}
                            placeholder="Usa o nome do modelo se ficar em branco"
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                        />
                        <InputError message={form.errors.title} />
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing || !form.data.template}
                        >
                            {form.processing && <Spinner />}
                            Criar rascunho
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
