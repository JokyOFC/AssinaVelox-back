import { Check, Lock } from 'lucide-react';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { SignerField } from '@/components/sign/signer-field-layer';
import { fieldTypeLabels } from '@/lib/labels';
import { cn } from '@/lib/utils';

/** Limite padrão de `text` no aceite; o servidor manda o real em `limits`. */
export const TEXT_FIELD_MAX = 500;

export interface FieldChecklistProps {
    fields: SignerField[];
    values: Record<string, string | boolean>;
    onChange: (fieldId: string, value: string | boolean) => void;
    /** Campo destacado (clicado no documento). */
    activeId: string | null;
    /** Registra o elemento de cada campo para poder focá-lo pelo documento. */
    registerRef: (fieldId: string, element: HTMLElement | null) => void;
    /** `limits.max_text_length` das props. */
    maxTextLength?: number;
    /** Erros por campo (`errors['fields.<id>']`). */
    errors?: Record<string, string>;
    className?: string;
}

/**
 * Campos que o signatário preenche fora da assinatura (texto, marcação) e os
 * carimbados pelo servidor (nome, data), listados fora do PDF.
 *
 * No celular, digitar dentro de uma caixa de 2 cm sobre a página é inviável:
 * clicar no campo do documento destaca e foca a linha correspondente aqui.
 */
export function FieldChecklist({
    fields,
    values,
    onChange,
    activeId,
    registerRef,
    maxTextLength = TEXT_FIELD_MAX,
    errors,
    className,
}: FieldChecklistProps) {
    if (fields.length === 0) {
        return null;
    }

    return (
        <div className={cn('flex flex-col gap-2.5', className)}>
            <p className="text-text-secondary text-[12.5px] font-semibold">
                Campos a preencher
            </p>

            {fields.map((field) => {
                const value = values[field.id];
                const label =
                    field.label?.trim() || fieldTypeLabels[field.type];
                const active = activeId === field.id;

                if (field.type === 'checkbox') {
                    return (
                        <label
                            key={field.id}
                            ref={(element) => registerRef(field.id, element)}
                            className={cn(
                                'flex cursor-pointer items-start gap-2.5 rounded-[10px] border p-2.5 text-[12.5px]',
                                active
                                    ? 'border-primary bg-primary-soft'
                                    : 'border-border',
                            )}
                        >
                            <Checkbox
                                checked={value === true}
                                onCheckedChange={(checked) =>
                                    onChange(field.id, checked === true)
                                }
                                className="mt-px"
                            />
                            <span className="text-text-secondary leading-[1.45]">
                                {label}
                                {field.required && (
                                    <span className="text-danger"> *</span>
                                )}
                            </span>
                        </label>
                    );
                }

                if (field.type === 'name' || field.type === 'date') {
                    return (
                        <div
                            key={field.id}
                            ref={(element) => registerRef(field.id, element)}
                            className={cn(
                                'flex items-center gap-2.5 rounded-[10px] border p-2.5 text-[12.5px]',
                                active
                                    ? 'border-primary bg-primary-soft'
                                    : 'border-border',
                            )}
                        >
                            <Lock className="text-muted-foreground size-3.5 shrink-0" />
                            <span className="min-w-0 flex-1">
                                <span className="text-muted-foreground block text-[11.5px]">
                                    {label}
                                </span>
                                <span className="text-foreground block truncate font-medium">
                                    {field.prefill ?? '—'}
                                </span>
                            </span>
                            <Check className="text-success size-3.5 shrink-0" />
                        </div>
                    );
                }

                const text = typeof value === 'string' ? value : '';

                return (
                    <div
                        key={field.id}
                        className={cn(
                            'rounded-[10px] border p-2.5',
                            active
                                ? 'border-primary bg-primary-soft'
                                : 'border-border',
                        )}
                    >
                        <Label
                            htmlFor={`field-${field.id}`}
                            className="text-[12px]"
                        >
                            {label}
                            {field.required && (
                                <span className="text-danger"> *</span>
                            )}
                        </Label>
                        <Input
                            id={`field-${field.id}`}
                            ref={(element) => registerRef(field.id, element)}
                            value={text}
                            maxLength={maxTextLength}
                            placeholder={field.placeholder ?? undefined}
                            aria-invalid={Boolean(
                                errors?.[`fields.${field.id}`],
                            )}
                            onChange={(event) =>
                                onChange(field.id, event.target.value)
                            }
                            className="mt-1 h-9"
                        />
                        {errors?.[`fields.${field.id}`] && (
                            <p
                                role="alert"
                                className="text-danger mt-1 text-[12px]"
                            >
                                {errors[`fields.${field.id}`]}
                            </p>
                        )}
                    </div>
                );
            })}
        </div>
    );
}
