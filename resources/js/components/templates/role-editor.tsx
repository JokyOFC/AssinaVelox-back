import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { recipientColor } from '@/components/envelopes/recipient-colors';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { ParticipantRole } from '@/types/enums';
import type { ParticipantRoleOption, TemplateRoleDraft } from './types';

/**
 * Participantes nomeados do modelo ("Locatário", "Locador", "Testemunha 1").
 * Ao usar o modelo, cada um vira um destinatário na ordem desta lista. O tipo
 * (signatário, testemunha, aprovador, visualizador) só aparece habilitado com a
 * flag `participant_roles` — o servidor confere de novo.
 */
export function RoleEditor({
    roles,
    onChange,
    options,
    errors,
    max,
    nameMax,
    fieldCounts,
    disabled,
}: {
    roles: TemplateRoleDraft[];
    onChange: (roles: TemplateRoleDraft[]) => void;
    options: ParticipantRoleOption[];
    errors: Record<string, string>;
    max: number;
    nameMax: number;
    fieldCounts?: Record<string, number>;
    disabled?: boolean;
}) {
    const update = (index: number, patch: Partial<TemplateRoleDraft>) =>
        onChange(
            roles.map((role, i) =>
                i === index ? { ...role, ...patch } : role,
            ),
        );

    const move = (from: number, to: number) => {
        if (to < 0 || to >= roles.length) {
            return;
        }

        const next = [...roles];
        const [item] = next.splice(from, 1);
        next.splice(to, 0, item);
        onChange(next);
    };

    const nonSignerLocked = options.some((option) => !option.enabled);

    return (
        <div className="flex flex-col gap-2.5">
            {errors.roles && (
                <p className="text-danger text-[13px]">{errors.roles}</p>
            )}
            {roles.map((role, index) => {
                const color = recipientColor(index);
                const fields = fieldCounts?.[role.ref] ?? 0;

                return (
                    <div
                        key={role.ref}
                        className="border-border flex flex-wrap items-start gap-3 rounded-lg border bg-white p-3"
                        data-testid="template-role"
                    >
                        <span
                            className="mt-2.5 size-2.5 shrink-0 rounded-full"
                            style={{ backgroundColor: color.solid }}
                            aria-hidden
                        />
                        <span className="text-muted-foreground mt-2 w-5 text-[12px] font-semibold">
                            {index + 1}º
                        </span>
                        <div className="grid min-w-[200px] flex-1 gap-1">
                            <Input
                                aria-label={`Nome do participante ${index + 1}`}
                                value={role.name}
                                maxLength={nameMax}
                                disabled={disabled}
                                onChange={(e) =>
                                    update(index, { name: e.target.value })
                                }
                                placeholder="Ex.: Locatário"
                            />
                            <InputError
                                message={errors[`roles.${index}.name`]}
                            />
                        </div>
                        <div className="grid w-[190px] gap-1">
                            <Select
                                value={role.participant_role}
                                disabled={disabled}
                                onValueChange={(value) =>
                                    update(index, {
                                        participant_role:
                                            value as ParticipantRole,
                                    })
                                }
                            >
                                <SelectTrigger
                                    aria-label="Tipo de participante"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {options.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                            disabled={!option.enabled}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError
                                message={
                                    errors[`roles.${index}.participant_role`]
                                }
                            />
                        </div>
                        {fieldCounts && (
                            <span className="text-muted-foreground mt-2 text-[12px]">
                                {fields === 1 ? '1 campo' : `${fields} campos`}
                            </span>
                        )}
                        <div className="ml-auto flex gap-1">
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                aria-label="Mover para cima"
                                disabled={disabled || index === 0}
                                onClick={() => move(index, index - 1)}
                            >
                                <ArrowUp />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                aria-label="Mover para baixo"
                                disabled={
                                    disabled || index === roles.length - 1
                                }
                                onClick={() => move(index, index + 1)}
                            >
                                <ArrowDown />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                aria-label={`Remover ${role.name || 'participante'}`}
                                disabled={disabled || roles.length === 1}
                                onClick={() =>
                                    onChange(
                                        roles.filter((_, i) => i !== index),
                                    )
                                }
                            >
                                <Trash2 />
                            </Button>
                        </div>
                    </div>
                );
            })}
            <div className="flex flex-wrap items-center gap-3">
                <Button
                    type="button"
                    variant="outline"
                    disabled={disabled || roles.length >= max}
                    onClick={() =>
                        onChange([
                            ...roles,
                            {
                                ref: `novo-${crypto.randomUUID()}`,
                                name: '',
                                participant_role: 'signer',
                            },
                        ])
                    }
                >
                    <Plus /> Adicionar participante
                </Button>
                {nonSignerLocked && (
                    <span className="text-muted-foreground text-[12px]">
                        Testemunha, aprovador e visualizador ainda não estão
                        disponíveis no plano desta conta.
                    </span>
                )}
            </div>
        </div>
    );
}
