import { useForm } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import Heading from '@/components/heading';
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
import { Spinner } from '@/components/ui/spinner';
import { formatCpfCnpj, onlyDigits } from '@/lib/format';
import { update as updateBillingProfile } from '@/routes/billing/profile';
import type { BillingProfile } from '@/types';

/** "01310100" → "01310-100" (o servidor recebe só os 8 dígitos). */
function formatPostalCode(value: string): string {
    const digits = onlyDigits(value).slice(0, 8);

    return digits.length > 5
        ? `${digits.slice(0, 5)}-${digits.slice(5)}`
        : digits;
}

function emptyProfile(): BillingProfile {
    return {
        legal_name: '',
        document_number: '',
        address_line: '',
        city: '',
        state: '',
        postal_code: '',
        email: '',
    };
}

/**
 * Dados de faturamento (ROUTES §2.15): leitura no card, edição em `Dialog` →
 * `PATCH billing.profile.update`. Todos os campos são obrigatórios; CEP tem 8
 * dígitos e UF 2 letras, como o servidor valida.
 */
export function BillingProfileCard({
    profile,
    canManage,
}: {
    profile: BillingProfile | null;
    canManage: boolean;
}) {
    const [open, setOpen] = useState(false);
    // Remonta o formulário a cada abertura (dados vigentes, sem erros antigos).
    const [instance, setInstance] = useState(0);

    const openDialog = () => {
        setInstance((value) => value + 1);
        setOpen(true);
    };

    return (
        <div className="border-border bg-card shadow-card flex flex-col gap-3 rounded-xl border p-5">
            <Heading
                variant="small"
                title="Dados de faturamento"
                description="Aparecem no recibo de cada pagamento."
                action={
                    canManage && (
                        <Button
                            variant="outline-sm"
                            size="xxs"
                            onClick={openDialog}
                        >
                            <Pencil aria-hidden className="size-3" />
                            {profile ? 'Editar' : 'Preencher'}
                        </Button>
                    )
                }
            />

            {profile ? (
                <p className="text-text-secondary text-[13px] leading-[1.7]">
                    <b className="text-foreground">{profile.legal_name}</b>
                    <br />
                    {formatCpfCnpj(profile.document_number)}
                    <br />
                    {profile.address_line} · {profile.city}/{profile.state} ·{' '}
                    {formatPostalCode(profile.postal_code)}
                    <br />
                    {profile.email}
                </p>
            ) : (
                <p className="text-muted-foreground text-[13px] leading-[1.6]">
                    Nenhum dado de faturamento cadastrado. Preencha para que
                    razão social, documento e endereço apareçam nos recibos.
                </p>
            )}

            <BillingProfileDialog
                key={instance}
                open={open}
                onOpenChange={setOpen}
                profile={profile}
            />
        </div>
    );
}

function BillingProfileDialog({
    open,
    onOpenChange,
    profile,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    profile: BillingProfile | null;
}) {
    const form = useForm<BillingProfile>(profile ?? emptyProfile());
    const { data, setData, errors, processing } = form;

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.transform((values) => ({
            ...values,
            document_number: onlyDigits(values.document_number),
            postal_code: onlyDigits(values.postal_code),
            state: values.state.toUpperCase(),
        }));

        form.patch(updateBillingProfile.url(), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[520px]">
                <DialogHeader>
                    <DialogTitle>Dados de faturamento</DialogTitle>
                    <DialogDescription>
                        Usados no recibo de pagamento. Não são enviados ao
                        Mercado Pago nem substituem nota fiscal.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="grid gap-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="bp-legal-name">
                            Razão social ou nome completo
                        </Label>
                        <Input
                            id="bp-legal-name"
                            value={data.legal_name}
                            maxLength={160}
                            aria-invalid={Boolean(errors.legal_name)}
                            onChange={(event) =>
                                setData('legal_name', event.target.value)
                            }
                        />
                        <InputError message={errors.legal_name} />
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="bp-document">CNPJ ou CPF</Label>
                            <Input
                                id="bp-document"
                                value={formatCpfCnpj(data.document_number)}
                                inputMode="numeric"
                                aria-invalid={Boolean(errors.document_number)}
                                onChange={(event) =>
                                    setData(
                                        'document_number',
                                        onlyDigits(event.target.value).slice(
                                            0,
                                            14,
                                        ),
                                    )
                                }
                            />
                            <InputError message={errors.document_number} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="bp-email">
                                E-mail de faturamento
                            </Label>
                            <Input
                                id="bp-email"
                                type="email"
                                value={data.email}
                                maxLength={255}
                                aria-invalid={Boolean(errors.email)}
                                onChange={(event) =>
                                    setData('email', event.target.value)
                                }
                            />
                            <InputError message={errors.email} />
                        </div>
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="bp-address">
                            Endereço (com número e complemento)
                        </Label>
                        <Input
                            id="bp-address"
                            value={data.address_line}
                            maxLength={200}
                            aria-invalid={Boolean(errors.address_line)}
                            onChange={(event) =>
                                setData('address_line', event.target.value)
                            }
                        />
                        <InputError message={errors.address_line} />
                    </div>

                    <div className="grid gap-3 sm:grid-cols-[1fr_88px_120px]">
                        <div className="grid gap-1.5">
                            <Label htmlFor="bp-city">Cidade</Label>
                            <Input
                                id="bp-city"
                                value={data.city}
                                maxLength={120}
                                aria-invalid={Boolean(errors.city)}
                                onChange={(event) =>
                                    setData('city', event.target.value)
                                }
                            />
                            <InputError message={errors.city} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="bp-state">UF</Label>
                            <Input
                                id="bp-state"
                                value={data.state}
                                maxLength={2}
                                autoCapitalize="characters"
                                aria-invalid={Boolean(errors.state)}
                                onChange={(event) =>
                                    setData(
                                        'state',
                                        event.target.value
                                            .replace(/[^a-zA-Z]/g, '')
                                            .toUpperCase(),
                                    )
                                }
                            />
                            <InputError message={errors.state} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="bp-postal">CEP</Label>
                            <Input
                                id="bp-postal"
                                value={formatPostalCode(data.postal_code)}
                                inputMode="numeric"
                                aria-invalid={Boolean(errors.postal_code)}
                                onChange={(event) =>
                                    setData(
                                        'postal_code',
                                        onlyDigits(event.target.value).slice(
                                            0,
                                            8,
                                        ),
                                    )
                                }
                            />
                            <InputError message={errors.postal_code} />
                        </div>
                    </div>

                    <DialogFooter className="mt-1">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner className="size-4" />}
                            Salvar dados
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
