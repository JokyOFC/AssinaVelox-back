import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, TriangleAlert } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import type {
    ConversionInfo,
    TemplateSummary,
    VariableOptions,
    VariableType,
} from '@/components/templates/types';
import { VariableInput } from '@/components/templates/variable-input';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { plural } from '@/lib/format';
import type { ParticipantRole } from '@/types/enums';
import {
    index as templatesIndex,
    use as useTemplate,
} from '@/routes/templates';

interface UseVariable {
    key: string;
    label: string;
    type: VariableType;
    required: boolean;
    help_text: string | null;
    default_value: string | null;
    options: VariableOptions;
}

interface UseRole {
    id: string;
    name: string;
    participant_role: ParticipantRole;
    participant_role_label: string;
}

interface TemplateUseProps {
    template: TemplateSummary & {
        version: number;
        version_id: string;
        fields_count: number;
        page_count: number | null;
    };
    variables: UseVariable[];
    roles: UseRole[];
    defaults: { title: string };
    conversion: ConversionInfo;
}

/**
 * "Usar modelo" (GET envelopes.create?template={ulid}). Pede os valores das
 * variáveis e quem ocupa cada papel; ao confirmar, o servidor valida tudo,
 * cria o documento em rascunho e abre o wizard no primeiro passo com pendência.
 */
