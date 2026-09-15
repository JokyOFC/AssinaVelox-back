import { GitBranch, Plus, Trash2, UserRoundCog } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { requestJson } from '@/components/identity/http';
import { Badge } from '@/components/ui/badge';
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
import { Switch } from '@/components/ui/switch';
import { update as delegationUpdate } from '@/routes/envelopes/delegation';
import { update as stepsUpdate } from '@/routes/envelopes/steps';
import {
    decisionLabels,
    operatorLabels,
    type EnvelopeFlowState,
    type FlowCondition,
    type FlowDecision,
    type FlowOperator,
    type FlowRule,
} from './types';
import { useEnvelopeFlow } from './use-envelope-flow';

interface DraftStep {
    name: string;
    condition: FlowCondition | null;
}

type Errors = Record<string, string>;

function firstErrors(body: unknown): Errors {
    const errors =
        body && typeof body === 'object' && 'errors' in body
            ? (body as { errors: Record<string, string[] | string> }).errors
            : {};

    return Object.fromEntries(
        Object.entries(errors).map(([key, value]) => [
            key,
            Array.isArray(value) ? (value[0] ?? '') : String(value),
        ]),
    );
}

function draftFrom(state: EnvelopeFlowState): {
    steps: DraftStep[];
    assignment: Record<string, number>;
} {
    const items = state.steps?.items ?? [];
    const participants = state.participants;

    if (items.length === 0) {
        return {
            steps: [{ name: '', condition: null }],
            assignment: Object.fromEntries(participants.map((p) => [p.id, 1])),
        };
    }

    return {
        steps: items.map((item) => ({
            name: item.name ?? '',
            condition: item.condition,
        })),
        assignment: Object.fromEntries(
            participants.map((p) => [p.id, p.step ?? items.length]),
        ),
    };
}

/**
 * Etapas condicionais e regras de delegação no passo "Revisão" do wizard (Fase 3 §3.3 —
 * docs/fase-3/etapas-e-delegacao.md §5). Descobre o recurso por `GET envelopes.flow.show`:
 * 404 (flags desligadas) = não aparece nada e o wizard é o de antes.
 *
 * As condições são montadas só com o que o motor aceita: decisão de um aprovador de etapa
 * anterior ou valor de um campo (caixa de seleção ou texto) de etapa anterior comparado com
 * um texto literal. O servidor revalida tudo — inclusive no envio.
 */
export function SigningFlowEditor({ envelopeId }: { envelopeId: string }) {
    const { state, setState, status } = useEnvelopeFlow(
        envelopeId,
        'envelopes/wizard',
    );

    if (status !== 'ready' || state === null) {
        return null;
    }

    const showSteps = state.steps !== null && state.features.conditional_steps;
    const showDelegation =
        state.delegation !== null && state.features.delegation;

    if (!showSteps && !showDelegation) {
        return null;
    }

    return (
        <div className="flex flex-col gap-4">
            {showSteps && <StepsCard state={state} onSaved={setState} />}
            {showDelegation && (
                <DelegationPolicyCard state={state} onSaved={setState} />
            )}
        </div>
    );
}

