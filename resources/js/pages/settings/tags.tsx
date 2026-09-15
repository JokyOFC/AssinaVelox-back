import { Head, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Tag as TagIcon, Trash2 } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Phase2EmptyState } from '@/components/phase2-empty-state';
import {
    TAG_COLOR_LABELS,
    TagChip,
    TagSwatch,
    type TagColor,
} from '@/components/tags/tag-chip';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateMedium, plural } from '@/lib/format';
import { cn } from '@/lib/utils';
import { index as envelopesIndex } from '@/routes/envelopes';
import { tags as settingsTags } from '@/routes/settings';
import {
    destroy as destroyTag,
    store as storeTag,
    update as updateTag,
} from '@/routes/tags';

interface TagRow {
    id: string;
    name: string;
    color: TagColor;
    envelopes_count: number;
    created_at: string | null;
}

type SettingsTagsProps =
    | { enabled: false }
    | {
          enabled: true;
          tags: TagRow[];
          colors: { value: TagColor; label: string }[];
          limits: { max_tags: number; max_name: number };
          can: { manage: boolean };
      };

/**
 * Configurações › Etiquetas (Fase 2 — flag `tags`). Lista para todos; criar,
 * renomear, recolorir e excluir exigem "Gerenciar etiquetas".
 */
export default function SettingsTags(props: SettingsTagsProps) {
    const [editing, setEditing] = useState<TagRow | 'new' | null>(null);
    const [deleting, setDeleting] = useState<TagRow | null>(null);
    const [busy, setBusy] = useState(false);

    if (!props.enabled) {
        return (
            <>
                <Head title="Etiquetas" />
                <Phase2EmptyState
                    title="Etiquetas não estão ativadas para esta conta"
                    description="Organize documentos com etiquetas coloridas, filtre a lista por etiqueta e aplique em lote."
                    ctaHref={envelopesIndex.url()}
                />
            </>
        );
    }

    const { tags, colors, limits, can } = props;

    return (
        <>
            <Head title="Etiquetas" />
            <div className="border-border bg-card shadow-card rounded-xl border">
                <div className="px-5 pt-[18px] pb-3">
                    <Heading
                        variant="small"
                        title="Etiquetas"
                        description={`${plural(tags.length, 'etiqueta')} · a contagem considera só os documentos que você pode ver.`}
                        action={
                            can.manage && (
                                <Button
                                    size="sm"
                                    onClick={() => setEditing('new')}
                                    disabled={tags.length >= limits.max_tags}
                                >
                                    <Plus className="size-3.5" />
                                    Nova etiqueta
                                </Button>
                            )
                        }
                    />
                </div>

                {tags.length === 0 ? (
                    <EmptyState
                        variant="inline"
                        title="Nenhuma etiqueta criada."
                        description={
                            can.manage
                                ? 'Crie etiquetas como “Locação”, “Urgente” ou “Cliente VIP” e aplique na lista de documentos.'
                                : 'Peça a um administrador para criar etiquetas.'
                        }
                    />
                ) : (
                    <ul className="divide-muted border-muted divide-y border-t">
                        {tags.map((tag) => (
                            <li
                                key={tag.id}
                                className="flex flex-wrap items-center gap-3 px-5 py-3"
                            >
                                <TagChip tag={tag} />
                                <span className="text-muted-foreground tabular min-w-0 flex-1 text-[12.5px]">
                                    {plural(tag.envelopes_count, 'documento')}
                                    {tag.created_at &&
                                        ` · criada em ${formatDateMedium(tag.created_at)}`}
                                </span>
                                {can.manage && (
                                    <span className="flex items-center gap-1">
                                        <Button
                                            variant="ghost"
                                            size="icon-xs"
                                            aria-label={`Editar ${tag.name}`}
                                            onClick={() => setEditing(tag)}
                                        >
                                            <Pencil className="size-3.5" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon-xs"
                                            aria-label={`Excluir ${tag.name}`}
                                            onClick={() => setDeleting(tag)}
                                        >
                                            <Trash2 className="text-danger size-3.5" />
                                        </Button>
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {editing !== null && (
                <TagFormDialog
                    tag={editing === 'new' ? null : editing}
                    colors={colors}
                    maxName={limits.max_name}
                    onClose={() => setEditing(null)}
                />
            )}

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                destructive
                processing={busy}
                title={`Excluir a etiqueta “${deleting?.name ?? ''}”?`}
                description="A etiqueta sai de todos os documentos. Os documentos, status e assinaturas não mudam."
                confirmLabel="Excluir etiqueta"
                onConfirm={() => {
                    if (!deleting) {
                        return;
                    }

                    setBusy(true);
                    router.delete(destroyTag.url(deleting.id), {
                        preserveScroll: true,
                        onFinish: () => {
                            setBusy(false);
                            setDeleting(null);
                        },
                    });
                }}
            />
        </>
    );
}

function TagFormDialog({
    tag,
    colors,
    maxName,
    onClose,
}: {
    tag: TagRow | null;
    colors: { value: TagColor; label: string }[];
    maxName: number;
    onClose: () => void;
}) {
    const form = useForm<{ name: string; color: TagColor }>({
        name: tag?.name ?? '',
        color: tag?.color ?? 'blue',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };

        if (tag) {
            form.patch(updateTag.url(tag.id), options);
        } else {
            form.post(storeTag.url(), options);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>
                            {tag ? 'Editar etiqueta' : 'Nova etiqueta'}
                        </DialogTitle>
                        <DialogDescription>
                            O nome é único na conta (maiúsculas e minúsculas
                            contam como iguais).
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-1.5">
                        <Label htmlFor="tag-name">Nome</Label>
                        <Input
                            id="tag-name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            maxLength={maxName}
                            placeholder="Ex.: Locação"
                            autoFocus
                            required
                            aria-invalid={!!form.errors.name}
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="grid gap-1.5">
                        <Label id="tag-color-label">Cor</Label>
                        <div
                            role="radiogroup"
                            aria-labelledby="tag-color-label"
                            className="flex flex-wrap gap-2"
                        >
                            {colors.map((color) => (
                                <button
                                    key={color.value}
                                    type="button"
                                    role="radio"
                                    aria-checked={
                                        form.data.color === color.value
                                    }
                                    onClick={() =>
                                        form.setData('color', color.value)
                                    }
                                    className={cn(
                                        'inline-flex h-[30px] items-center gap-1.5 rounded-full border px-[10px] text-[12.5px] font-semibold',
                                        form.data.color === color.value
                                            ? 'border-primary bg-primary-soft text-primary'
                                            : 'border-input text-text-secondary bg-white',
                                    )}
                                >
                                    <TagSwatch color={color.value} />
                                    {color.label ??
                                        TAG_COLOR_LABELS[color.value]}
                                </button>
                            ))}
                        </div>
                        <InputError message={form.errors.color} />
                    </div>

                    <div className="flex items-center gap-2 text-[12.5px]">
                        <span className="text-muted-foreground">Prévia:</span>
                        <TagChip
                            tag={{
                                id: 'preview',
                                name: form.data.name.trim() || 'Etiqueta',
                                color: form.data.color,
                            }}
                        />
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={form.processing}
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing || !form.data.name.trim()}
                        >
                            {form.processing ? (
                                <Spinner />
                            ) : (
                                <TagIcon className="size-3.5" />
                            )}
                            {tag ? 'Salvar' : 'Criar etiqueta'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

SettingsTags.layout = {
    breadcrumbs: [
        { title: 'Configurações', href: settingsTags() },
        { title: 'Etiquetas', href: settingsTags() },
    ],
};