export default function TemplateUse({
    template,
    variables,
    roles,
    defaults,
    conversion,
}: TemplateUseProps) {
    const form = useForm<{
        title: string;
        values: Record<string, string>;
        participants: Record<string, { name: string; email: string }>;
    }>({
        title: defaults.title,
        values: Object.fromEntries(
            variables.map((variable) => [
                variable.key,
                variable.default_value ??
                    (variable.type === 'boolean' ? '0' : ''),
            ]),
        ),
        participants: Object.fromEntries(
            roles.map((role) => [role.id, { name: '', email: '' }]),
        ),
    });

    const errors = form.errors as Record<string, string | undefined>;

    const setValue = (key: string, value: string) =>
        form.setData('values', { ...form.data.values, [key]: value });

    const setParticipant = (
        id: string,
        patch: Partial<{ name: string; email: string }>,
    ) =>
        form.setData('participants', {
            ...form.data.participants,
            [id]: { ...form.data.participants[id], ...patch },
        });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(useTemplate.url(template.id), { preserveScroll: true });
    };

    return (
        <>
            <Head title={`Usar modelo · ${template.name}`} />
            <PageHeader
                size="detail"
                leading={
                    <Button
                        asChild
                        variant="outline"
                        size="icon"
                        aria-label="Voltar para Modelos"
                    >
                        <Link href={templatesIndex.url()}>
                            <ArrowLeft />
                        </Link>
                    </Button>
                }
                eyebrow="Usar modelo"
                title={template.name}
                badge={<Badge variant="neutral">{template.source_label}</Badge>}
                subtitle="Preencha as informações e indique quem participa. O documento é gerado como rascunho para você revisar antes de enviar."
            />

            {conversion.required && !conversion.available && (
                <div className="border-warning-border bg-warning-bg text-warning flex gap-2 rounded-lg border p-3 text-[13px]">
                    <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                    <span>{conversion.message}</span>
                </div>
            )}

            {errors.template && (
                <div
                    className="border-danger-border bg-danger-bg text-danger rounded-lg border p-3 text-[13px]"
                    role="alert"
                >
                    {errors.template}
                </div>
            )}

            <form
                onSubmit={submit}
                className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_300px]"
            >
                <div className="flex flex-col gap-5">
                    <Card title="Documento">
                        <div className="grid gap-1.5">
                            <Label htmlFor="title">Título</Label>
                            <Input
                                id="title"
                                value={form.data.title}
                                maxLength={160}
                                onChange={(e) =>
                                    form.setData('title', e.target.value)
                                }
                            />
                            <InputError message={errors.title} />
                        </div>
                    </Card>

                    {variables.length > 0 && (
                        <Card
                            title="Informações do documento"
                            description="Os valores entram no documento como texto, com a formatação brasileira (datas, valores em reais, CPF e CNPJ)."
                        >
                            <div className="grid gap-4 md:grid-cols-2">
                                {variables.map((variable) => {
                                    const id = `value-${variable.key}`;
                                    const error =
                                        errors[`values.${variable.key}`];

                                    return (
                                        <div
                                            key={variable.key}
                                            className={
                                                variable.type === 'long_text'
                                                    ? 'grid gap-1.5 md:col-span-2'
                                                    : 'grid gap-1.5'
                                            }
                                        >
                                            <Label htmlFor={id}>
                                                {variable.label}
                                                {variable.required &&
                                                    variable.type !==
                                                        'boolean' && (
                                                        <span
                                                            className="text-danger ml-0.5"
                                                            aria-hidden
                                                        >
                                                            *
                                                        </span>
                                                    )}
                                            </Label>
                                            <VariableInput
                                                id={id}
                                                type={variable.type}
                                                options={variable.options ?? {}}
                                                value={
                                                    form.data.values[
                                                        variable.key
                                                    ] ?? ''
                                                }
                                                onChange={(value) =>
                                                    setValue(
                                                        variable.key,
                                                        value,
                                                    )
                                                }
                                                invalid={Boolean(error)}
                                            />
                                            {variable.help_text && (
                                                <p className="text-muted-foreground text-[12px]">
                                                    {variable.help_text}
                                                </p>
                                            )}
                                            <InputError message={error} />
                                        </div>
                                    );
                                })}
                            </div>
                        </Card>
                    )}

                    <Card
                        title="Participantes"
                        description="Cada papel do modelo vira um destinatário. O convite só sai quando você enviar o documento."
                    >
                        <div className="flex flex-col gap-4">
                            {roles.map((role, index) => (
                                <div
                                    key={role.id}
                                    className="border-border rounded-lg border p-3.5"
                                    data-testid="template-participant"
                                >
                                    <div className="mb-2.5 flex items-center gap-2">
                                        <span className="text-muted-foreground text-[12px] font-semibold">
                                            {index + 1}º
                                        </span>
                                        <span className="text-foreground text-[13.5px] font-semibold">
                                            {role.name}
                                        </span>
                                        <Badge
                                            variant={
                                                role.participant_role ===
                                                'signer'
                                                    ? 'info'
                                                    : 'neutral'
                                            }
                                        >
                                            {role.participant_role_label}
                                        </Badge>
                                    </div>
                                    <div className="grid gap-3 md:grid-cols-2">
                                        <div className="grid gap-1.5">
                                            <Label
                                                htmlFor={`participant-${role.id}-name`}
                                            >
                                                Nome completo
                                            </Label>
                                            <Input
                                                id={`participant-${role.id}-name`}
                                                value={
                                                    form.data.participants[
                                                        role.id
                                                    ]?.name ?? ''
                                                }
                                                maxLength={120}
                                                onChange={(e) =>
                                                    setParticipant(role.id, {
                                                        name: e.target.value,
                                                    })
                                                }
                                            />
                                            <InputError
                                                message={
                                                    errors[
                                                        `participants.${role.id}.name`
                                                    ]
                                                }
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label
                                                htmlFor={`participant-${role.id}-email`}
                                            >
                                                E-mail
                                            </Label>
                                            <Input
                                                id={`participant-${role.id}-email`}
                                                type="email"
                                                value={
                                                    form.data.participants[
                                                        role.id
                                                    ]?.email ?? ''
                                                }
                                                maxLength={255}
                                                onChange={(e) =>
                                                    setParticipant(role.id, {
                                                        email: e.target.value,
                                                    })
                                                }
                                            />
                                            <InputError
                                                message={
                                                    errors[
                                                        `participants.${role.id}.email`
                                                    ]
                                                }
                                            />
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Card>
                </div>

                <aside className="flex flex-col gap-4">
                    <Card title="Resumo">
                        <dl className="grid gap-2 text-[13px]">
                            <Row label="Modelo">{template.name}</Row>
                            <Row label="Versão">v{template.version}</Row>
                            <Row label="Tipo">{template.source_label}</Row>
                            <Row label="Participantes">{roles.length}</Row>
                            {template.supports_fields && (
                                <Row label="Campos prontos">
                                    {template.fields_count}
                                </Row>
                            )}
                            {template.page_count ? (
                                <Row label="Páginas">{template.page_count}</Row>
                            ) : null}
                        </dl>
                        <p className="text-muted-foreground mt-3 text-[12px]">
                            {template.supports_fields
                                ? 'Os campos já vêm posicionados. Você revisa tudo no último passo antes de enviar.'
                                : `Depois de gerar, você posiciona os campos de ${plural(roles.length, 'participante')} no passo 3.`}{' '}
                            Alterações futuras no modelo não mudam este
                            documento.
                        </p>
                    </Card>
                    <Button
                        type="submit"
                        disabled={form.processing}
                        className="w-full"
                    >
                        {form.processing && <Spinner />}
                        Gerar documento
                    </Button>
                </aside>
            </form>
        </>
    );
}

TemplateUse.layout = {
    breadcrumbs: [
        { title: 'Modelos', href: templatesIndex() },
        { title: 'Usar modelo', href: templatesIndex() },
    ],
};

function Card({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <section className="border-border bg-card shadow-card rounded-xl border p-5">
            <h2 className="text-foreground text-[15px] font-bold">{title}</h2>
            {description && (
                <p className="text-muted-foreground mt-1 text-[12.5px]">
                    {description}
                </p>
            )}
            <div className="mt-4">{children}</div>
        </section>
    );
}

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex justify-between gap-3">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-foreground text-right font-semibold">
                {children}
            </dd>
        </div>
    );
}
