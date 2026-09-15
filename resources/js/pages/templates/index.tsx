import { Head, Link, router } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    Copy,
    Download,
    FileText,
    LayoutTemplate,
    MoreHorizontal,
    Pencil,
    Plus,
    Upload,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { SearchInput, SelectableChip } from '@/components/filter-bar';
import { PageHeader } from '@/components/page-header';
import { Phase2EmptyState } from '@/components/phase2-empty-state';
import {
    BulkGenerateButton,
    BulkGenerationsLink,
} from '@/components/bulk-generations/bulk-generate-button';
import { CreateTemplateDialog } from '@/components/templates/create-template-dialog';
import type {
    ConversionInfo,
    TemplateRow,
    TemplateSourceType,
    TemplateStatus,
} from '@/components/templates/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatTimeAgo, plural } from '@/lib/format';
import {
    index as envelopesIndex,
    create as envelopesCreate,
} from '@/routes/envelopes';
import {
    archive,
    duplicate,
    edit,
    index as templatesIndex,
    restore,
} from '@/routes/templates';
import { show as sourceShow } from '@/routes/templates/source';

const PLACEHOLDER_CATEGORIES = ['Todos', 'Locação', 'Vendas', 'Jurídico', 'RH'];

interface TemplatesIndexProps {
    feature: string;
    title: string;
    subtitle: string;
    /** Presente só com a flag `templates` ligada (docs/fase-2/modelos.md §7). */
    enabled?: boolean;
    templates?: TemplateRow[];
    categories?: string[];
    filters?: { status: TemplateStatus };
    counts?: { active: number; archived: number };
    limits?: { max_upload_bytes: number };
    conversion?: ConversionInfo;
    can?: { create: boolean; use: boolean };
}

/**
 * Modelos (ROUTES §2.21; mock "App - Modelos"). Com a flag `templates`
 * desligada é o placeholder da Fase 1, sem mudança nenhuma.
 */
export default function TemplatesIndex(props: TemplatesIndexProps) {
    return props.enabled ? (
        <TemplateGallery {...props} />
    ) : (
        <TemplatesPlaceholder />
    );
}

TemplatesIndex.layout = {
    breadcrumbs: [{ title: 'Modelos', href: templatesIndex() }],
};

function TemplatesPlaceholder() {
    return (
        <>
            <Head title="Modelos" />
            <PageHeader
                title="Modelos de documentos"
                subtitle="Crie solicitações a partir de modelos reutilizáveis com campos já posicionados."
                actions={
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <span>
                                <Button disabled>
                                    <Plus
                                        className="size-[15px]"
                                        strokeWidth={2.5}
                                    />
                                    Novo modelo
                                </Button>
                            </span>
                        </TooltipTrigger>
                        <TooltipContent>
                            Recurso não ativado para esta conta
                        </TooltipContent>
                    </Tooltip>
                }
            />
            <div className="flex flex-wrap gap-2">
                {PLACEHOLDER_CATEGORIES.map((category, index) => (
                    <SelectableChip
                        key={category}
                        selected={index === 0}
                        disabled
                    >
                        {category}
                    </SelectableChip>
                ))}
            </div>
            <Phase2EmptyState
                title="Modelos não estão ativados para esta conta"
                description="Enquanto isso, duplique um documento existente para reaproveitar signatários e campos."
                ctaHref={envelopesIndex.url()}
                ctaLabel="Ir para Documentos"
            />
        </>
    );
}

function normalize(value: string): string {
    return value
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}

