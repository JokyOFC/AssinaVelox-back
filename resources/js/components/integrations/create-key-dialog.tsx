import { useForm } from '@inertiajs/react';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/integrations/keys';
import type { AbilityOption } from './types';

const EXPIRATION_OPTIONS = [
    { value: '30', label: '30 dias' },
    { value: '90', label: '90 dias (recomendado)' },
    { value: '180', label: '180 dias' },
    { value: '365', label: '1 ano' },
    { value: 'never', label: 'Sem validade' },
];

type KeyForm = {
    name: string;
    abilities: string[];
    expires_in: string;
};

/**
 * "Nova chave": nome, permissões (abilities) e validade. Só aparecem
 * habilitadas as permissões que a pessoa pode conceder (anti-escalada —
 * conferida de novo no servidor).
 */
export function CreateKeyDialog({
    open,
    onOpenChange,
    abilities,
    maxExpirationDays,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    abilities: AbilityOption[];
    maxExpirationDays: number;
}) {
    const form = useForm<KeyForm>({
        name: '',
        abilities: abilities.some((a) => a.value === 'envelopes:read')
            ? ['envelopes:read']
            : [],
        expires_in: '90',
    });

    const options = EXPIRATION_OPTIONS.filter(
        (option) =>
            option.value === 'never' ||
            Number(option.value) <= maxExpirationDays,
    );

    const toggle = (value: string, checked: boolean) => {
        form.setData(
            'abilities',
            checked
                ? [...form.data.abilities, value]
                : form.data.abilities.filter((item) => item !== value),
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            name: data.name,
            abilities: data.abilities,
            expires_at:
                data.expires_in === 'never'
                    ? null
                    : new Date(
                          Date.now() +
                              Number(data.expires_in) * 86_400_000 -
                              60_000,
                      ).toISOString(),
        }));
        form.post(store.url(), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[560px]">
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Nova chave de API</DialogTitle>
                        <DialogDescription>
                            A chave age em nome de você, nesta organização, e só
                            faz o que você também pode fazer. Ela será exibida
                            uma única vez.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="api-key-name">Nome</Label>
                        <Input
                            id="api-key-name"
                            value={form.data.name}
                            maxLength={120}
                            autoComplete="off"
                            placeholder="Ex.: Integração ERP, Zapier, n8n"
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            aria-invalid={form.errors.name ? true : undefined}
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <fieldset className="grid gap-2">
                        <legend className="mb-1 text-[13.5px] font-semibold">
                            Permissões da chave
                        </legend>
                        <div className="border-border divide-border max-h-[280px] divide-y overflow-y-auto rounded-lg border">
                            {abilities.map((ability) => {
                                const id = `ability-${ability.value.replace(':', '-')}`;
                                const disabled = ability.grantable === false;

                                return (
                                    <label
                                        key={ability.value}
                                        htmlFor={id}
                                        className={
                                            disabled
                                                ? 'flex cursor-not-allowed items-start gap-3 px-3 py-2.5 opacity-60'
                                                : 'hover:bg-row-hover flex cursor-pointer items-start gap-3 px-3 py-2.5'
                                        }
                                    >
                                        <Checkbox
                                            id={id}
                                            className="mt-0.5"
                                            disabled={disabled}
                                            checked={form.data.abilities.includes(
                                                ability.value,
                                            )}
                                            onCheckedChange={(checked) =>
                                                toggle(
                                                    ability.value,
                                                    checked === true,
                                                )
                                            }
                                        />
                                        <span className="min-w-0">
                                            <span className="flex flex-wrap items-center gap-2 text-[13.5px] font-medium">
                                                {ability.label}
                                                <code className="text-muted-foreground font-mono text-[11.5px]">
                                                    {ability.value}
                                                </code>
                                            </span>
                                            <span className="text-text-secondary block text-[12.5px] leading-[1.5]">
                                                {disabled
                                                    ? 'Você não tem a permissão necessária para conceder esta.'
                                                    : ability.description}
                                            </span>
                                        </span>
                                    </label>
                                );
                            })}
                        </div>
                        <InputError
                            message={
                                form.errors.abilities ??
                                (form.errors as Record<string, string>)[
                                    'abilities.0'
                                ]
                            }
                        />
                    </fieldset>

                    <div className="grid gap-2">
                        <Label htmlFor="api-key-expiration">Validade</Label>
                        <Select
                            value={form.data.expires_in}
                            onValueChange={(value) =>
                                form.setData('expires_in', value)
                            }
                        >
                            <SelectTrigger
                                id="api-key-expiration"
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {options.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError
                            message={
                                (form.errors as Record<string, string>)
                                    .expires_at
                            }
                        />
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
                                form.data.name.trim().length < 2 ||
                                form.data.abilities.length === 0
                            }
                        >
                            {form.processing && <Spinner />}
                            Criar chave
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
