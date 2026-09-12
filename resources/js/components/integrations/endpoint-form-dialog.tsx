import { useForm } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import type { FormEvent } from 'react';
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
import { store, update } from '@/routes/integrations/webhooks';
import type { EventOption, WebhookEndpointRow } from './types';

type EndpointForm = {
    url: string;
    events: string[];
    description: string;
};

/**
 * Cadastrar ou editar um endpoint de webhook. A URL passa pela proteção de
 * rede no servidor (só HTTPS público, sem IP literal, sem redirecionamento);
 * a recusa volta como erro do campo, com a mesma mensagem para "não resolve"
 * e "resolve para endereço interno".
 */
export function EndpointFormDialog({
    open,
    onOpenChange,
    catalog,
    endpoint,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    catalog: EventOption[];
    endpoint?: WebhookEndpointRow;
}) {
    const editing = endpoint !== undefined;
    const form = useForm<EndpointForm>({
        url: endpoint?.url ?? '',
        events: endpoint?.events ?? ['*'],
        description: endpoint?.description ?? '',
    });

    const all = form.data.events.includes('*');

    const toggle = (value: string, checked: boolean) => {
        const current = form.data.events.filter((item) => item !== '*');

        form.setData(
            'events',
            checked
                ? [...current, value]
                : current.filter((item) => item !== value),
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (editing && endpoint) {
            form.patch(update.url({ webhookEndpoint: endpoint.id }), options);
        } else {
            form.post(store.url(), options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[580px]">
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>
                            {editing ? 'Editar endpoint' : 'Adicionar endpoint'}
                        </DialogTitle>
                        <DialogDescription>
                            Enviamos um POST assinado (HMAC SHA-256) para esta
                            URL a cada evento escolhido.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="webhook-url">URL de destino</Label>
                        <Input
                            id="webhook-url"
                            type="url"
                            inputMode="url"
                            value={form.data.url}
                            maxLength={2048}
                            autoComplete="off"
                            placeholder="https://seu-sistema.com.br/webhooks/assinavelox"
                            className="font-mono text-[13px]"
                            onChange={(event) =>
                                form.setData('url', event.target.value)
                            }
                            aria-invalid={form.errors.url ? true : undefined}
                        />
                        {form.errors.url ? (
                            <p
                                role="alert"
                                className="text-danger flex items-start gap-1.5 text-[12px] font-medium"
                            >
                                <ShieldAlert className="mt-px size-3.5 shrink-0" />
                                {form.errors.url}
                            </p>
                        ) : (
                            <p className="text-muted-foreground text-[12px]">
                                Só HTTPS, com domínio público. Endereços IP,
                                redes internas e redirecionamentos são recusados
                                por segurança.
                            </p>
                        )}
                    </div>

                    <fieldset className="grid gap-2">
                        <legend className="mb-1 text-[13.5px] font-semibold">
                            Eventos
                        </legend>
                        <label className="border-border hover:bg-row-hover flex cursor-pointer items-start gap-3 rounded-lg border px-3 py-2.5">
                            <Checkbox
                                className="mt-0.5"
                                checked={all}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'events',
                                        checked === true ? ['*'] : [],
                                    )
                                }
                            />
                            <span>
                                <span className="block text-[13.5px] font-medium">
                                    Todos os eventos
                                </span>
                                <span className="text-text-secondary block text-[12.5px]">
                                    Inclui os que forem criados no futuro.
                                </span>
                            </span>
                        </label>
                        {!all && (
                            <div className="border-border divide-border max-h-[260px] divide-y overflow-y-auto rounded-lg border">
                                {catalog.map((option) => (
                                    <label
                                        key={option.value}
                                        className="hover:bg-row-hover flex cursor-pointer items-start gap-3 px-3 py-2.5"
                                    >
                                        <Checkbox
                                            className="mt-0.5"
                                            checked={form.data.events.includes(
                                                option.value,
                                            )}
                                            onCheckedChange={(checked) =>
                                                toggle(
                                                    option.value,
                                                    checked === true,
                                                )
                                            }
                                        />
                                        <span className="min-w-0">
                                            <span className="flex flex-wrap items-center gap-2 text-[13.5px] font-medium">
                                                {option.label}
                                                <code className="text-primary font-mono text-[11.5px]">
                                                    {option.value}
                                                </code>
                                            </span>
                                            <span className="text-text-secondary block text-[12.5px] leading-[1.5]">
                                                {option.description}
                                            </span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                        )}
                        <InputError message={form.errors.events} />
                    </fieldset>

                    <div className="grid gap-2">
                        <Label htmlFor="webhook-description">
                            Descrição (opcional)
                        </Label>
                        <Input
                            id="webhook-description"
                            value={form.data.description}
                            maxLength={160}
                            placeholder="Ex.: ERP financeiro"
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                        />
                        <InputError message={form.errors.description} />
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
                            disabled={
                                form.processing ||
                                form.data.url.trim() === '' ||
                                form.data.events.length === 0
                            }
                        >
                            {form.processing && <Spinner />}
                            {editing ? 'Salvar' : 'Adicionar endpoint'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
