import {
    ChevronDown,
    ChevronUp,
    Eye,
    GripVertical,
    Mail,
    Trash2,
    UserPlus,
} from 'lucide-react';
import { useState } from 'react';
import { recipientColor } from '@/components/envelopes/recipient-colors';
import { SelectableChip } from '@/components/filter-bar';
import InputError from '@/components/input-error';
import { SegmentedControl } from '@/components/segmented-control';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { signingOrderLabels } from '@/lib/labels';
import { cn } from '@/lib/utils';
import type { SigningOrder } from '@/types/enums';
import type { WizardRecipient } from '@/types/models';

const ORDER_HINTS: Record<SigningOrder, string> = {
    sequential: 'Cada signatário recebe o documento após o anterior assinar.',
    parallel: 'Todos recebem o documento imediatamente.',
};

/**
 * Passo 2 — Signatários (DESIGN §6.5). Lista reordenável (arraste ou setas),
 * nome e e-mail por signatário, papel sugerido e ordem de assinatura.
 *
 * Canal e autenticação ficam fixos em e-mail nesta fase (RECONCILIACAO §2):
 * os chips aparecem desabilitados para deixar claro o que está em uso.
 */
export function WizardStepRecipients({
    recipients,
    signingOrder,
    roleSuggestions,
    onChange,
    onSigningOrderChange,
    errors,
    disabled,
}: {
    recipients: WizardRecipient[];
    signingOrder: SigningOrder;
    roleSuggestions: string[];
    onChange: (recipients: WizardRecipient[]) => void;
    onSigningOrderChange: (order: SigningOrder) => void;
    errors: Record<string, string>;
    disabled?: boolean;
}) {
    const [draggingIndex, setDraggingIndex] = useState<number | null>(null);

    const withOrder = (list: WizardRecipient[]): WizardRecipient[] =>
        list.map((recipient, index) => ({
            ...recipient,
            order: index + 1,
            color_index: index % 4,
        }));

    const update = (index: number, patch: Partial<WizardRecipient>): void => {
        onChange(
            withOrder(
                recipients.map((recipient, position) =>
                    position === index ? { ...recipient, ...patch } : recipient,
                ),
            ),
        );
    };

    const remove = (index: number): void => {
        onChange(withOrder(recipients.filter((_, i) => i !== index)));
    };

    const moveTo = (from: number, to: number): void => {
        if (to < 0 || to >= recipients.length || from === to) {
            return;
        }

        const next = [...recipients];
        const [moved] = next.splice(from, 1);
        next.splice(to, 0, moved);
        onChange(withOrder(next));
    };

    const add = (role: string): void => {
        onChange(
            withOrder([
                ...recipients,
                {
                    id: null,
                    client_id: crypto.randomUUID(),
                    name: '',
                    email: '',
                    role,
                    order: recipients.length + 1,
                    color_index: recipients.length % 4,
                    channel: 'email',
                    auth_methods: ['email_otp'],
                },
            ]),
        );
    };

    return (
        <div className="flex flex-col gap-4">
            <div className="border-border bg-card shadow-card flex flex-wrap items-center justify-between gap-3 rounded-xl border px-5 py-3.5">
                <div>
                    <div className="text-[14px] font-semibold">
                        Ordem de assinatura
                    </div>
                    <div className="text-muted-foreground mt-0.5 text-[12.5px]">
                        {ORDER_HINTS[signingOrder]}
                    </div>
                </div>
                <SegmentedControl
                    value={signingOrder}
                    onChange={onSigningOrderChange}
                    ariaLabel="Ordem de assinatura"
                    options={[
                        {
                            value: 'sequential',
                            label: signingOrderLabels.sequential,
                            disabled,
                        },
                        {
                            value: 'parallel',
                            label: signingOrderLabels.parallel,
                            disabled,
                        },
                    ]}
                />
            </div>

            {recipients.map((recipient, index) => {
                const color = recipientColor(index);

                return (
                    <div
                        key={recipient.client_id}
                        draggable={!disabled}
                        onDragStart={() => setDraggingIndex(index)}
                        onDragEnd={() => setDraggingIndex(null)}
                        onDragOver={(event) => {
                            if (draggingIndex === null) {
                                return;
                            }

                            event.preventDefault();
                        }}
                        onDrop={(event) => {
                            event.preventDefault();

                            if (draggingIndex !== null) {
                                moveTo(draggingIndex, index);
                                setDraggingIndex(null);
                            }
                        }}
                        className={cn(
                            'border-border bg-card shadow-card flex flex-col gap-3.5 rounded-xl border px-5 py-[18px]',
                            draggingIndex === index && 'opacity-60',
                        )}
                    >
                        <div className="flex items-center gap-3">
                            <span
                                aria-hidden
                                className="text-muted-foreground hidden cursor-grab md:block"
                                title="Arraste para reordenar"
                            >
                                <GripVertical className="size-4" />
                            </span>
                            <span
                                style={{
                                    backgroundColor: color.soft,
                                    color: color.text,
                                }}
                                className="tabular flex size-7 shrink-0 items-center justify-center rounded-lg text-[12.5px] font-bold"
                            >
                                {index + 1}
                            </span>
                            <span className="min-w-0 flex-1 truncate text-[14px] font-semibold">
                                {recipient.name || `Signatário ${index + 1}`}
                            </span>

                            <div className="flex items-center gap-1">
                                <Button
                                    variant="ghost"
                                    size="icon-xs"
                                    aria-label={`Mover ${recipient.name || `signatário ${index + 1}`} para cima`}
                                    disabled={disabled || index === 0}
                                    onClick={() => moveTo(index, index - 1)}
                                >
                                    <ChevronUp className="size-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon-xs"
                                    aria-label={`Mover ${recipient.name || `signatário ${index + 1}`} para baixo`}
                                    disabled={
                                        disabled ||
                                        index === recipients.length - 1
                                    }
                                    onClick={() => moveTo(index, index + 1)}
                                >
                                    <ChevronDown className="size-4" />
                                </Button>
                            </div>

                            <Select
                                value={recipient.role || 'Parte'}
                                disabled={disabled}
                                onValueChange={(value) =>
                                    update(index, { role: value })
                                }
                            >
                                <SelectTrigger
                                    size="sm"
                                    className="h-[30px] w-auto min-w-[120px] text-[12.5px] font-semibold"
                                    aria-label="Papel do signatário"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {[
                                        ...new Set([
                                            ...roleSuggestions,
                                            recipient.role || 'Parte',
                                        ]),
                                    ]
                                        .filter(Boolean)
                                        .map((role) => (
                                            <SelectItem key={role} value={role}>
                                                {role}
                                            </SelectItem>
                                        ))}
                                </SelectContent>
                            </Select>

                            <Button
                                variant="ghost"
                                size="icon-xs"
                                title="Remover"
                                aria-label={`Remover ${recipient.name || `signatário ${index + 1}`}`}
                                disabled={disabled || recipients.length <= 1}
                                onClick={() => remove(index)}
                                className="hover:bg-danger-bg hover:text-danger"
                            >
                                <Trash2 className="size-[15px]" />
                            </Button>
                        </div>

                        <div
                            className="grid gap-3"
                            style={{
                                gridTemplateColumns:
                                    'repeat(auto-fit, minmax(200px, 1fr))',
                            }}
                        >
                            <div className="grid gap-1.5">
                                <Label
                                    htmlFor={`recipient-name-${recipient.client_id}`}
                                    className="text-[12.5px]"
                                >
                                    Nome completo
                                </Label>
                                <Input
                                    id={`recipient-name-${recipient.client_id}`}
                                    value={recipient.name}
                                    placeholder="Nome do signatário"
                                    maxLength={120}
                                    disabled={disabled}
                                    aria-invalid={Boolean(
                                        errors[`recipients.${index}.name`],
                                    )}
                                    onChange={(event) =>
                                        update(index, {
                                            name: event.target.value,
                                        })
                                    }
                                />
                                <InputError
                                    message={errors[`recipients.${index}.name`]}
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label
                                    htmlFor={`recipient-email-${recipient.client_id}`}
                                    className="text-[12.5px]"
                                >
                                    E-mail
                                </Label>
                                <Input
                                    id={`recipient-email-${recipient.client_id}`}
                                    type="email"
                                    inputMode="email"
                                    autoComplete="off"
                                    value={recipient.email}
                                    placeholder="email@exemplo.com"
                                    maxLength={255}
                                    disabled={disabled}
                                    aria-invalid={Boolean(
                                        errors[`recipients.${index}.email`],
                                    )}
                                    onChange={(event) =>
                                        update(index, {
                                            email: event.target.value,
                                        })
                                    }
                                />
                                <InputError
                                    message={
                                        errors[`recipients.${index}.email`]
                                    }
                                />
                            </div>
                        </div>

                        <div className="flex flex-wrap items-end gap-6">
                            <div className="flex flex-col gap-1.5">
                                <span className="text-[12.5px] font-semibold">
                                    Enviar por
                                </span>
                                <span className="bg-muted text-text-secondary inline-flex items-center gap-[5px] rounded-md px-[7px] py-1 text-[11.5px] font-semibold">
                                    <Mail className="size-3" />
                                    E-mail
                                </span>
                            </div>
                            <div className="flex flex-1 flex-col gap-1.5">
                                <span className="text-[12.5px] font-semibold">
                                    Como o signatário se autentica
                                </span>
                                <div className="flex flex-wrap gap-1.5">
                                    <SelectableChip selected disabled>
                                        Código por e-mail
                                    </SelectableChip>
                                    <SelectableChip selected={false} disabled>
                                        Token SMS · Fase 2
                                    </SelectableChip>
                                </div>
                            </div>
                        </div>
                    </div>
                );
            })}

            <InputError message={errors.recipients} />

            <div className="flex flex-wrap gap-2">
                <Button
                    variant="dashed"
                    disabled={disabled || recipients.length >= 20}
                    onClick={() => add('Parte')}
                >
                    <UserPlus className="size-[15px]" />
                    Adicionar signatário
                </Button>
                <Button
                    variant="dashed"
                    disabled={disabled || recipients.length >= 20}
                    onClick={() => add('Testemunha')}
                >
                    <Eye className="size-[15px]" />
                    Adicionar testemunha
                </Button>
            </div>

            {recipients.length >= 20 && (
                <p className="text-muted-foreground text-[12.5px]">
                    Máximo de 20 signatários por documento.
                </p>
            )}
        </div>
    );
}
