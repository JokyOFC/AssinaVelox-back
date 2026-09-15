import { usePage } from '@inertiajs/react';
import { FlaskConical, Plus, Save, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { firstError, getJson, requestJson } from '@/components/anchors/http';
import type {
    AnchorFieldType,
    AnchorPlacement,
    AnchorRule,
    AnchorRulesPayload,
    AnchorRulesTestResult,
} from '@/components/anchors/types';
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
import { Spinner } from '@/components/ui/spinner';
import { TabsContent, TabsTrigger } from '@/components/ui/tabs';
import { plural } from '@/lib/format';
import {
    index as rulesIndex,
    test as rulesTest,
    update as rulesUpdate,
} from '@/routes/anchors/template';

const NO_ROLE = 'none';

function useAnchorsFlag(): boolean {
    const { features } = usePage().props;

    return features?.field_anchors === true;
}

/** Aba "Âncoras" do editor de modelo — só com a flag `field_anchors`. */
export function AnchorRulesTabTrigger() {
    if (!useAnchorsFlag()) {
        return null;
    }

    return <TabsTrigger value="anchors">Âncoras</TabsTrigger>;
}

export function AnchorRulesTabContent({
    templateId,
    disabled,
}: {
    templateId: string;
    disabled?: boolean;
}) {
    if (!useAnchorsFlag()) {
        return null;
    }

    return (
        <TabsContent value="anchors">
            <AnchorRulesEditor templateId={templateId} disabled={disabled} />
        </TabsContent>
    );
}

function blankRule(): AnchorRule {
    return {
        pattern: '',
        field_type: 'signature',
        role_position: null,
        placement: 'below',
        offset_x_pt: 0,
        offset_y_pt: 0,
        width_pt: null,
        height_pt: null,
        required: true,
        occurrence: 'all',
    };
}

function numberOrNull(value: string): number | null {
    if (value.trim() === '') {
        return null;
    }

    const parsed = Number(value.replace(',', '.'));

    return Number.isFinite(parsed) ? parsed : null;
}

/**
 * Regras de âncora do modelo (Fase 3 §3.2). Onde o documento gerado tiver o
 * texto da regra, o editor do envelope SUGERE um campo para o participante
 * escolhido — e a sugestão sempre passa pela revisão de quem prepara.
 * Salvar as regras não cria versão do modelo.
 */
export function AnchorRulesEditor({
    templateId,
    disabled,
}: {
    templateId: string;
    disabled?: boolean;
}) {
    const [payload, setPayload] = useState<AnchorRulesPayload | null>(null);
    const [rules, setRules] = useState<AnchorRule[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [message, setMessage] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [testing, setTesting] = useState(false);
    const [result, setResult] = useState<AnchorRulesTestResult | null>(null);
    const [dirty, setDirty] = useState(false);

    const load = useCallback(async () => {
        const response = await getJson<AnchorRulesPayload>(
            rulesIndex.url(templateId),
        );

        if (response.ok && response.body) {
            setPayload(response.body);
            setRules(response.body.rules);
            setDirty(false);
        } else {
            setMessage(firstError(response));
        }
    }, [templateId]);

    useEffect(() => {
        void load();
    }, [load]);

    const change = (index: number, patch: Partial<AnchorRule>): void => {
        setRules((rows) =>
            rows.map((row, i) => (i === index ? { ...row, ...patch } : row)),
        );
        setDirty(true);
        setResult(null);
    };

    const save = async (): Promise<void> => {
        setSaving(true);
        setErrors({});
        setMessage(null);

        const response = await requestJson<AnchorRulesPayload>(
            'PUT',
            rulesUpdate.url(templateId),
            {
                rules: rules.map((rule) => ({
                    pattern: rule.pattern,
                    field_type: rule.field_type,
                    role_position: rule.role_position,
                    placement: rule.placement,
                    offset_x_pt: rule.offset_x_pt,
                    offset_y_pt: rule.offset_y_pt,
                    width_pt: rule.width_pt,
                    height_pt: rule.height_pt,
                    required: rule.required,
                    occurrence: rule.occurrence,
                })),
            },
        );

        setSaving(false);

        if (response.ok && response.body) {
            setPayload(response.body);
            setRules(response.body.rules);
            setDirty(false);
            setMessage(
                'Regras salvas. Valem para os próximos documentos gerados deste modelo.',
            );

            return;
        }

        const body = response.body as {
            errors?: Record<string, string[] | string>;
        } | null;
        const flat: Record<string, string> = {};

        for (const [key, value] of Object.entries(body?.errors ?? {})) {
            flat[key] = Array.isArray(value) ? (value[0] ?? '') : value;
        }

        setErrors(flat);
        setMessage(firstError(response));
    };

    const runTest = async (): Promise<void> => {
        setTesting(true);
        setMessage(null);

        const response = await requestJson<AnchorRulesTestResult>(
            'POST',
            rulesTest.url(templateId),
            {},
        );

        setTesting(false);

        if (response.ok && response.body) {
            setResult(response.body);
        } else {
            setMessage(firstError(response));
        }
    };

    if (payload === null) {
        return (
            <div className="text-muted-foreground flex items-center gap-2 py-6 text-[13px]">
                {message ?? (
                    <>
                        <Spinner /> Carregando as regras…
                    </>
                )}
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-4">
            <p className="text-muted-foreground max-w-[760px] text-[13px] leading-[1.55]">
                Onde o documento gerado tiver o texto da regra, o editor sugere
                um campo para o participante escolhido. A busca é literal:
                maiúsculas, acentos e espaços extras não importam, e símbolos
                contam como texto. Marcadores como{' '}
                <code className="text-foreground">
                    {'{{assinatura:papel}}'}
                </code>{' '}
                também são procurados. Toda sugestão passa pela revisão de quem
                prepara o documento antes do envio.
            </p>

            {rules.length === 0 && (
                <p className="border-border text-muted-foreground rounded-xl border border-dashed p-4 text-[13px]">
                    Nenhuma regra ainda.
                </p>
            )}

            {rules.map((rule, index) => (
                <div
                    key={rule.id ?? `new-${index}`}
                    className="border-border bg-card shadow-card grid gap-3 rounded-xl border p-4 md:grid-cols-6"
                >
                    <div className="grid gap-1.5 md:col-span-4">
                        <Label htmlFor={`anchor-rule-${index}`}>
                            Texto no documento
                        </Label>
                        <Input
                            id={`anchor-rule-${index}`}
                            value={rule.pattern}
                            maxLength={payload.limits.pattern_max}
                            placeholder="Assinatura do locatário"
                            disabled={disabled}
                            onChange={(event) =>
                                change(index, { pattern: event.target.value })
                            }
                        />
                        <InputError
                            message={errors[`rules.${index}.pattern`]}
                        />
                        {result && rule.id && (
                            <span className="text-muted-foreground text-[12px]">
                                No PDF do modelo:{' '}
                                {plural(
                                    result.rules[rule.id] ?? 0,
                                    'ocorrência',
                                    'ocorrências',
                                )}
                            </span>
                        )}
                    </div>

                    <RuleSelect
                        className="md:col-span-2"
                        label="Campo sugerido"
                        value={rule.field_type}
                        options={payload.field_types}
                        disabled={disabled}
                        onChange={(value) =>
                            change(index, {
                                field_type: value as AnchorFieldType,
                            })
                        }
                        error={errors[`rules.${index}.field_type`]}
                    />

                    <RuleSelect
                        className="md:col-span-2"
                        label="Participante"
                        value={
                            rule.role_position === null
                                ? NO_ROLE
                                : String(rule.role_position)
                        }
                        options={[
                            { value: NO_ROLE, label: 'Escolher ao revisar' },
                            ...payload.roles.map((role) => ({
                                value: String(role.position),
                                label: `${role.position}. ${role.name}`,
                            })),
                        ]}
                        disabled={disabled}
                        onChange={(value) =>
                            change(index, {
                                role_position:
                                    value === NO_ROLE ? null : Number(value),
                            })
                        }
                        error={errors[`rules.${index}.role_position`]}
                    />

                    <RuleSelect
                        className="md:col-span-2"
                        label="Posição"
                        value={rule.placement}
                        options={payload.placements}
                        disabled={disabled}
                        onChange={(value) =>
                            change(index, {
                                placement: value as AnchorPlacement,
                            })
                        }
                        error={errors[`rules.${index}.placement`]}
                    />

                    <RuleSelect
                        className="md:col-span-2"
                        label="Ocorrências"
                        value={rule.occurrence}
                        options={[
                            { value: 'all', label: 'Todas' },
                            { value: 'first', label: 'Só a primeira' },
                        ]}
                        disabled={disabled}
                        onChange={(value) =>
                            change(index, {
                                occurrence: value === 'first' ? 'first' : 'all',
                            })
                        }
                    />

                    <NumberField
                        label="Deslocar → (pt)"
                        value={rule.offset_x_pt}
                        disabled={disabled}
                        error={errors[`rules.${index}.offset_x_pt`]}
                        onChange={(value) =>
                            change(index, { offset_x_pt: value ?? 0 })
                        }
                    />
                    <NumberField
                        label="Deslocar ↓ (pt)"
                        value={rule.offset_y_pt}
                        disabled={disabled}
                        error={errors[`rules.${index}.offset_y_pt`]}
                        onChange={(value) =>
                            change(index, { offset_y_pt: value ?? 0 })
                        }
                    />
                    <NumberField
                        label="Largura (pt)"
                        value={rule.width_pt}
                        placeholder="padrão"
                        disabled={disabled}
                        error={errors[`rules.${index}.width_pt`]}
                        onChange={(value) => change(index, { width_pt: value })}
                    />
                    <NumberField
                        label="Altura (pt)"
                        value={rule.height_pt}
                        placeholder="padrão"
                        disabled={disabled}
                        error={errors[`rules.${index}.height_pt`]}
                        onChange={(value) =>
                            change(index, { height_pt: value })
                        }
                    />

                    <div className="flex items-end justify-between gap-2 md:col-span-2">
                        <label className="text-text-secondary flex cursor-pointer items-center gap-2 pb-2 text-[12.5px]">
                            <Checkbox
                                checked={rule.required}
                                disabled={disabled}
                                onCheckedChange={(value) =>
                                    change(index, { required: value === true })
                                }
                            />
                            Obrigatório
                        </label>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            aria-label="Remover regra"
                            disabled={disabled}
                            onClick={() => {
                                setRules((rows) =>
                                    rows.filter((_, i) => i !== index),
                                );
                                setDirty(true);
                            }}
                        >
                            <Trash2 />
                        </Button>
                    </div>
                </div>
            ))}

            <InputError message={errors.rules} />

            <div className="flex flex-wrap items-center gap-2">
                <Button
                    variant="dashed"
                    size="sm"
                    disabled={
                        disabled || rules.length >= payload.limits.max_rules
                    }
                    onClick={() => {
                        setRules((rows) => [...rows, blankRule()]);
                        setDirty(true);
                    }}
                >
                    <Plus />
                    Adicionar regra
                </Button>
                <Button
                    size="sm"
                    disabled={disabled || saving || !dirty}
                    onClick={() => void save()}
                >
                    {saving ? <Spinner /> : <Save />}
                    Salvar regras
                </Button>
                {payload.can_test && (
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={testing || dirty || rules.length === 0}
                        title={
                            dirty
                                ? 'Salve as regras antes de testar'
                                : undefined
                        }
                        onClick={() => void runTest()}
                    >
                        {testing ? <Spinner /> : <FlaskConical />}
                        Testar no PDF do modelo
                    </Button>
                )}
            </div>

            {message && (
                <p role="status" className="text-text-secondary text-[13px]">
                    {message}
                </p>
            )}

            {result && (
                <p className="text-muted-foreground text-[12.5px]">
                    PDF do modelo: {plural(result.pages, 'página')},{' '}
                    {plural(result.markers, 'marcador', 'marcadores')}
                    {result.pages_without_text.length > 0 &&
                        `, ${plural(result.pages_without_text.length, 'página', 'páginas')} sem texto selecionável`}
                    .
                </p>
            )}

            {!payload.can_test && (
                <p className="text-muted-foreground text-[12.5px]">
                    O teste está disponível para modelos em PDF. Nos modelos
                    HTML e DOCX as regras valem no documento gerado.
                </p>
            )}
        </div>
    );
}

function RuleSelect({
    label,
    value,
    options,
    onChange,
    disabled,
    error,
    className,
}: {
    label: string;
    value: string;
    options: { value: string; label: string }[];
    onChange: (value: string) => void;
    disabled?: boolean;
    error?: string;
    className?: string;
}) {
    return (
        <div className={`grid gap-1.5 ${className ?? ''}`}>
            <span className="text-[13px] font-medium">{label}</span>
            <Select value={value} disabled={disabled} onValueChange={onChange}>
                <SelectTrigger size="sm" className="w-full" aria-label={label}>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <InputError message={error} />
        </div>
    );
}

function NumberField({
    label,
    value,
    onChange,
    disabled,
    error,
    placeholder,
}: {
    label: string;
    value: number | null;
    onChange: (value: number | null) => void;
    disabled?: boolean;
    error?: string;
    placeholder?: string;
}) {
    return (
        <div className="grid gap-1.5">
            <span className="text-[13px] font-medium">{label}</span>
            <Input
                inputMode="decimal"
                value={value === null ? '' : String(value)}
                placeholder={placeholder}
                disabled={disabled}
                aria-label={label}
                onChange={(event) => onChange(numberOrNull(event.target.value))}
            />
            <InputError message={error} />
        </div>
    );
}