function StepsCard({
    state,
    onSaved,
}: {
    state: EnvelopeFlowState;
    onSaved: (state: EnvelopeFlowState) => void;
}) {
    const steps = state.steps!;
    const participants = state.participants;
    const [enabled, setEnabled] = useState(steps.enabled);
    const [draft, setDraft] = useState(() => draftFrom(state));
    const [errors, setErrors] = useState<Errors>({});
    const [saving, setSaving] = useState(false);
    const editable = steps.can_edit;

    useEffect(() => {
        setEnabled(steps.enabled);
        setDraft(draftFrom(state));
    }, [state, steps.enabled]);

    const stepOf = (participantId: string): number =>
        draft.assignment[participantId] ?? 1;

    const approversBefore = (index: number) =>
        participants.filter(
            (p) => p.role === 'approver' && stepOf(p.id) < index,
        );

    const fieldsBefore = (index: number) =>
        steps.fields.filter((field) => {
            const owner = participants.find((p) => p.id === field.recipient_id);

            return owner !== undefined && stepOf(owner.id) < index;
        });

    const setStep = (position: number, patch: Partial<DraftStep>) =>
        setDraft((current) => ({
            ...current,
            steps: current.steps.map((step, i) =>
                i === position ? { ...step, ...patch } : step,
            ),
        }));

    const addStep = () =>
        setDraft((current) => ({
            ...current,
            steps: [...current.steps, { name: '', condition: null }],
        }));

    const removeStep = (position: number) =>
        setDraft((current) => {
            const removed = position + 1;

            return {
                steps: current.steps.filter((_, i) => i !== position),
                // Quem estava na etapa removida vai para a anterior; as seguintes sobem uma.
                assignment: Object.fromEntries(
                    Object.entries(current.assignment).map(([id, step]) => [
                        id,
                        step === removed
                            ? Math.max(1, removed - 1)
                            : step > removed
                              ? step - 1
                              : step,
                    ]),
                ),
            };
        });

    const save = async (nextEnabled: boolean) => {
        setSaving(true);
        setErrors({});

        const payload = nextEnabled
            ? {
                  enabled: true,
                  steps: draft.steps.map((step, i) => ({
                      name: step.name.trim() === '' ? null : step.name.trim(),
                      recipients: participants
                          .filter((p) => stepOf(p.id) === i + 1)
                          .map((p) => p.id),
                      condition: i === 0 ? null : step.condition,
                  })),
              }
            : { enabled: false };

        const response = await requestJson<EnvelopeFlowState>(
            'PUT',
            stepsUpdate(state.envelope.id).url,
            payload,
        );

        setSaving(false);

        if (response.ok && response.body) {
            onSaved(response.body);
            toast.success(
                nextEnabled
                    ? 'Etapas salvas. A vez de cada participante segue a etapa dele.'
                    : 'Fluxo por etapas desligado. A ordem de assinatura voltou a ser a do passo 1.',
            );

            return;
        }

        setErrors(firstErrors(response.body));
        toast.error(
            response.network
                ? 'Não foi possível salvar agora. Confira a conexão e tente de novo.'
                : 'Revise as etapas: há algo que o fluxo não aceita.',
        );
    };

    const generalError = errors.steps ?? errors.enabled;

    return (
        <section className="border-border bg-card shadow-card flex flex-col gap-4 rounded-xl border p-5">
            <header className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="flex items-center gap-2 text-[15px] font-semibold">
                        <GitBranch className="text-primary size-4" />
                        Fluxo por etapas
                    </h3>
                    <p className="text-muted-foreground text-[12.5px]">
                        Agrupe os participantes em etapas. Da segunda em diante,
                        a etapa pode depender da decisão de um aprovador ou do
                        valor de um campo preenchido antes. Etapa cuja condição
                        não se cumpre é pulada, e os participantes dela não
                        recebem nada.
                    </p>
                </div>
                <Switch
                    checked={enabled}
                    disabled={!editable || saving}
                    aria-label="Conduzir por etapas"
                    onCheckedChange={(checked) => {
                        const next = checked === true;

                        setEnabled(next);

                        if (!next && steps.enabled) {
                            void save(false);
                        }
                    }}
                />
            </header>

            {enabled && (
                <>
                    <div className="grid gap-2">
                        <p className="text-[12.5px] font-semibold">
                            Etapa de cada participante
                        </p>
                        {participants.map((participant) => (
                            <div
                                key={participant.id}
                                className="border-muted flex flex-wrap items-center justify-between gap-2 border-b pb-2 last:border-b-0"
                            >
                                <span className="min-w-0 text-[13px]">
                                    <span className="font-semibold">
                                        {participant.name}
                                    </span>{' '}
                                    <span className="text-muted-foreground">
                                        · {participant.role_label}
                                    </span>
                                </span>
                                <Select
                                    value={String(stepOf(participant.id))}
                                    disabled={!editable}
                                    onValueChange={(value) =>
                                        setDraft((current) => ({
                                            ...current,
                                            assignment: {
                                                ...current.assignment,
                                                [participant.id]: Number(value),
                                            },
                                        }))
                                    }
                                >
                                    <SelectTrigger
                                        size="sm"
                                        className="w-[130px]"
                                        aria-label={`Etapa de ${participant.name}`}
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {draft.steps.map((_, i) => (
                                            <SelectItem
                                                key={i}
                                                value={String(i + 1)}
                                            >
                                                Etapa {i + 1}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        ))}
                    </div>

                    {draft.steps.map((step, position) => (
                        <StepBlock
                            key={position}
                            position={position}
                            step={step}
                            members={participants
                                .filter((p) => stepOf(p.id) === position + 1)
                                .map((p) => p.name)}
                            approvers={approversBefore(position + 1)}
                            fields={fieldsBefore(position + 1)}
                            maxRules={steps.limits.max_rules}
                            maxLiteral={steps.limits.max_literal_length}
                            editable={editable}
                            errors={errors}
                            onChange={(patch) => setStep(position, patch)}
                            onRemove={
                                draft.steps.length > 1
                                    ? () => removeStep(position)
                                    : undefined
                            }
                        />
                    ))}

                    {generalError && (
                        <p role="alert" className="text-danger text-[12.5px]">
                            {generalError}
                        </p>
                    )}

                    {editable && (
                        <div className="flex flex-wrap justify-between gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={
                                    draft.steps.length >= steps.limits.max_steps
                                }
                                onClick={addStep}
                            >
                                <Plus className="size-3.5" />
                                Adicionar etapa
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                disabled={saving}
                                onClick={() => void save(true)}
                            >
                                {saving && <Spinner className="size-3.5" />}
                                Salvar etapas
                            </Button>
                        </div>
                    )}
                </>
            )}
        </section>
    );
}

function StepBlock({
    position,
    step,
    members,
    approvers,
    fields,
    maxRules,
    maxLiteral,
    editable,
    errors,
    onChange,
    onRemove,
}: {
    position: number;
    step: DraftStep;
    members: string[];
    approvers: EnvelopeFlowState['participants'];
    fields: NonNullable<EnvelopeFlowState['steps']>['fields'];
    maxRules: number;
    maxLiteral: number;
    editable: boolean;
    errors: Errors;
    onChange: (patch: Partial<DraftStep>) => void;
    onRemove?: () => void;
}) {
    const index = position + 1;
    const condition = step.condition;
    const prefix = `steps.${position}`;
    const stepErrors = Object.entries(errors)
        .filter(([key]) => key === prefix || key.startsWith(`${prefix}.`))
        .map(([, message]) => message);

    const setRules = (rules: FlowRule[]) =>
        onChange({
            condition:
                rules.length === 0
                    ? null
                    : { match: condition?.match ?? 'all', rules },
        });

    const newRule = (): FlowRule | null =>
        approvers[0]
            ? {
                  type: 'approver_decision',
                  recipient: approvers[0].id,
                  equals: 'approved',
              }
            : fields[0]
              ? {
                    type: 'field_value',
                    field: fields[0].id,
                    operator: 'equals',
                    value: fields[0].type === 'checkbox' ? 'checked' : '',
                }
              : null;

    const canAddRule =
        position > 0 &&
        editable &&
        (approvers.length > 0 || fields.length > 0) &&
        (condition?.rules.length ?? 0) < maxRules;

    return (
        <div className="border-border flex flex-col gap-3 rounded-[10px] border p-3.5">
            <div className="flex flex-wrap items-center gap-2">
                <Badge variant="phase">Etapa {index}</Badge>
                <Input
                    value={step.name}
                    disabled={!editable}
                    maxLength={80}
                    placeholder={
                        index === 1 ? 'Ex.: Aprovação' : 'Ex.: Assinaturas'
                    }
                    aria-label={`Nome da etapa ${index}`}
                    className="h-8 max-w-[260px] text-[13px]"
                    onChange={(event) => onChange({ name: event.target.value })}
                />
                {onRemove && editable && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={onRemove}
                        aria-label={`Remover etapa ${index}`}
                    >
                        <Trash2 className="size-3.5" />
                    </Button>
                )}
            </div>

            <p className="text-muted-foreground text-[12.5px]">
                {members.length > 0
                    ? members.join(', ')
                    : 'Nenhum participante nesta etapa ainda.'}
            </p>

            {position === 0 ? (
                <p className="text-muted-foreground text-[12px]">
                    A primeira etapa começa sempre, no envio.
                </p>
            ) : (
                <div className="flex flex-col gap-2">
                    {condition && condition.rules.length > 1 && (
                        <Select
                            value={condition.match}
                            disabled={!editable}
                            onValueChange={(value) =>
                                onChange({
                                    condition: {
                                        ...condition,
                                        match: value === 'any' ? 'any' : 'all',
                                    },
                                })
                            }
                        >
                            <SelectTrigger
                                size="sm"
                                className="w-[240px]"
                                aria-label="Como combinar as regras"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    Vale se todas as regras se cumprirem
                                </SelectItem>
                                <SelectItem value="any">
                                    Vale se qualquer regra se cumprir
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    )}

                    {(condition?.rules ?? []).map((rule, ruleIndex) => (
                        <RuleRow
                            key={ruleIndex}
                            rule={rule}
                            approvers={approvers}
                            fields={fields}
                            maxLiteral={maxLiteral}
                            editable={editable}
                            onChange={(next) =>
                                setRules(
                                    (condition?.rules ?? []).map((r, i) =>
                                        i === ruleIndex ? next : r,
                                    ),
                                )
                            }
                            onRemove={() =>
                                setRules(
                                    (condition?.rules ?? []).filter(
                                        (_, i) => i !== ruleIndex,
                                    ),
                                )
                            }
                        />
                    ))}

                    {!condition && (
                        <p className="text-muted-foreground text-[12px]">
                            Sem condição: a etapa sempre se aplica.
                        </p>
                    )}

                    {canAddRule && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="self-start"
                            onClick={() => {
                                const rule = newRule();

                                if (rule) {
                                    setRules([
                                        ...(condition?.rules ?? []),
                                        rule,
                                    ]);
                                }
                            }}
                        >
                            <Plus className="size-3.5" />
                            Adicionar condição
                        </Button>
                    )}

                    {position > 0 &&
                        approvers.length === 0 &&
                        fields.length === 0 && (
                            <p className="text-muted-foreground text-[12px]">
                                Para usar condição, coloque um aprovador ou um
                                campo de caixa de seleção ou texto numa etapa
                                anterior.
                            </p>
                        )}
                </div>
            )}

            {stepErrors.length > 0 && (
                <p role="alert" className="text-danger text-[12.5px]">
                    {stepErrors[0]}
                </p>
            )}
        </div>
    );
}

