import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, TriangleAlert } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { FormStatusBadge } from '@/components/public-forms/status-badge';
import type {
    DestinationOption,
    FixedParticipant,
    FormIssue,
    FormRole,
    FormVariable,
    PublicFormDestination,
    PublicFormDetail,
    SubmissionPeriod,
    SubmissionRow,
} from '@/components/public-forms/types';
import { VariableInput } from '@/components/templates/variable-input';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    activate,
    index as publicFormsIndex,
    pause,
    revoke,
    update,
} from '@/routes/public_forms';
import { CopyLinkButton, SubmissionItem } from './index';

interface PublicFormEditProps {
    form: PublicFormDetail;
    template: {
        id: string;
        name: string;
        source_type: string;
        source_label: string;
        usable: boolean;
        current_version: number | null;
        form_version: number;
        outdated: boolean;
    };
    variables: FormVariable[];
    roles: FormRole[];
    issues: FormIssue[];
    options: {
        destinations: DestinationOption[];
        periods: { value: SubmissionPeriod; label: string }[];
    };
    submissions: SubmissionRow[];
    can: { update: boolean; approve: boolean };
}

type EditData = {
    title: string;
    instructions: string;
    envelope_title: string;
    destination: PublicFormDestination;
    submissions_limit: number;
    submissions_period: SubmissionPeriod;
    expires_at: string;
    public_variables: string[];
    fixed_values: Record<string, string>;
    filler_role: string;
    fixed_participants: Record<string, FixedParticipant>;
};

/**
 * Configuração de um formulário público: o que o público preenche, qual papel
 * ocupa, valores e participantes fixos, destino, limites e prazo. Salvar usa a
 * versão atual do modelo; publicar exige zero pendências.
 */
