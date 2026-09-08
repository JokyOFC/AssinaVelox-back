import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    FileText,
    Folder,
    FolderPlus,
    MoreHorizontal,
    Plus,
    RefreshCw,
    XCircle,
} from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { toast } from 'sonner';
import { AvatarStack } from '@/components/avatar-initials';
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    BulkActionBar,
    DataTable,
    TitleCell,
    type DataTableColumn,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { FilterBar, FilterChip, SearchInput } from '@/components/filter-bar';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { RailNavButton, UnderlineTabs } from '@/components/segmented-control';
import { EnvelopeStatusBadge } from '@/components/status/envelope-status-badge';
import { TablePagination } from '@/components/table-pagination';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
import {
    formatBytes,
    formatNumber,
    formatProgress,
    formatRelativeDateTime,
    plural,
} from '@/lib/format';
import { envelopeTabLabels } from '@/lib/labels';
import {
    bulk as envelopesBulk,
    cancel as envelopeCancel,
    create as envelopesCreate,
    destroy as envelopeDestroy,
    duplicate as envelopeDuplicate,
    edit as envelopeEdit,
    index as envelopesIndex,
    show as envelopeShow,
} from '@/routes/envelopes';
import { store as storeFolder } from '@/routes/folders';
import type {
    EnvelopeListItem,
    FolderFilterItem,
    Paginated,
    UserRef,
} from '@/types';

type EnvelopeTab =
    | 'all'
    | 'awaiting'
    | 'in_progress'
    | 'completed'
    | 'drafts'
    | 'refused_expired';
type Sort =
    | 'updated_desc'
    | 'updated_asc'
    | 'created_desc'
    | 'title_asc'
    | 'expires_asc';

export interface EnvelopesIndexProps {
    filters: {
        status: EnvelopeTab;
        folder: string | null;
        q: string;
        period_from: string | null;
        period_to: string | null;
        recipient: string;
        creator: string | null;
        sort: Sort;
    };
    summary: { total: number; awaiting: number; storage_used_bytes: number };
    tabs: Record<EnvelopeTab, number>;
    folders: FolderFilterItem[];
    creators: UserRef[];
    envelopes: Paginated<EnvelopeListItem>;
    can: { create_folder: boolean; bulk_cancel: boolean };
}

const SORT_LABELS: Record<Sort, string> = {
    updated_desc: 'Atualizados recentemente',
    updated_asc: 'Atualizados há mais tempo',
    created_desc: 'Criados recentemente',
    title_asc: 'Título (A–Z)',
    expires_asc: 'Vencem primeiro',
};

type PendingAction =
    | { type: 'cancel' | 'delete'; envelope: EnvelopeListItem }
    | { type: 'bulk_cancel' }
    | null;

/**
 * Documentos (ROUTES §2.5; DESIGN §6.3): rail de pastas, abas por status,
 * filtros, tabela com seleção e ações em lote. Versão inicial funcional; Wave B refina.
 */
