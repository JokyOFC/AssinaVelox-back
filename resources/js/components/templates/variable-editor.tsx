import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
    slugifyKey,
    VARIABLE_TYPE_HINTS,
    type OptionItem,
    type TemplateVariableDraft,
    type VariableOptions,
    type VariableType,
} from './types';

function move<T>(list: T[], from: number, to: number): T[] {
    if (to < 0 || to >= list.length) {
        return list;
    }

    const next = [...list];
    const [item] = next.splice(from, 1);
    next.splice(to, 0, item);

    return next;
}

/**
 * Editor de variáveis tipadas (docs/fase-2/modelos.md §3). Chave, rótulo,
 * tipo, obrigatoriedade, ajuda, valor padrão e opções por tipo. O servidor
 * valida tudo de novo ao salvar; os erros chegam como `variables.{i}.{campo}`.
 */
export function VariableEditor({
    variables,
    onChange,
    types,
    errors,
    max,
    placeholder,
    disabled,
}: {
    variables: TemplateVariableDraft[];
    onChange: (variables: TemplateVariableDraft[]) => void;
    types: OptionItem[];
    errors: Record<string, string>;
    max: number;
    /** Marcador de exemplo: `{{chave}}` (HTML) ou `${chave}` (DOCX). */
    placeholder: (key: string) => string;
    disabled?: boolean;
}) {
    const update = (index: number, patch: Partial<TemplateVariableDraft>) => {
        onChange(
            variables.map((variable, i) =>
                i === index ? { ...variable, ...patch } : variable,
            ),
        );
    };

    const updateOptions = (index: number, patch: VariableOptions) => {
        update(index, {
            options: { ...variables[index].options, ...patch },
        });
    };

    const add = () => {
        const base = `variavel_${variables.length + 1}`;
        onChange([
            ...variables,
            {
                key: base,
                label: `Variável ${variables.length + 1}`,
                type: 'text',
                required: true,
                help_text: null,
                default_value: null,
                options: {},
            },
        ]);
    };

    return (
        <div className="flex flex-col gap-3">
            {errors.variables && (
                <p className="text-danger text-[13px]">{errors.variables}</p>
            )}
            {variables.length === 0 && (
                <p className="text-muted-foreground text-[13px]">
                    Nenhuma variável. Variáveis viram um formulário ao usar o
                    modelo, e o valor preenchido é inserido no documento como
                    texto.
                </p>
            )}
            {variables.map((variable, index) => {
                const prefix = `variables.${index}`;
                const err = (field: string) => errors[`${prefix}.${field}`];

                return (
                    <div
                        key={index}
                        className="border-border rounded-lg border bg-white p-3.5"
                        data-testid="template-variable"
                    >
                        <div className="grid gap-3 md:grid-cols-[1fr_1fr_190px_auto]">
                            <div className="grid gap-1.5">
                                <Label htmlFor={`${prefix}.label`}>
                                    Rótulo
                                </Label>
                                <Input
                                    id={`${prefix}.label`}
                                    value={variable.label}
                                    maxLength={120}
                                    disabled={disabled}
                                    onChange={(e) =>
                                        update(index, { label: e.target.value })
                                    }
                                    onBlur={() => {
                                        if (
                                            /^variavel_\d+$/.test(
                                                variable.key,
                                            ) &&
                                            variable.label.trim()
                                        ) {
                                            update(index, {
                                                key: slugifyKey(variable.label),
                                            });
                                        }
                                    }}
                                />
                                <InputError message={err('label')} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`${prefix}.key`}>Chave</Label>
                                <Input
                                    id={`${prefix}.key`}
                                    value={variable.key}
                                    maxLength={64}
                                    disabled={disabled}
                                    className="font-mono text-[12.5px]"
                                    onChange={(e) =>
                                        update(index, {
                                            key: e.target.value
                                                .toLowerCase()
                                                .replace(/[^a-z0-9_]/g, '_'),
                                        })
                                    }
                                />
                                <p className="text-muted-foreground font-mono text-[11.5px]">
                                    {placeholder(variable.key || 'chave')}
                                </p>
                                <InputError message={err('key')} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`${prefix}.type`}>Tipo</Label>
                                <Select
                                    value={variable.type}
                                    disabled={disabled}
                                    onValueChange={(value) =>
                                        update(index, {
                                            type: value as VariableType,
                                            options: {},
                                            default_value: null,
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        id={`${prefix}.type`}
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {types.map((type) => (
                                            <SelectItem
                                                key={type.value}
                                                value={type.value}
                                            >
                                                {type.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={err('type')} />
                            </div>
                            <div className="flex items-start gap-1 pt-6">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label="Mover para cima"
                                    disabled={disabled || index === 0}
                                    onClick={() =>
                                        onChange(
                                            move(variables, index, index - 1),
                                        )
                                    }
                                >
                                    <ArrowUp />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label="Mover para baixo"
                                    disabled={
                                        disabled ||
                                        index === variables.length - 1
                                    }
                                    onClick={() =>
                                        onChange(
                                            move(variables, index, index + 1),
                                        )
                                    }
                                >
                                    <ArrowDown />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label={`Remover ${variable.label}`}
                                    disabled={disabled}
                                    onClick={() =>
                                        onChange(
                                            variables.filter(
                                                (_, i) => i !== index,
                                            ),
                                        )
                                    }
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </div>

                        <p className="text-muted-foreground mt-2 text-[12px]">
                            {VARIABLE_TYPE_HINTS[variable.type]}
                        </p>

                        <div className="mt-3 grid gap-3 md:grid-cols-3">
                            <label className="flex items-center gap-2 text-[13px]">
                                <Checkbox
                                    checked={variable.required}
                                    disabled={
                                        disabled || variable.type === 'boolean'
                                    }
                                    onCheckedChange={(checked) =>
                                        update(index, {
                                            required: checked === true,
                                        })
                                    }
                                />
                                Preenchimento obrigatório
                            </label>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`${prefix}.help`}>
                                    Ajuda (opcional)
                                </Label>
                                <Input
                                    id={`${prefix}.help`}
                                    value={variable.help_text ?? ''}
                                    maxLength={255}
                                    disabled={disabled}
                                    onChange={(e) =>
                                        update(index, {
                                            help_text: e.target.value || null,
                                        })
                                    }
                                    placeholder="Texto exibido abaixo do campo"
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`${prefix}.default`}>
                                    Valor padrão (opcional)
                                </Label>
                                <Input
                                    id={`${prefix}.default`}
                                    value={variable.default_value ?? ''}
                                    maxLength={500}
                                    disabled={disabled}
                                    onChange={(e) =>
                                        update(index, {
                                            default_value:
                                                e.target.value || null,
                                        })
                                    }
                                    placeholder={
                                        variable.type === 'date'
                                            ? 'dd/mm/aaaa'
                                            : variable.type === 'boolean'
                                              ? '1 = sim, 0 = não'
                                              : ''
                                    }
                                />
                                <InputError message={err('default_value')} />
                            </div>
                        </div>

                        <TypeOptions
                            prefix={prefix}
                            variable={variable}
                            disabled={disabled}
                            errors={errors}
                            onChange={(patch) => updateOptions(index, patch)}
                        />
                    </div>
                );
            })}
            <Button
                type="button"
                variant="outline"
                className="self-start"
                disabled={disabled || variables.length >= max}
                onClick={add}
            >
                <Plus /> Adicionar variável
            </Button>
        </div>
    );
}

function TypeOptions({
    prefix,
    variable,
    onChange,
    errors,
    disabled,
}: {
    prefix: string;
    variable: TemplateVariableDraft;
    onChange: (patch: VariableOptions) => void;
    errors: Record<string, string>;
    disabled?: boolean;
}) {
    const options = variable.options ?? {};
    const err = (field: string) => errors[`${prefix}.options.${field}`];
    const text = (value: string | number | null | undefined): string =>
        value === null || value === undefined ? '' : String(value);

    if (variable.type === 'text' || variable.type === 'long_text') {
        return (
            <div className="mt-3 grid max-w-[220px] gap-1.5">
                <Label htmlFor={`${prefix}.max_length`}>
                    Limite de caracteres
                </Label>
                <Input
                    id={`${prefix}.max_length`}
                    inputMode="numeric"
                    value={text(options.max_length)}
                    disabled={disabled}
                    onChange={(e) =>
                        onChange({ max_length: e.target.value || null })
                    }
                    placeholder={variable.type === 'text' ? '500' : '5000'}
                />
                <InputError message={err('max_length')} />
            </div>
        );
    }

    if (
        variable.type === 'number' ||
        variable.type === 'currency' ||
        variable.type === 'date'
    ) {
        const inputType = variable.type === 'date' ? 'date' : 'text';
        const minValue =
            variable.type === 'currency' &&
            options.min === undefined &&
            options.min_cents != null
                ? (options.min_cents / 100).toFixed(2).replace('.', ',')
                : text(options.min);
        const maxValue =
            variable.type === 'currency' &&
            options.max === undefined &&
            options.max_cents != null
                ? (options.max_cents / 100).toFixed(2).replace('.', ',')
                : text(options.max);

        return (
            <div className="mt-3 grid gap-3 sm:grid-cols-3">
                <div className="grid gap-1.5">
                    <Label htmlFor={`${prefix}.min`}>Mínimo (opcional)</Label>
                    <Input
                        id={`${prefix}.min`}
                        type={inputType}
                        value={minValue}
                        disabled={disabled}
                        onChange={(e) =>
                            onChange({
                                min: e.target.value || null,
                                min_cents: null,
                            })
                        }
                    />
                    <InputError message={err('min')} />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor={`${prefix}.max`}>Máximo (opcional)</Label>
                    <Input
                        id={`${prefix}.max`}
                        type={inputType}
                        value={maxValue}
                        disabled={disabled}
                        onChange={(e) =>
                            onChange({
                                max: e.target.value || null,
                                max_cents: null,
                            })
                        }
                    />
                    <InputError message={err('max')} />
                </div>
                {variable.type === 'number' && (
                    <div className="grid gap-1.5">
                        <Label htmlFor={`${prefix}.decimals`}>
                            Casas decimais
                        </Label>
                        <Input
                            id={`${prefix}.decimals`}
                            inputMode="numeric"
                            value={text(options.decimals ?? 2)}
                            disabled={disabled}
                            onChange={(e) =>
                                onChange({ decimals: e.target.value })
                            }
                        />
                        <InputError message={err('decimals')} />
                    </div>
                )}
            </div>
        );
    }

    if (variable.type === 'select') {
        return (
            <div className="mt-3 grid max-w-[420px] gap-1.5">
                <Label htmlFor={`${prefix}.choices`}>
                    Opções (uma por linha)
                </Label>
                <Textarea
                    id={`${prefix}.choices`}
                    rows={3}
                    value={(options.choices ?? []).join('\n')}
                    disabled={disabled}
                    onChange={(e) =>
                        onChange({ choices: e.target.value.split('\n') })
                    }
                    placeholder={'Mensal\nAnual'}
                />
                <InputError message={err('choices')} />
            </div>
        );
    }

    return null;
}