function TemplateGallery({
    templates = [],
    categories = [],
    filters = { status: 'active' },
    counts = { active: 0, archived: 0 },
    limits = { max_upload_bytes: 25 * 1024 * 1024 },
    conversion = { required: false, available: true, message: null },
    can = { create: false, use: false },
}: TemplatesIndexProps) {
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState('Todos');
    const [dialog, setDialog] = useState<{
        open: boolean;
        source: TemplateSourceType;
    }>({
        open: false,
        source: 'pdf',
    });

    const visible = useMemo(() => {
        const needle = normalize(query.trim());

        return templates.filter(
            (template) =>
                (category === 'Todos' || template.category === category) &&
                (needle === '' ||
                    normalize(
                        `${template.name} ${template.category ?? ''} ${template.description ?? ''}`,
                    ).includes(needle)),
        );
    }, [templates, query, category]);

    const archived = filters.status === 'archived';

    const openDialog = (source: TemplateSourceType) =>
        setDialog({ open: true, source });

    return (
        <>
            <Head title="Modelos" />
            <PageHeader
                title="Modelos de documentos"
                subtitle="Documentos com campos e signatários pré-configurados. Use-os para enviar em segundos."
                actions={
                    <>
                        {can.use && <BulkGenerationsLink />}
                        {can.create && (
                            <Button onClick={() => openDialog('pdf')}>
                                <Plus
                                    className="size-[15px]"
                                    strokeWidth={2.5}
                                />
                                Novo modelo
                            </Button>
                        )}
                    </>
                }
            />

            <div className="flex flex-wrap items-center gap-2">
                <SearchInput
                    value={query}
                    onChange={setQuery}
                    placeholder="Buscar modelos"
                    debounce={150}
                />
                {['Todos', ...categories].map((item) => (
                    <SelectableChip
                        key={item}
                        selected={category === item}
                        onClick={() => setCategory(item)}
                    >
                        {item}
                    </SelectableChip>
                ))}
                <span className="ml-auto flex gap-2">
                    <SelectableChip
                        selected={!archived}
                        onClick={() =>
                            router.get(
                                templatesIndex.url(),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Ativos ({counts.active})
                    </SelectableChip>
                    <SelectableChip
                        selected={archived}
                        onClick={() =>
                            router.get(
                                templatesIndex.url({
                                    query: { status: 'archived' },
                                }),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Arquivados ({counts.archived})
                    </SelectableChip>
                </span>
            </div>

            {templates.length === 0 && !can.create ? (
                <div className="border-border bg-card shadow-card rounded-xl border">
                    <EmptyState
                        icon={LayoutTemplate}
                        title={
                            archived
                                ? 'Nenhum modelo arquivado'
                                : 'Nenhum modelo ainda'
                        }
                        description="Quem gerencia modelos na sua conta pode criar o primeiro."
                    />
                </div>
            ) : (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {visible.map((template) => (
                        <TemplateCard
                            key={template.id}
                            template={template}
                            canManage={can.create}
                            canUse={can.use}
                        />
                    ))}
                    {!archived && can.create && (
                        <button
                            type="button"
                            onClick={() => openDialog('pdf')}
                            className="border-input text-text-secondary hover:border-primary hover:bg-primary-soft/40 hover:text-primary flex min-h-[248px] flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed bg-white p-6 text-center transition-colors"
                        >
                            <span className="bg-accent flex size-10 items-center justify-center rounded-full">
                                <Upload className="size-5" />
                            </span>
                            <span className="text-[13.5px] font-semibold">
                                Criar modelo a partir de um PDF
                            </span>
                            <span className="text-muted-foreground text-[12px]">
                                Defina campos e papéis uma vez, reutilize sempre
                            </span>
                        </button>
                    )}
                    {visible.length === 0 && templates.length > 0 && (
                        <p className="text-muted-foreground col-span-full py-6 text-center text-[13.5px]">
                            Nenhum modelo corresponde à busca.
                        </p>
                    )}
                </div>
            )}

            <CreateTemplateDialog
                open={dialog.open}
                onOpenChange={(open) =>
                    setDialog((state) => ({ ...state, open }))
                }
                categories={categories}
                initialSource={dialog.source}
                maxUploadBytes={limits.max_upload_bytes}
                conversion={conversion}
            />
        </>
    );
}

function TemplateCard({
    template,
    canManage,
    canUse,
}: {
    template: TemplateRow;
    canManage: boolean;
    canUse: boolean;
}) {
    const meta =
        template.source_type === 'pdf'
            ? [
                  plural(template.roles_count, 'participante', 'participantes'),
                  plural(template.fields_count, 'campo'),
                  template.page_count
                      ? plural(template.page_count, 'pág.', 'págs.')
                      : null,
              ]
            : [
                  plural(template.roles_count, 'participante', 'participantes'),
                  plural(template.variables_count, 'variável', 'variáveis'),
                  template.source_label,
              ];

    const archived = template.status === 'archived';

    return (
        <div
            className="border-border bg-card shadow-card hover:border-input flex flex-col overflow-hidden rounded-xl border transition-shadow hover:shadow-[0_8px_24px_rgba(11,31,66,.08)]"
            data-testid="template-card"
        >
            <div className="bg-accent relative flex h-[118px] items-end justify-center px-6 pt-4">
                <div className="flex h-full w-[92px] flex-col gap-1.5 rounded-t-md border border-b-0 bg-white p-2.5 shadow-sm">
                    <div className="bg-muted h-1.5 w-3/4 rounded" />
                    <div className="bg-muted h-1 w-full rounded" />
                    <div className="bg-muted h-1 w-5/6 rounded" />
                    <div className="bg-muted h-1 w-full rounded" />
                    <div className="bg-primary-soft mt-auto h-3 w-1/2 rounded" />
                </div>
                {template.category && (
                    <Badge variant="neutral" className="absolute top-3 left-3">
                        {template.category}
                    </Badge>
                )}
                {archived && (
                    <Badge variant="draft" className="absolute top-3 right-3">
                        Arquivado
                    </Badge>
                )}
            </div>
            <div className="flex flex-1 flex-col gap-1 p-4">
                <p className="text-foreground line-clamp-2 text-[14px] font-semibold">
                    {template.name}
                </p>
                <p className="text-text-secondary text-[12.5px]">
                    {meta.filter(Boolean).join(' · ')}
                </p>
                <p className="text-muted-foreground text-[12px]">
                    Usado {plural(template.uses, 'vez', 'vezes')} · atualizado{' '}
                    {formatTimeAgo(template.updated_at)}
                </p>
            </div>
            <div className="flex items-center gap-1.5 px-4 pb-4">
                {canUse && template.usable ? (
                    <Button asChild size="sm" className="flex-1">
                        <Link
                            href={envelopesCreate.url({
                                query: { template: template.id },
                            })}
                        >
                            Usar modelo
                        </Link>
                    </Button>
                ) : (
                    <Button size="sm" className="flex-1" disabled>
                        {archived ? 'Arquivado' : 'Usar modelo'}
                    </Button>
                )}
                {canUse && template.usable && (
                    <BulkGenerateButton
                        templateId={template.id}
                        templateName={template.name}
                    />
                )}
                {canManage && (
                    <>
                        <Button
                            asChild
                            variant="outline"
                            size="icon-sm"
                            aria-label={`Editar ${template.name}`}
                        >
                            <Link href={edit.url(template.id)}>
                                <Pencil />
                            </Link>
                        </Button>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="outline"
                                    size="icon-sm"
                                    aria-label={`Mais ações de ${template.name}`}
                                >
                                    <MoreHorizontal />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    onSelect={() =>
                                        router.post(
                                            duplicate.url(template.id),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <Copy /> Duplicar
                                </DropdownMenuItem>
                                {template.source_type !== 'html' && (
                                    <DropdownMenuItem asChild>
                                        <a href={sourceShow.url(template.id)}>
                                            <Download /> Baixar arquivo
                                        </a>
                                    </DropdownMenuItem>
                                )}
                                {archived ? (
                                    <DropdownMenuItem
                                        onSelect={() =>
                                            router.post(
                                                restore.url(template.id),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <ArchiveRestore /> Restaurar
                                    </DropdownMenuItem>
                                ) : (
                                    <DropdownMenuItem
                                        onSelect={() =>
                                            router.post(
                                                archive.url(template.id),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Archive /> Arquivar
                                    </DropdownMenuItem>
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </>
                )}
                {!canManage && template.source_type !== 'html' && (
                    <FileText
                        className="text-muted-foreground ml-1 size-4"
                        aria-label={template.source_label}
                    />
                )}
            </div>
        </div>
    );
}