export default function EnvelopesIndex({
    filters,
    summary,
    tabs,
    folders,
    creators,
    envelopes,
    can,
}: EnvelopesIndexProps) {
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [pending, setPending] = useState<PendingAction>(null);
    const [busy, setBusy] = useState(false);
    const [folderDialog, setFolderDialog] = useState(false);
    const [moveOpen, setMoveOpen] = useState(false);
    const [moveTarget, setMoveTarget] = useState<string>('__none');

    const apply = (next: Partial<EnvelopesIndexProps['filters']>) => {
        router.get(
            envelopesIndex.url({
                query: { ...filters, ...next, page: undefined },
            }),
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onSuccess: () => setSelected(new Set()),
            },
        );
    };

    const runBulk = (
        action: 'move' | 'resend' | 'cancel',
        payload: Record<string, unknown> = {},
    ) => {
        setBusy(true);
        router.post(
            envelopesBulk(action).url,
            { ids: [...selected], ...payload },
            {
                preserveScroll: true,
                onSuccess: () => setSelected(new Set()),
                onFinish: () => {
                    setBusy(false);
                    setPending(null);
                    setMoveOpen(false);
                },
            },
        );
    };

    const runPending = () => {
        if (!pending) {
            return;
        }

        if (pending.type === 'bulk_cancel') {
            runBulk('cancel');

            return;
        }

        setBusy(true);
        const options = {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setPending(null);
            },
        };

        if (pending.type === 'cancel') {
            router.post(envelopeCancel(pending.envelope.id).url, {}, options);
        } else {
            router.delete(envelopeDestroy(pending.envelope.id).url, options);
        }
    };

    const columns: DataTableColumn<EnvelopeListItem>[] = [
        {
            key: 'title',
            header: 'Documento',
            width: 'minmax(0,2.6fr)',
            cell: (row) => (
                <TitleCell
                    icon={<FileText className="size-[17px]" />}
                    title={row.title}
                    href={envelopeShow(row.id).url}
                    onClick={() => router.visit(envelopeShow(row.id).url)}
                    meta={[
                        row.display_code,
                        row.document?.pages
                            ? `PDF · ${plural(row.document.pages, 'pág')}`
                            : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                />
            ),
        },
        {
            key: 'folder',
            header: 'Pasta',
            width: '1fr',
            cell: (row) => (
                <span className="text-text-secondary truncate text-[13px]">
                    {row.folder?.name ?? '—'}
                </span>
            ),
        },
        {
            key: 'recipients',
            header: 'Signatários',
            width: '1.2fr',
            cell: (row) => (
                <AvatarStack
                    items={row.recipients}
                    progress={formatProgress(
                        row.signed_count,
                        row.recipients_count,
                    )}
                />
            ),
        },
        {
            key: 'status',
            header: 'Status',
            width: '1.1fr',
            cell: (row) => (
                <EnvelopeStatusBadge
                    status={row.status}
                    signedCount={row.signed_count}
                    label={row.status_label}
                />
            ),
        },
        {
            key: 'creator',
            header: 'Criado por',
            width: '1fr',
            cell: (row) => (
                <span className="text-text-secondary truncate text-[13px]">
                    {row.creator.name}
                </span>
            ),
        },
        {
            key: 'updated',
            header: 'Atualizado',
            width: '1fr',
            cell: (row) => (
                <span className="text-text-secondary tabular text-[13px] whitespace-nowrap">
                    {formatRelativeDateTime(row.updated_at)}
                </span>
            ),
        },
        {
            key: 'actions',
            header: '',
            width: '44px',
            align: 'right',
            cell: (row) => (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon-xs"
                            aria-label="Ações"
                        >
                            <MoreHorizontal className="text-muted-foreground size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem asChild>
                            <Link href={envelopeShow(row.id)}>Abrir</Link>
                        </DropdownMenuItem>
                        {row.can.update &&
                            ['draft', 'preparing', 'ready'].includes(
                                row.status,
                            ) && (
                                <DropdownMenuItem asChild>
                                    <Link href={envelopeEdit(row.id)}>
                                        Editar rascunho
                                    </Link>
                                </DropdownMenuItem>
                            )}
                        <DropdownMenuItem
                            onSelect={() =>
                                router.post(envelopeDuplicate(row.id).url)
                            }
                        >
                            Duplicar
                        </DropdownMenuItem>
                        {(row.can.cancel || row.can.delete) && (
                            <DropdownMenuSeparator />
                        )}
                        {row.can.cancel && row.status === 'in_progress' && (
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() =>
                                    setPending({
                                        type: 'cancel',
                                        envelope: row,
                                    })
                                }
                            >
                                <XCircle className="size-3.5" />
                                Cancelar documento
                            </DropdownMenuItem>
                        )}
                        {row.can.delete && (
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() =>
                                    setPending({
                                        type: 'delete',
                                        envelope: row,
                                    })
                                }
                            >
                                Excluir rascunho
                            </DropdownMenuItem>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            ),
        },
    ];

    const hasFilters =
        !!filters.q ||
        !!filters.creator ||
        !!filters.folder ||
        filters.status !== 'all';

    return (
        <>
            <Head title="Documentos" />
            <PageHeader
                title="Documentos"
                subtitle={`${plural(summary.total, 'documento')} · ${plural(summary.awaiting, 'aguardando assinatura', 'aguardando assinatura')} · ${formatBytes(summary.storage_used_bytes)} usados`}
                actions={
                    <Button asChild>
                        <Link href={envelopesCreate()}>
                            <Plus className="size-[15px]" strokeWidth={2.5} />
                            Nova solicitação
                        </Link>
                    </Button>
                }
            />

            <div className="flex flex-wrap items-start gap-5">
                <aside className="hidden w-[200px] shrink-0 flex-col gap-1 md:flex">
                    <div className="flex h-7 items-center justify-between px-3">
                        <span className="text-muted-foreground text-[10.5px] font-bold tracking-[.14em] uppercase">
                            Pastas
                        </span>
                        {can.create_folder && (
                            <button
                                type="button"
                                onClick={() => setFolderDialog(true)}
                                aria-label="Nova pasta"
                                className="text-muted-foreground hover:bg-accent hover:text-foreground flex size-[22px] items-center justify-center rounded-md"
                            >
                                <FolderPlus className="size-3.5" />
                            </button>
                        )}
                    </div>
                    {folders.map((folder) => (
                        <RailNavButton
                            key={folder.id ?? 'all'}
                            active={(filters.folder ?? null) === folder.id}
                            onClick={() => apply({ folder: folder.id })}
                            trailing={
                                <span className="text-muted-foreground tabular text-[11.5px]">
                                    {formatNumber(folder.count)}
                                </span>
                            }
                        >
                            <span className="inline-flex items-center gap-2">
                                <Folder className="size-3.5" />
                                {folder.name}
                            </span>
                        </RailNavButton>
                    ))}
                    <p className="bg-primary-soft text-primary mt-2 rounded-[10px] p-3 text-[12px] leading-[1.5]">
                        Pastas organizam documentos por assunto. Mover um
                        documento não altera status nem assinaturas.
                    </p>
                </aside>

                <div className="border-border bg-card shadow-card min-w-0 flex-1 rounded-xl border">
                    <UnderlineTabs
                        value={filters.status}
                        onChange={(status) => apply({ status })}
                        options={(
                            Object.keys(envelopeTabLabels) as EnvelopeTab[]
                        ).map((key) => ({
                            value: key,
                            label: envelopeTabLabels[key],
                            count: tabs[key],
                        }))}
                    />
                    <FilterBar
                        trailing={
                            <Select
                                value={filters.sort}
                                onValueChange={(sort) =>
                                    apply({ sort: sort as Sort })
                                }
                            >
                                <SelectTrigger
                                    size="sm"
                                    className="h-[34px] text-[13px]"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {(Object.keys(SORT_LABELS) as Sort[]).map(
                                        (key) => (
                                            <SelectItem key={key} value={key}>
                                                {SORT_LABELS[key]}
                                            </SelectItem>
                                        ),
                                    )}
                                </SelectContent>
                            </Select>
                        }
                    >
                        <SearchInput
                            value={filters.q}
                            onChange={(q) => apply({ q })}
                            placeholder="Buscar por título, código ou signatário"
                        />
                        <FilterChip
                            label="Pasta"
                            value={filters.folder}
                            onChange={(folder) => apply({ folder })}
                            options={folders
                                .filter((f) => f.id !== null)
                                .map((f) => ({
                                    value: f.id as string,
                                    label: f.name,
                                    count: f.count,
                                }))}
                            allLabel="Todas"
                            className="md:hidden"
                        />
                        <FilterChip
                            label="Criado por"
                            value={filters.creator}
                            onChange={(creator) => apply({ creator })}
                            options={creators.map((c) => ({
                                value: c.id,
                                label: c.name,
                            }))}
                        />
                    </FilterBar>

                    <BulkActionBar
                        count={selected.size}
                        onClear={() => setSelected(new Set())}
                    >
                        <Button
                            variant="outline"
                            size="xs"
                            onClick={() => setMoveOpen(true)}
                            disabled={busy}
                        >
                            <Folder className="size-3.5" /> Mover
                        </Button>
                        <Button
                            variant="outline"
                            size="xs"
                            onClick={() => runBulk('resend')}
                            disabled={busy}
                        >
                            <RefreshCw className="size-3.5" /> Reenviar convites
                        </Button>
                        {can.bulk_cancel && (
                            <Button
                                variant="destructive"
                                size="xs"
                                onClick={() =>
                                    setPending({ type: 'bulk_cancel' })
                                }
                                disabled={busy}
                            >
                                <XCircle className="size-3.5" /> Cancelar
                            </Button>
                        )}
                    </BulkActionBar>

                    <DataTable
                        columns={columns}
                        rows={envelopes.data}
                        rowKey={(row) => row.id}
                        selectable
                        selected={selected}
                        onSelectedChange={setSelected}
                        minWidth={960}
                        dense
                        empty={
                            hasFilters ? (
                                <EmptyState
                                    variant="inline"
                                    title="Nenhum documento nesta combinação de pasta e status."
                                />
                            ) : (
                                <EmptyState
                                    icon={FileText}
                                    title="Envie seu primeiro documento"
                                    description="Faça upload de um PDF ou DOCX, indique quem assina e posicione os campos. Leva menos de 5 minutos."
                                    action={
                                        <Button asChild>
                                            <Link href={envelopesCreate()}>
                                                <Plus
                                                    className="size-[15px]"
                                                    strokeWidth={2.5}
                                                />
                                                Nova solicitação
                                            </Link>
                                        </Button>
                                    }
                                />
                            )
                        }
                    />
                    <TablePagination
                        paginated={envelopes}
                        entity="documentos"
                        entitySingular="documento"
                    />
                </div>
            </div>

            <NewFolderDialog
                open={folderDialog}
                onOpenChange={setFolderDialog}
            />

            <Dialog open={moveOpen} onOpenChange={setMoveOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Mover {plural(selected.size, 'documento')}
                        </DialogTitle>
                        <DialogDescription>
                            Escolha a pasta de destino. Mover não altera status
                            nem assinaturas.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-1.5">
                        <Label>Pasta</Label>
                        <Select
                            value={moveTarget}
                            onValueChange={setMoveTarget}
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__none">
                                    Sem pasta (Todos)
                                </SelectItem>
                                {folders
                                    .filter((f) => f.id !== null)
                                    .map((f) => (
                                        <SelectItem
                                            key={f.id}
                                            value={f.id as string}
                                        >
                                            {f.name}
                                        </SelectItem>
                                    ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setMoveOpen(false)}
                            disabled={busy}
                        >
                            Cancelar
                        </Button>
                        <Button
                            onClick={() =>
                                runBulk('move', {
                                    folder_id:
                                        moveTarget === '__none'
                                            ? null
                                            : moveTarget,
                                })
                            }
                            disabled={busy}
                        >
                            {busy && <Spinner />}
                            Mover
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={pending !== null}
                onOpenChange={(open) => !open && setPending(null)}
                destructive
                processing={busy}
                title={
                    pending?.type === 'bulk_cancel'
                        ? `Cancelar ${plural(selected.size, 'documento')}?`
                        : pending?.type === 'cancel'
                          ? `Cancelar “${pending.envelope.title}”?`
                          : pending?.type === 'delete'
                            ? `Excluir o rascunho “${pending.envelope.title}”?`
                            : ''
                }
                description={
                    pending?.type === 'delete'
                        ? 'O rascunho e o arquivo enviado serão removidos. Esta ação não pode ser desfeita.'
                        : 'Os signatários pendentes serão avisados e o link de assinatura deixa de funcionar. Documentos que não estão em andamento são ignorados.'
                }
                confirmLabel={
                    pending?.type === 'delete'
                        ? 'Excluir rascunho'
                        : 'Cancelar documento'
                }
                onConfirm={runPending}
            />
        </>
    );
}

function NewFolderDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({ name: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(storeFolder.url(), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(`Pasta “${form.data.name}” criada`);
                form.reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Nova pasta</DialogTitle>
                    <DialogDescription>
                        Pastas ajudam a organizar documentos por assunto ou
                        equipe.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="folder-name">Nome da pasta</Label>
                        <Input
                            id="folder-name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            placeholder="Ex.: Locação"
                            autoFocus
                            required
                            minLength={2}
                            maxLength={60}
                            aria-invalid={!!form.errors.name}
                        />
                        <InputError message={form.errors.name} />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            disabled={form.processing}
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing || !form.data.name.trim()}
                        >
                            {form.processing && <Spinner />}
                            Criar pasta
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

EnvelopesIndex.layout = {
    breadcrumbs: [{ title: 'Documentos', href: envelopesIndex() }],
};
