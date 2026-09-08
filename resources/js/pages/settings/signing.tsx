import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { SelectableChip } from '@/components/filter-bar';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { signingOrderLabels } from '@/lib/labels';
import { signing as settingsSigning } from '@/routes/settings';
import { update as updateSigning } from '@/routes/settings/signing';
import type { SigningOrder } from '@/types';

export interface SettingsSigningProps {
    defaults: {
        expires_in_days: number;
        signing_order: SigningOrder;
        initials_on_all_pages: boolean;
        allow_drawn_signature: true;
        allow_typed_signature: boolean;
        allow_uploaded_signature: boolean;
    };
    phase2: {
        reminders: false;
        auth_methods: ['email_otp'];
        channels: ['email'];
    };
}

const EXPIRATION_OPTIONS = [3, 7, 15, 30, 45, 60, 90];

function SwitchRow({
    title,
    description,
    checked,
    onCheckedChange,
    disabled,
    phase2,
}: {
    title: string;
    description: string;
    checked: boolean;
    onCheckedChange?: (checked: boolean) => void;
    disabled?: boolean;
    phase2?: boolean;
}) {
    return (
        <div className="border-muted flex items-center justify-between gap-4 border-t py-3 first:border-t-0">
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2 text-[13.5px] font-semibold">
                    {title}
                    {phase2 && <Badge variant="phase">Fase 2</Badge>}
                </div>
                <div className="text-muted-foreground mt-0.5 text-[12.5px]">
                    {description}
                </div>
            </div>
            <Switch
                checked={checked}
                onCheckedChange={onCheckedChange}
                disabled={disabled}
                className="data-[state=unchecked]:bg-border-dashed h-[22px] w-10 [&>span]:size-[18px] [&>span]:data-[state=checked]:translate-x-[18px]"
            />
        </div>
    );
}

/** Configurações › Padrões de assinatura (ROUTES §2.13; DESIGN §6.11). */
export default function SettingsSigning({ defaults }: SettingsSigningProps) {
    const form = useForm({
        expires_in_days: defaults.expires_in_days,
        signing_order: defaults.signing_order,
        initials_on_all_pages: defaults.initials_on_all_pages,
        allow_typed_signature: defaults.allow_typed_signature,
        allow_uploaded_signature: defaults.allow_uploaded_signature,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.patch(updateSigning.url(), { preserveScroll: true });
    };

    return (
        <>
            <Head title="Configurações · Padrões de assinatura" />

            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="border-border bg-card shadow-card flex flex-col gap-4 rounded-xl border p-5">
                    <Heading
                        variant="small"
                        title="Padrões de novas solicitações"
                        description="Podem ser alterados em cada envio."
                    />
                    <div
                        className="grid gap-3"
                        style={{
                            gridTemplateColumns:
                                'repeat(auto-fit, minmax(200px, 1fr))',
                        }}
                    >
                        <div className="grid gap-1.5">
                            <Label htmlFor="expires_in_days">
                                Prazo para assinatura
                            </Label>
                            <Select
                                value={String(form.data.expires_in_days)}
                                onValueChange={(v) =>
                                    form.setData('expires_in_days', Number(v))
                                }
                            >
                                <SelectTrigger
                                    id="expires_in_days"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {EXPIRATION_OPTIONS.map((days) => (
                                        <SelectItem
                                            key={days}
                                            value={String(days)}
                                        >
                                            {days} dias
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.expires_in_days} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="signing_order">
                                Ordem de assinatura
                            </Label>
                            <Select
                                value={form.data.signing_order}
                                onValueChange={(v) =>
                                    form.setData(
                                        'signing_order',
                                        v as SigningOrder,
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="signing_order"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {(
                                        Object.keys(
                                            signingOrderLabels,
                                        ) as SigningOrder[]
                                    ).map((key) => (
                                        <SelectItem key={key} value={key}>
                                            {signingOrderLabels[key]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.signing_order} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label className="flex items-center gap-2">
                                Lembretes automáticos{' '}
                                <Badge variant="phase">Fase 2</Badge>
                            </Label>
                            <Select value="off" disabled>
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="off">
                                        Desativados
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <div className="grid gap-1.5">
                        <span className="text-[13px] font-semibold">
                            Autenticação padrão dos signatários
                        </span>
                        <div className="flex flex-wrap gap-2">
                            <SelectableChip selected disabled>
                                Token e-mail
                            </SelectableChip>
                            <SelectableChip selected={false} disabled>
                                Token SMS · Fase 2
                            </SelectableChip>
                            <SelectableChip selected={false} disabled>
                                Token WhatsApp · Fase 2
                            </SelectableChip>
                        </div>
                        <span className="text-muted-foreground text-[12px] leading-[1.5]">
                            O código por e-mail é o método disponível na Fase 1.
                            Outros fatores aumentam a robustez do aceite e
                            chegam na Fase 2.
                        </span>
                    </div>
                </div>

                <div className="border-border bg-card shadow-card flex flex-col rounded-xl border p-5">
                    <Heading
                        variant="small"
                        title="Canais e recursos"
                        className="mb-2"
                    />
                    <SwitchRow
                        title="Envio por e-mail"
                        description="Convites e reenvios por e-mail"
                        checked
                        disabled
                    />
                    <SwitchRow
                        title="Envio por WhatsApp"
                        description="Requer celular do signatário"
                        checked={false}
                        disabled
                        phase2
                    />
                    <SwitchRow
                        title="Envio por SMS"
                        description="Alternativa ao WhatsApp"
                        checked={false}
                        disabled
                        phase2
                    />
                    <SwitchRow
                        title="Permitir assinatura desenhada"
                        description="O signatário desenha a assinatura no celular ou computador (sempre permitido)"
                        checked
                        disabled
                    />
                    <SwitchRow
                        title="Permitir assinatura digitada"
                        description="O signatário digita o nome com uma fonte manuscrita"
                        checked={form.data.allow_typed_signature}
                        onCheckedChange={(v) =>
                            form.setData('allow_typed_signature', v)
                        }
                    />
                    <SwitchRow
                        title="Permitir imagem de assinatura"
                        description="O signatário envia uma imagem PNG/JPG da assinatura"
                        checked={form.data.allow_uploaded_signature}
                        onCheckedChange={(v) =>
                            form.setData('allow_uploaded_signature', v)
                        }
                    />
                    <SwitchRow
                        title="Rubrica automática em todas as páginas"
                        description="Adiciona um campo de rubrica por página ao criar solicitações"
                        checked={form.data.initials_on_all_pages}
                        onCheckedChange={(v) =>
                            form.setData('initials_on_all_pages', v)
                        }
                    />
                </div>

                <div className="flex justify-end">
                    <Button
                        type="submit"
                        disabled={form.processing || !form.isDirty}
                    >
                        {form.processing && <Spinner />}
                        Salvar alterações
                    </Button>
                </div>
            </form>
        </>
    );
}

SettingsSigning.layout = {
    breadcrumbs: [{ title: 'Configurações', href: settingsSigning() }],
};