export default function PublicFormEdit({
    form: detail,
    template,
    variables,
    roles,
    issues,
    options,
    submissions,
    can,
}: PublicFormEditProps) {
    const signerRoles = roles.filter(
        (role) => role.participant_role === 'signer',
    );

    const form = useForm<EditData>({
        title: detail.title,
        instructions: detail.instructions ?? '',
        envelope_title: detail.envelope_title ?? '',
        destination: detail.destination,
        submissions_limit: detail.submissions_limit,
        submissions_period: detail.submissions_period,
        expires_at: detail.expires_at ?? '',
        public_variables: detail.public_variables.filter((key) =>
            variables.some((variable) => variable.key === key),
        ),
        fixed_values: { ...detail.fixed_values },
        filler_role:
            detail.filler_role &&
            roles.some((role) => role.id === detail.filler_role)
                ? detail.filler_role
                : (signerRoles[0]?.id ?? ''),
        fixed_participants: Object.fromEntries(
            roles.map((role) => [
                role.id,
                detail.fixed_participants[role.id] ?? { name: '', email: '' },
            ]),
        ),
    });

    const readOnly = !can.update;
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            fixed_values: Object.fromEntries(
                Object.entries(data.fixed_values).filter(
                    ([key]) => !data.public_variables.includes(key),
                ),
            ),
            fixed_participants: Object.fromEntries(
                Object.entries(data.fixed_participants).filter(
                    ([id]) => id !== data.filler_role,
                ),
            ),
        }));
        form.put(update.url(detail.id), { preserveScroll: true });
    };

    const action = (url: string) =>
        router.post(url, {}, { preserveScroll: true });

    const togglePublic = (key: string, isPublic: boolean) =>
        form.setData(
            'public_variables',
            isPublic
                ? [...form.data.public_variables, key]
                : form.data.public_variables.filter((item) => item !== key),
        );

    return (
        <>
            <Head title={`Formulário · ${detail.title}`} />
            <PageHeader
                size="detail"
                title={detail.title}
                badge={
                    <FormStatusBadge
                        status={detail.status}
                        label={detail.status_label}
                    />
                }
                subtitle={`Modelo: ${template.name} (${template.source_label}, versão ${template.form_version})${detail.responsible ? ` · documentos criados em nome de ${detail.responsible}` : ''}`}
                leading={
                    <Button asChild variant="ghost" size="icon">
                        <Link
                            href={publicFormsIndex()}
                            aria-label="Voltar para Formulários públicos"
                        >
                            <ArrowLeft className="size-4" />
                        </Link>
                    </Button>
                }
                actions={
                    can.update && (
                        <div className="flex flex-wrap gap-2">
                            {detail.public_url && detail.status !== 'draft' && (
                                <CopyLinkButton url={detail.public_url} />
                            )}
                            {(detail.status === 'draft' ||
                                detail.status === 'paused') && (
                                <Button
                                    onClick={() =>
                                        action(activate.url(detail.id))
                                    }
                                    disabled={issues.length > 0}
                                >
                                    {detail.status === 'draft'
                                        ? 'Publicar'
                                        : 'Retomar'}
                                </Button>
                            )}
                            {detail.status === 'active' && (
                                <Button
                                    variant="outline"
                                    onClick={() => action(pause.url(detail.id))}
                                >
                                    Pausar
                                </Button>
                            )}
                            <RevokeButton
                                onConfirm={() => action(revoke.url(detail.id))}
                            />
                        </div>
                    )
                }
            />

            {errors.form && (
                <Alert variant="destructive">
                    <TriangleAlert className="size-4" />
                    <AlertDescription>{errors.form}</AlertDescription>
                </Alert>
            )}

            {detail.status !== 'revoked' && issues.length > 0 && (
                <Alert>
                    <TriangleAlert className="size-4" />
                    <AlertTitle>
                        {detail.status === 'active'
                            ? 'O link está indisponível para o público até resolver:'
                            : 'Antes de publicar, resolva:'}
                    </AlertTitle>
                    <AlertDescription>
                        <ul className="list-disc pl-4">
                            {issues.map((issue) => (
                                <li key={issue.code}>{issue.message}</li>
                            ))}
                        </ul>
                    </AlertDescription>
                </Alert>
            )}

            {detail.status === 'revoked' && (
                <Alert>
                    <AlertTitle>Formulário revogado</AlertTitle>
                    <AlertDescription>
                        O link deixou de funcionar e não pode ser reativado.
                        Crie um formulário novo se precisar.
                    </AlertDescription>
                </Alert>
            )}

            {detail.public_url && detail.status !== 'revoked' && (
                <div className="border-border bg-card rounded-xl border p-4">
                    <Label htmlFor="public-url">Link público</Label>
                    <Input
                        id="public-url"
                        readOnly
                        value={detail.public_url}
                        className="mt-2 font-mono text-[12.5px]"
                        onFocus={(event) => event.currentTarget.select()}
                    />
                    <p className="text-muted-foreground mt-2 text-[12px]">
                        {detail.status === 'draft'
                            ? 'O link só funciona depois de publicar.'
                            : `${detail.pending_confirmation_count} envio(s) aguardando confirmação do e-mail.`}
                    </p>
                </div>
            )}

            <form onSubmit={submit} className="flex flex-col gap-5">
                <Section
                    title="Página pública"
                    description="O que a pessoa vê ao abrir o link."
                >
                    <Field label="Título" id="title" error={errors.title}>
                        <Input
                            id="title"
                            value={form.data.title}
                            maxLength={160}
                            disabled={readOnly}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                        />
                    </Field>
                    <Field
                        label="Instruções (opcional)"
                        id="instructions"
                        error={errors.instructions}
                    >
                        <Textarea
                            id="instructions"
                            rows={3}
                            value={form.data.instructions}
                            maxLength={2000}
                            disabled={readOnly}
                            onChange={(e) =>
                                form.setData('instructions', e.target.value)
                            }
                        />
                    </Field>
                    <Field
                        label="Título do documento gerado (opcional)"
                        id="envelope_title"
                        error={errors.envelope_title}
                        help="O nome de quem preencheu é acrescentado ao final."
                    >
                        <Input
                            id="envelope_title"
                            value={form.data.envelope_title}
                            maxLength={120}
                            disabled={readOnly}
                            placeholder={detail.title}
                            onChange={(e) =>
                                form.setData('envelope_title', e.target.value)
                            }
                        />
                    </Field>
                </Section>

                <Section
                    title="Participantes"
                    description="Quem preenche o formulário ocupa um papel de signatário. Os demais papéis são pessoas fixas, definidas aqui."
                >
                    <Field
                        label="Papel de quem preenche"
                        id="filler_role"
                        error={errors.filler_role}
                    >
                        <Select
                            value={form.data.filler_role || undefined}
                            onValueChange={(value) =>
                                form.setData('filler_role', value)
                            }
                            disabled={readOnly}
                        >
                            <SelectTrigger id="filler_role" className="w-full">
                                <SelectValue placeholder="Escolha o papel" />
                            </SelectTrigger>
                            <SelectContent>
                                {signerRoles.map((role) => (
                                    <SelectItem key={role.id} value={role.id}>
                                        {role.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    {roles
                        .filter((role) => role.id !== form.data.filler_role)
                        .map((role) => (
                            <div
                                key={role.id}
                                className="grid gap-3 md:grid-cols-2"
                            >
                                <Field
                                    label={`${role.name} — nome (${role.participant_role_label.toLowerCase()})`}
                                    id={`fp-${role.id}-name`}
                                    error={
                                        errors[
                                            `fixed_participants.${role.id}.name`
                                        ]
                                    }
                                >
                                    <Input
                                        id={`fp-${role.id}-name`}
                                        value={
                                            form.data.fixed_participants[
                                                role.id
                                            ]?.name ?? ''
                                        }
                                        disabled={readOnly}
                                        onChange={(e) =>
                                            form.setData('fixed_participants', {
                                                ...form.data.fixed_participants,
                                                [role.id]: {
                                                    ...form.data
                                                        .fixed_participants[
                                                        role.id
                                                    ],
                                                    name: e.target.value,
                                                },
                                            })
                                        }
                                    />
                                </Field>
                                <Field
                                    label={`${role.name} — e-mail`}
                                    id={`fp-${role.id}-email`}
                                    error={
                                        errors[
                                            `fixed_participants.${role.id}.email`
                                        ]
                                    }
                                >
                                    <Input
                                        id={`fp-${role.id}-email`}
                                        type="email"
                                        value={
                                            form.data.fixed_participants[
                                                role.id
                                            ]?.email ?? ''
                                        }
                                        disabled={readOnly}
                                        onChange={(e) =>
                                            form.setData('fixed_participants', {
                                                ...form.data.fixed_participants,
                                                [role.id]: {
                                                    ...form.data
                                                        .fixed_participants[
                                                        role.id
                                                    ],
                                                    email: e.target.value,
                                                },
                                            })
                                        }
                                    />
                                </Field>
                            </div>
                        ))}
                </Section>

                <Section
                    title="Variáveis do modelo"
                    description="Marque o que o público preenche. As demais recebem um valor fixo (ou o padrão do modelo). Tudo passa pela mesma validação do modelo."
                >
                    {errors.public_variables && (
                        <InputError message={errors.public_variables} />
                    )}
                    {variables.length === 0 && (
                        <p className="text-muted-foreground text-[13px]">
                            Este modelo não tem variáveis: o público informa só
                            nome e e-mail.
                        </p>
                    )}
                    {variables.map((variable) => {
                        const isPublic = form.data.public_variables.includes(
                            variable.key,
                        );

                        return (
                            <div
                                key={variable.key}
                                className="border-border flex flex-col gap-2 rounded-[10px] border p-3"
                            >
                                <div className="flex items-center justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="text-[13.5px] font-semibold">
                                            {variable.label}
                                            {variable.required && (
                                                <span className="text-danger">
                                                    {' '}
                                                    *
                                                </span>
                                            )}
                                        </p>
                                        <p className="text-muted-foreground font-mono text-[11.5px]">
                                            {variable.key}
                                        </p>
                                    </div>
                                    <label className="flex items-center gap-2 text-[12.5px]">
                                        <Switch
                                            checked={isPublic}
                                            disabled={readOnly}
                                            onCheckedChange={(checked) =>
                                                togglePublic(
                                                    variable.key,
                                                    checked,
                                                )
                                            }
                                        />
                                        Público preenche
                                    </label>
                                </div>
                                {!isPublic && (
                                    <>
                                        <VariableInput
                                            id={`fixed-${variable.key}`}
                                            type={variable.type}
                                            options={variable.options}
                                            value={
                                                form.data.fixed_values[
                                                    variable.key
                                                ] ?? ''
                                            }
                                            invalid={Boolean(
                                                errors[
                                                    `fixed_values.${variable.key}`
                                                ],
                                            )}
                                            placeholder={
                                                variable.default_value
                                                    ? `Padrão do modelo: ${variable.default_value}`
                                                    : 'Valor fixo'
                                            }
                                            onChange={(value) =>
                                                form.setData('fixed_values', {
                                                    ...form.data.fixed_values,
                                                    [variable.key]: value,
                                                })
                                            }
                                        />
                                        <InputError
                                            message={
                                                errors[
                                                    `fixed_values.${variable.key}`
                                                ]
                                            }
                                        />
                                    </>
                                )}
                            </div>
                        );
                    })}
                </Section>

                <Section
                    title="Depois da confirmação do e-mail"
                    description="Nada é gerado antes de a pessoa confirmar o e-mail. A cota do plano só é usada no envio do documento."
                >
                    <RadioGroup
                        value={form.data.destination}
                        onValueChange={(value) =>
                            form.setData(
                                'destination',
                                value as PublicFormDestination,
                            )
                        }
                        disabled={readOnly}
                        className="gap-3"
                    >
                        {options.destinations.map((option) => (
                            <label
                                key={option.value}
                                className={`border-border flex items-start gap-3 rounded-[10px] border p-3 ${option.available ? '' : 'opacity-60'}`}
                            >
                                <RadioGroupItem
                                    value={option.value}
                                    disabled={!option.available}
                                    className="mt-0.5"
                                />
                                <span className="min-w-0">
                                    <span className="block text-[13.5px] font-semibold">
                                        {option.label}
                                    </span>
                                    <span className="text-text-secondary block text-[12.5px]">
                                        {option.available
                                            ? option.description
                                            : option.reason}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </RadioGroup>
                    <InputError message={errors.destination} />
                </Section>

                <Section
                    title="Limites"
                    description="Além do limite abaixo, a plataforma limita tentativas por conexão e por formulário, usa um campo invisível contra robôs e exige um tempo mínimo de preenchimento."
                >
                    <div className="grid gap-3 md:grid-cols-2">
                        <Field
                            label="Respostas confirmadas"
                            id="submissions_limit"
                            error={errors.submissions_limit}
                        >
                            <Input
                                id="submissions_limit"
                                type="number"
                                min={1}
                                max={10000}
                                value={form.data.submissions_limit}
                                disabled={readOnly}
                                onChange={(e) =>
                                    form.setData(
                                        'submissions_limit',
                                        Number(e.target.value),
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Período"
                            id="submissions_period"
                            error={errors.submissions_period}
                        >
                            <Select
                                value={form.data.submissions_period}
                                onValueChange={(value) =>
                                    form.setData(
                                        'submissions_period',
                                        value as SubmissionPeriod,
                                    )
                                }
                                disabled={readOnly}
                            >
                                <SelectTrigger
                                    id="submissions_period"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {options.periods.map((period) => (
                                        <SelectItem
                                            key={period.value}
                                            value={period.value}
                                        >
                                            {period.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                    </div>
                    <Field
                        label="Encerrar em (opcional)"
                        id="expires_at"
                        error={errors.expires_at}
                        help="O link deixa de aceitar respostas no fim do dia escolhido."
                    >
                        <Input
                            id="expires_at"
                            type="date"
                            value={form.data.expires_at}
                            disabled={readOnly}
                            onChange={(e) =>
                                form.setData('expires_at', e.target.value)
                            }
                            className="md:max-w-[220px]"
                        />
                    </Field>
                </Section>

                {can.update && (
                    <div className="flex justify-end">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Salvar formulário
                        </Button>
                    </div>
                )}
            </form>

            <section className="border-border bg-card overflow-hidden rounded-xl border">
                <div className="border-border border-b px-5 py-3">
                    <h2 className="text-[14px] font-semibold">
                        Respostas confirmadas
                    </h2>
                    <p className="text-text-secondary text-[12.5px]">
                        As 30 mais recentes. Envios não confirmados não aparecem
                        e são apagados quando o link vence.
                    </p>
                </div>
                {submissions.length === 0 ? (
                    <p className="text-muted-foreground px-5 py-8 text-center text-[13px]">
                        Nenhuma resposta confirmada ainda.
                    </p>
                ) : (
                    <ul className="divide-border divide-y">
                        {submissions.map((submission) => (
                            <SubmissionItem
                                key={submission.id}
                                submission={submission}
                                canApprove={can.approve}
                                showForm={false}
                            />
                        ))}
                    </ul>
                )}
            </section>
        </>
    );
}

PublicFormEdit.layout = (props: PublicFormEditProps) => ({
    breadcrumbs: [
        { title: 'Formulários públicos', href: publicFormsIndex() },
        { title: props.form.title, href: '#' },
    ],
});

function Section({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <section className="border-border bg-card flex flex-col gap-4 rounded-xl border p-5">
            <div>
                <h2 className="text-[15px] font-semibold">{title}</h2>
                {description && (
                    <p className="text-text-secondary mt-1 text-[12.5px] leading-[1.5]">
                        {description}
                    </p>
                )}
            </div>
            {children}
        </section>
    );
}

function Field({
    label,
    id,
    error,
    help,
    children,
}: {
    label: string;
    id: string;
    error?: string;
    help?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {help && (
                <p className="text-muted-foreground text-[12px]">{help}</p>
            )}
            <InputError message={error} />
        </div>
    );
}

function RevokeButton({ onConfirm }: { onConfirm: () => void }) {
    return (
        <AlertDialog>
            <AlertDialogTrigger asChild>
                <Button variant="outline">Revogar</Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Revogar o formulário?</AlertDialogTitle>
                    <AlertDialogDescription>
                        O link deixa de funcionar para sempre e os envios que
                        aguardam confirmação são apagados. Documentos já gerados
                        não mudam.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Voltar</AlertDialogCancel>
                    <AlertDialogAction onClick={onConfirm}>
                        Revogar
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
