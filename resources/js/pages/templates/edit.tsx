import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Download,
    Eye,
    FileText,
    Save,
    TriangleAlert,
    Upload,
} from 'lucide-react';
import { useMemo, useRef, useState, type ReactNode } from 'react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { RoleEditor } from '@/components/templates/role-editor';
import { TemplateFieldEditor } from '@/components/templates/template-field-editor';
import type {
    ConversionInfo,
    OptionItem,
    ParticipantRoleOption,
    TemplateDefinitionProps,
    TemplateFieldDraft,
    TemplatePageInfo,
    TemplateRoleDraft,
    TemplateSummary,
    TemplateVariableDraft,
    TemplateVersionRow,
} from '@/components/templates/types';
import { VariableEditor } from '@/components/templates/variable-editor';
import { Badge } from '@/components/ui/badge';
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
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { formatBytes, formatDateTime, plural } from '@/lib/format';
import { create as envelopesCreate } from '@/routes/envelopes';
import { index as templatesIndex, preview, update } from '@/routes/templates';
import {
    show as sourceShow,
    update as sourceUpdate,
} from '@/routes/templates/source';

interface TemplateEditProps {
    template: TemplateSummary;
    version: {
        id: string;
        number: number;
        created_at: string | null;
        original_filename: string | null;
        size_bytes: number | null;
        page_count: number | null;
        pages: TemplatePageInfo[];
        placeholders: string[];
    };
    definition: TemplateDefinitionProps;
    versions: TemplateVersionRow[];
    options: {
        variable_types: OptionItem[];
        participant_roles: ParticipantRoleOption[];
        signing_orders: OptionItem[];
        date_formats: string[];
    };
    limits: {
        max_variables: number;
        max_roles: number;
        max_fields: number;
        role_name_max: number;
        html_max_length: number;
        max_upload_bytes: number;
    };
    conversion: ConversionInfo;
    can: { update: boolean; use: boolean };
}

/**
 * Editor de modelo (Fase 2 §2.1). Cada "Salvar" que muda o conteúdo cria uma
 * versão nova; documentos já gerados guardam a versão que usaram e não mudam.
 * O estado local é reiniciado quando a versão muda (`key` = id da versão).
 */
export default function TemplateEdit(props: TemplateEditProps) {
    // A aba fica fora do editor: salvar cria uma versão nova e remonta o editor,
    // mas a pessoa continua na aba em que estava.
    const [tab, setTab] = useState('content');

    return (
        <TemplateEditor
            key={props.version.id}
            {...props}
            tab={tab}
            onTabChange={setTab}
        />
    );
}

TemplateEdit.layout = {
    breadcrumbs: [
        { title: 'Modelos', href: templatesIndex() },
        { title: 'Editar modelo', href: templatesIndex() },
    ],
};

function withClientIds(
    fields: TemplateDefinitionProps['fields'],
): TemplateFieldDraft[] {
    return fields.map((field) => ({
        ...field,
        client_id: field.id ?? crypto.randomUUID(),
        options: field.options ?? {},
    }));
}