function RuleRow({
    rule,
    approvers,
    fields,
    maxLiteral,
    editable,
    onChange,
    onRemove,
}: {
    rule: FlowRule;
    approvers: EnvelopeFlowState['participants'];
    fields: NonNullable<EnvelopeFlowState['steps']>['fields'];
    maxLiteral: number;
    editable: boolean;
    onChange: (rule: FlowRule) => void;
    onRemove: () => void;
}) {
    const field =
        rule.type === 'field_value'
            ? fields.find((f) => f.id === rule.field)
            : undefined;
    const checkbox = field?.type === 'checkbox';

    return (
        <div className="bg-muted/40 flex flex-wrap items-center gap-2 rounded-lg p-2">
            <Select
                value={rule.type}
                disabled={!editable}
                onValueChange={(value) => {
                    if (value === 'approver_decision' && approvers[0]) {
                        onChange({
                            type: 'approver_decision',
                            recipient: approvers[0].id,
                            equals: 'approved',
                        });
                    }

                    if (value === 'field_value' && fields[0]) {
                        onChange({
                            type: 'field_value',
                            field: fields[0].id,
                            operator: 'equals',
                            value:
                                fields[0].type === 'checkbox' ? 'checked' : '',
                        });
                    }
                }}
            >
                <SelectTrigger
                    size="sm"
                    className="w-[170px]"
                    aria-label="Tipo de condição"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        value="approver_decision"
                        disabled={approvers.length === 0}
                    >
                        Decisão de aprovador
                    </SelectItem>
                    <SelectItem
                        value="field_value"
                        disabled={fields.length === 0}
                    >
                        Valor de campo
                    </SelectItem>
                </SelectContent>
            </Select>

            {rule.type === 'approver_decision' ? (
                <>
                    <Select
                        value={rule.recipient}
                        disabled={!editable}
                        onValueChange={(recipient) =>
                            onChange({ ...rule, recipient })
                        }
                    >
                        <SelectTrigger
                            size="sm"
                            className="w-[180px]"
                            aria-label="Aprovador"
                        >
                            <SelectValue placeholder="Aprovador" />
                        </SelectTrigger>
                        <SelectContent>
                            {approvers.map((approver) => (
                                <SelectItem
                                    key={approver.id}
                                    value={approver.id}
                                >
                                    {approver.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={rule.equals}
                        disabled={!editable}
                        onValueChange={(value) =>
                            onChange({
                                ...rule,
                                equals: value as FlowDecision,
                            })
                        }
                    >
                        <SelectTrigger
                            size="sm"
                            className="w-[120px]"
                            aria-label="Decisão"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {(['approved', 'refused'] as FlowDecision[]).map(
                                (decision) => (
                                    <SelectItem key={decision} value={decision}>
                                        {decisionLabels[decision]}
                                    </SelectItem>
                                ),
                            )}
                        </SelectContent>
                    </Select>
                </>
            ) : (
                <>
                    <Select
                        value={rule.field}
                        disabled={!editable}
                        onValueChange={(fieldId) => {
                            const next = fields.find((f) => f.id === fieldId);

                            onChange({
                                ...rule,
                                field: fieldId,
                                operator:
                                    next?.type === 'checkbox' &&
                                    rule.operator === 'contains'
                                        ? 'equals'
                                        : rule.operator,
                                value:
                                    next?.type === 'checkbox' ? 'checked' : '',
                            });
                        }}
                    >
                        <SelectTrigger
                            size="sm"
                            className="w-[180px]"
                            aria-label="Campo"
                        >
                            <SelectValue placeholder="Campo" />
                        </SelectTrigger>
                        <SelectContent>
                            {fields.map((f) => (
                                <SelectItem key={f.id} value={f.id}>
                                    {f.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={rule.operator}
                        disabled={!editable}
                        onValueChange={(value) =>
                            onChange({
                                ...rule,
                                operator: value as FlowOperator,
                            })
                        }
                    >
                        <SelectTrigger
                            size="sm"
                            className="w-[140px]"
                            aria-label="Comparação"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {(
                                (checkbox
                                    ? ['equals', 'not_equals']
                                    : [
                                          'equals',
                                          'not_equals',
                                          'contains',
                                      ]) as FlowOperator[]
                            ).map((operator) => (
                                <SelectItem key={operator} value={operator}>
                                    {operatorLabels[operator]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {checkbox ? (
                        <Select
                            value={rule.value}
                            disabled={!editable}
                            onValueChange={(value) =>
                                onChange({ ...rule, value })
                            }
                        >
                            <SelectTrigger
                                size="sm"
                                className="w-[130px]"
                                aria-label="Valor"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="checked">marcada</SelectItem>
                                <SelectItem value="unchecked">
                                    desmarcada
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    ) : (
                        <Input
                            value={rule.value}
                            disabled={!editable}
                            maxLength={maxLiteral}
                            placeholder="Texto a comparar"
                            aria-label="Texto a comparar"
                            className="h-8 w-[180px] text-[13px]"
                            onChange={(event) =>
                                onChange({ ...rule, value: event.target.value })
                            }
                        />
                    )}
                </>
            )}

            {editable && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={onRemove}
                    aria-label="Remover condição"
                >
                    <Trash2 className="size-3.5" />
                </Button>
            )}
        </div>
    );
}

function DelegationPolicyCard({
    state,
    onSaved,
}: {
    state: EnvelopeFlowState;
    onSaved: (state: EnvelopeFlowState) => void;
}) {
    const delegation = state.delegation!;
    const [policy, setPolicy] = useState(delegation.policy);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const editable = delegation.can_edit;

    useEffect(() => {
        setPolicy(delegation.policy);
    }, [delegation.policy]);

    const personal = useMemo(() => new Set(policy.personal), [policy.personal]);

    const save = async () => {
        setSaving(true);
        setError(null);

        const response = await requestJson<EnvelopeFlowState>(
            'PUT',
            delegationUpdate(state.envelope.id).url,
            {
                allow: policy.allow,
                requires_confirmation: policy.requires_confirmation,
                personal: policy.personal,
            },
        );

        setSaving(false);

        if (response.ok && response.body) {
            onSaved(response.body);
            toast.success('Regras de delegação salvas.');

            return;
        }

        const errors = firstErrors(response.body);
        setError(
            Object.values(errors)[0] ??
                'Não foi possível salvar agora. Tente de novo.',
        );
    };

    return (
        <section className="border-border bg-card shadow-card flex flex-col gap-3 rounded-xl border p-5">
            <header className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="flex items-center gap-2 text-[15px] font-semibold">
                        <UserRoundCog className="text-primary size-4" />
                        Delegação
                    </h3>
                    <p className="text-muted-foreground text-[12.5px]">
                        Permite que um participante indique outra pessoa para
                        participar no lugar dele. Quem recebe entra com convite
                        e código próprios e registra o próprio aceite — nunca em
                        nome de outra pessoa.
                    </p>
                </div>
                <Switch
                    checked={policy.allow}
                    disabled={!editable || saving}
                    aria-label="Permitir delegação"
                    onCheckedChange={(checked) =>
                        setPolicy({ ...policy, allow: checked === true })
                    }
                />
            </header>

            {policy.allow && (
                <>
                    <label className="flex items-start gap-2 text-[13px]">
                        <Checkbox
                            checked={policy.requires_confirmation}
                            disabled={!editable}
                            onCheckedChange={(checked) =>
                                setPolicy({
                                    ...policy,
                                    requires_confirmation: checked === true,
                                })
                            }
                        />
                        <span>
                            Exigir a minha confirmação antes de a delegação
                            valer
                            <span className="text-muted-foreground block text-[12px]">
                                Participante com código por SMS ou WhatsApp,
                                PIN, fotos ou vídeo curto exigidos sempre
                                depende da sua confirmação.
                            </span>
                        </span>
                    </label>

                    <div className="grid gap-1.5">
                        <Label className="text-[12.5px]">
                            Participação pessoal (não pode ser delegada)
                        </Label>
                        {state.participants.map((participant) => (
                            <label
                                key={participant.id}
                                className="flex items-center gap-2 text-[13px]"
                            >
                                <Checkbox
                                    checked={personal.has(participant.id)}
                                    disabled={!editable}
                                    onCheckedChange={(checked) =>
                                        setPolicy({
                                            ...policy,
                                            personal:
                                                checked === true
                                                    ? [
                                                          ...policy.personal,
                                                          participant.id,
                                                      ]
                                                    : policy.personal.filter(
                                                          (id) =>
                                                              id !==
                                                              participant.id,
                                                      ),
                                        })
                                    }
                                />
                                {participant.name}
                                <span className="text-muted-foreground">
                                    · {participant.role_label}
                                </span>
                            </label>
                        ))}
                    </div>
                </>
            )}

            {error && (
                <p role="alert" className="text-danger text-[12.5px]">
                    {error}
                </p>
            )}

            {editable && (
                <Button
                    type="button"
                    size="sm"
                    className="self-end"
                    disabled={saving}
                    onClick={() => void save()}
                >
                    {saving && <Spinner className="size-3.5" />}
                    Salvar regras de delegação
                </Button>
            )}
        </section>
    );
}