function TemplateEditor({
    template,
    version,
    definition,
    versions,
    options,
    limits,
    conversion,
    can,
    tab,
    onTabChange,
}: TemplateEditProps & {
    tab: string;
    onTabChange: (tab: string) => void;
}) {
    const errors = (usePage().props.errors ?? {}) as Record<string, string>;
    const fileInput = useRef<HTMLInputElement>(null);
    const htmlInput = useRef<HTMLTextAreaElement>(null);

    const [name, setName] = useState(template.name);
    const [category, setCategory] = useState(template.category ?? '');
    const [description, setDescription] = useState(template.description ?? '');
    const [signingOrder, setSigningOrder] = useState(
        definition.signing_order ?? 'sequential',
    );
    const [html, setHtml] = useState(definition.html_body ?? '');
    const [variables, setVariables] = useState<TemplateVariableDraft[]>(
        definition.variables.map((variable) => ({
            ...variable,
            options: Array.isArray(variable.options)
                ? {}
                : (variable.options ?? {}),
        })),
    );
    const [roles, setRoles] = useState<TemplateRoleDraft[]>(definition.roles);
    const [fields, setFields] = useState<TemplateFieldDraft[]>(() =>
        withClientIds(definition.fields),
    );
    const [saving, setSaving] = useState(false);
    const [uploading, setUploading] = useState(false);

    const payload = () => ({
        name,
        category: category || null,
        description: description || null,
        signing_order: signingOrder,
        html_body: template.source_type === 'html' ? html : null,
        variables,
        roles,
        fields: fields.map(({ client_id: _clientId, ...field }) => field),
    });

    const initial = useMemo(
        () =>
            JSON.stringify({
                name: template.name,
                category: template.category ?? '',
                description: template.description ?? '',
                signingOrder: definition.signing_order ?? 'sequential',
                html: definition.html_body ?? '',
                variables: definition.variables,
                roles: definition.roles,
                fields: definition.fields.length,
            }),
        [template, definition],
    );

    const dirty =
        JSON.stringify({
            name,
            category,
            description,
            signingOrder,
            html,
            variables,
            roles,
            fields: fields.length,
        }) !== initial ||
        JSON.stringify(fields.map(({ client_id: _c, ...f }) => f)) !==
            JSON.stringify(
                withClientIds(definition.fields).map(
                    ({ client_id: _c, ...f }) => f,
                ),
            );

    const save = () => {
        setSaving(true);
        router.put(update.url(template.id), payload(), {
            preserveScroll: true,
            onFinish: () => setSaving(false),
        });
    };

    const replaceFile = (file: File) => {
        setUploading(true);
        router.post(
            sourceUpdate.url(template.id),
            { file },
            {
                forceFormData: true,
                preserveScroll: true,
                onFinish: () => {
                    setUploading(false);

                    if (fileInput.current) {
                        fileInput.current.value = '';
                    }
                },
            },
        );
    };

    const updateRoles = (next: TemplateRoleDraft[]) => {
        const refs = new Set(next.map((role) => role.ref));
        setRoles(next);
        setFields((current) =>
            current.filter((field) => refs.has(field.role_ref)),
        );
    };

    const insertVariable = (key: string) => {
        const token = `{{${key}}}`;
        const element = htmlInput.current;

        if (!element) {
            setHtml((current) => current + token);

            return;
        }

        const start = element.selectionStart;
        const end = element.selectionEnd;
        setHtml(html.slice(0, start) + token + html.slice(end));
        requestAnimationFrame(() => {
            element.focus();
            element.setSelectionRange(
                start + token.length,
                start + token.length,
            );
        });
    };

    const hasError = (prefix: string) =>
        Object.keys(errors).some((key) => key.startsWith(prefix));
    const fieldCounts = fields.reduce<Record<string, number>>(
        (counts, field) => {
            counts[field.role_ref] = (counts[field.role_ref] ?? 0) + 1;

            return counts;
        },
        {},
    );

    const readOnly = !can.update;
    const marker = (key: string) =>
        template.source_type === 'docx' ? `\${${key}}` : `{{${key}}}`;

    return (
        <>
            <Head title={`Modelo · ${template.name}`} />
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
                eyebrow="Modelo"
                title={template.name}
                badge={
                    <span className="flex gap-1.5">
                        <Badge variant="neutral">{template.source_label}</Badge>
                        <Badge variant="info">Versão {version.number}</Badge>
                        {template.status === 'archived' && (
                            <Badge variant="draft">Arquivado</Badge>
                        )}
                    </span>
                }
                subtitle="Salvar com mudanças no conteúdo cria uma versão nova. Documentos já gerados não mudam."
                actions={
                    <>
                        {template.source_type !== 'docx' && (
                            <Button asChild variant="outline">
                                <a
                                    href={preview.url(template.id)}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <Eye /> Pré-visualizar
                                </a>
                            </Button>
                        )}
                        {can.use && (
                            <Button asChild variant="outline">
                                <Link
                                    href={envelopesCreate.url({
                                        query: { template: template.id },
                                    })}
                                >
                                    Usar modelo
                                </Link>
                            </Button>
                        )}
                        {can.update && (
                            <Button onClick={save} disabled={saving || !dirty}>
                                {saving ? <Spinner /> : <Save />}
                                Salvar
                            </Button>
                        )}
                    </>
                }
            />

            {errors.template && (
                <div className="border-danger-border bg-danger-bg text-danger rounded-lg border p-3 text-[13px]">
                    {errors.template}
                </div>
            )}

            <Tabs value={tab} onValueChange={onTabChange}>
                <TabsList>
                    <TabsTrigger value="content">
                        Conteúdo{' '}
                        <ErrorDot
                            show={hasError('html_body') || hasError('name')}
                        />
                    </TabsTrigger>
                    {template.supports_variables && (
                        <TabsTrigger value="variables">
                            Variáveis ({variables.length}){' '}
                            <ErrorDot show={hasError('variables')} />
                        </TabsTrigger>
                    )}
                    <TabsTrigger value="roles">
                        Participantes ({roles.length}){' '}
                        <ErrorDot show={hasError('roles')} />
                    </TabsTrigger>
                    {template.supports_fields && (
                        <TabsTrigger value="fields">
                            Campos ({fields.length}){' '}
                            <ErrorDot show={hasError('fields')} />
                        </TabsTrigger>
                    )}
                    <TabsTrigger value="versions">
                        Versões ({versions.length})
                    </TabsTrigger>
                </TabsList>

                <TabsContent value="content" className="flex flex-col gap-4">
                    <Section title="Dados do modelo">
                        <div className="grid gap-3 md:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="name">Nome</Label>
                                <Input
                                    id="name"
                                    value={name}
                                    maxLength={160}
                                    disabled={readOnly}
                                    onChange={(e) => setName(e.target.value)}
                                />
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="category">Categoria</Label>
                                <Input
                                    id="category"
                                    value={category}
                                    maxLength={60}
                                    disabled={readOnly}
                                    onChange={(e) =>
                                        setCategory(e.target.value)
                                    }
                                    placeholder="Ex.: Locação"
                                />
                                <InputError message={errors.category} />
                            </div>
                            <div className="grid gap-1.5 md:col-span-2">
                                <Label htmlFor="description">Descrição</Label>
                                <Textarea
                                    id="description"
                                    rows={2}
                                    value={description}
                                    maxLength={500}
                                    disabled={readOnly}
                                    onChange={(e) =>
                                        setDescription(e.target.value)
                                    }
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="signing-order">
                                    Ordem de assinatura
                                </Label>
                                <Select
                                    value={signingOrder}
                                    disabled={readOnly}
                                    onValueChange={(value) =>
                                        setSigningOrder(
                                            value as 'sequential' | 'parallel',
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        id="signing-order"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {options.signing_orders.map(
                                            (option) => (
                                                <SelectItem
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </Section>

                    {template.source_type === 'html' && (
                        <Section
                            title="Texto do documento"
                            description="HTML simples: títulos, parágrafos, listas, tabelas e formatação. Scripts, imagens, links, estilos externos e eventos são removidos ao salvar. Variáveis entram como texto — nenhuma expressão é executada."
                        >
                            {variables.length > 0 && (
                                <div className="mb-2 flex flex-wrap items-center gap-1.5">
                                    <span className="text-muted-foreground text-[12px]">
                                        Inserir variável:
                                    </span>
                                    {variables.map((variable) => (
                                        <button
                                            key={variable.key}
                                            type="button"
                                            disabled={readOnly}
                                            onClick={() =>
                                                insertVariable(variable.key)
                                            }
                                            className="border-border hover:bg-accent-subtle rounded-md border bg-white px-2 py-0.5 font-mono text-[11.5px]"
                                        >
                                            {`{{${variable.key}}}`}
                                        </button>
                                    ))}
                                </div>
                            )}
                            <Textarea
                                ref={htmlInput}
                                aria-label="Texto do modelo em HTML"
                                rows={18}
                                value={html}
                                maxLength={limits.html_max_length}
                                disabled={readOnly}
                                onChange={(e) => setHtml(e.target.value)}
                                className="font-mono text-[12.5px] leading-relaxed"
                            />
                            <InputError message={errors.html_body} />
                        </Section>
                    )}

                    {template.requires_file && (
                        <Section title="Arquivo do modelo">
                            <div className="flex flex-wrap items-center gap-3">
                                <span className="bg-primary-soft text-primary flex size-10 items-center justify-center rounded-lg">
                                    <FileText className="size-5" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="text-foreground truncate text-[13.5px] font-semibold">
                                        {version.original_filename ?? 'Arquivo'}
                                    </p>
                                    <p className="text-muted-foreground text-[12px]">
                                        {[
                                            formatBytes(version.size_bytes),
                                            version.page_count
                                                ? plural(
                                                      version.page_count,
                                                      'página',
                                                  )
                                                : null,
                                            version.placeholders.length > 0
                                                ? plural(
                                                      version.placeholders
                                                          .length,
                                                      'marcador',
                                                      'marcadores',
                                                  )
                                                : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                </div>
                                <Button asChild variant="outline">
                                    <a href={sourceShow.url(template.id)}>
                                        <Download /> Baixar
                                    </a>
                                </Button>
                                {can.update && (
                                    <>
                                        <input
                                            ref={fileInput}
                                            type="file"
                                            className="hidden"
                                            accept={
                                                template.source_type === 'pdf'
                                                    ? 'application/pdf,.pdf'
                                                    : '.docx'
                                            }
                                            onChange={(e) => {
                                                const file =
                                                    e.target.files?.[0];

                                                if (file) {
                                                    replaceFile(file);
                                                }
                                            }}
                                        />
                                        <Button
                                            variant="outline"
                                            disabled={uploading}
                                            onClick={() =>
                                                fileInput.current?.click()
                                            }
                                        >
                                            {uploading ? (
                                                <Spinner />
                                            ) : (
                                                <Upload />
                                            )}{' '}
                                            Substituir arquivo
                                        </Button>
                                    </>
                                )}
                            </div>
                            <InputError message={errors.file} />
                            {template.source_type === 'docx' && (
                                <p className="text-muted-foreground mt-3 text-[12.5px]">
                                    Use marcadores{' '}
                                    <code className="font-mono">
                                        {'${nome_da_variavel}'}
                                    </code>{' '}
                                    no Word, sem mudar a formatação no meio do
                                    marcador. Substituir o arquivo mantém as
                                    variáveis e acrescenta as novas.
                                </p>
                            )}
                            {template.source_type === 'pdf' && (
                                <p className="text-muted-foreground mt-3 text-[12.5px]">
                                    Um PDF fixo não tem variáveis no texto.
                                    Substituir o arquivo mantém os
                                    participantes; campos que não couberem nas
                                    novas páginas são removidos.
                                </p>
                            )}
                            {conversion.required && !conversion.available && (
                                <div className="border-warning-border bg-warning-bg text-warning mt-3 flex gap-2 rounded-lg border p-3 text-[12.5px]">
                                    <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                                    <span>{conversion.message}</span>
                                </div>
                            )}
                        </Section>
                    )}
                </TabsContent>

                {template.supports_variables && (
                    <TabsContent value="variables">
                        <VariableEditor
                            variables={variables}
                            onChange={setVariables}
                            types={options.variable_types}
                            errors={errors}
                            max={limits.max_variables}
                            placeholder={marker}
                            disabled={readOnly}
                        />
                    </TabsContent>
                )}

                <TabsContent value="roles">
                    <p className="text-muted-foreground mb-3 text-[13px]">
                        Cada participante vira um destinatário ao usar o modelo,
                        nesta ordem. O nome aparece como papel do signatário
                        ("Locatário", "Testemunha 1").
                    </p>
                    <RoleEditor
                        roles={roles}
                        onChange={updateRoles}
                        options={options.participant_roles}
                        errors={errors}
                        max={limits.max_roles}
                        nameMax={limits.role_name_max}
                        fieldCounts={
                            template.supports_fields ? fieldCounts : undefined
                        }
                        disabled={readOnly}
                    />
                </TabsContent>

                {template.supports_fields && (
                    <TabsContent value="fields">
                        <TemplateFieldEditor
                            pdfUrl={preview.url(template.id)}
                            pages={version.pages}
                            roles={roles}
                            fields={fields}
                            onChange={setFields}
                            errors={errors}
                            maxFields={limits.max_fields}
                            disabled={readOnly}
                        />
                    </TabsContent>
                )}

                <TabsContent value="versions">
                    <div className="border-border overflow-x-auto rounded-xl border bg-white">
                        <table className="w-full text-[13px]">
                            <thead className="text-muted-foreground bg-accent-subtle text-left text-[12px]">
                                <tr>
                                    <th className="px-4 py-2.5 font-semibold">
                                        Versão
                                    </th>
                                    <th className="px-4 py-2.5 font-semibold">
                                        Criada em
                                    </th>
                                    <th className="px-4 py-2.5 font-semibold">
                                        Por
                                    </th>
                                    <th className="px-4 py-2.5 font-semibold">
                                        Arquivo
                                    </th>
                                    <th className="px-4 py-2.5 font-semibold">
                                        Documentos gerados
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {versions.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-border border-t"
                                    >
                                        <td className="px-4 py-2.5 font-semibold">
                                            v{row.number}{' '}
                                            {row.is_current && (
                                                <Badge
                                                    variant="success"
                                                    className="ml-1.5"
                                                >
                                                    Atual
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="px-4 py-2.5">
                                            {formatDateTime(row.created_at)}
                                        </td>
                                        <td className="px-4 py-2.5">
                                            {row.created_by ?? '—'}
                                        </td>
                                        <td className="px-4 py-2.5">
                                            {row.original_filename ?? '—'}
                                        </td>
                                        <td className="px-4 py-2.5">
                                            {row.uses}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </TabsContent>
            </Tabs>
        </>
    );
}

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
        <section className="border-border bg-card shadow-card rounded-xl border p-5">
            <h2 className="text-foreground text-[15px] font-bold">{title}</h2>
            {description && (
                <p className="text-muted-foreground mt-1 mb-3 text-[12.5px]">
                    {description}
                </p>
            )}
            <div className={description ? '' : 'mt-3'}>{children}</div>
        </section>
    );
}

function ErrorDot({ show }: { show: boolean }) {
    return show ? (
        <span
            className="bg-danger ml-1 inline-block size-1.5 rounded-full"
            aria-label="Há erros nesta aba"
        />
    ) : null;
}
