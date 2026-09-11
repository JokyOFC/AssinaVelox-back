import { Link, router } from '@inertiajs/react';
import { Tag as TagIcon } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { plural } from '@/lib/format';
import { cn } from '@/lib/utils';
import { apply as applyTag } from '@/routes/envelopes/tags';
import { tags as settingsTags } from '@/routes/settings';
import { TagChip, type TagOption } from './tag-chip';

/**
 * Ação em lote "Adicionar etiqueta" (Documentos). O servidor aplica só nos
 * documentos que a pessoa pode editar e informa quantos foram ignorados.
 */
export function BulkTagDialog({
    open,
    onOpenChange,
    envelopeIds,
    available,
    canManage,
    onApplied,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    envelopeIds: string[];
    available: TagOption[];
    canManage: boolean;
    onApplied?: () => void;
}) {
    const [selected, setSelected] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const submit = () => {
        if (!selected) {
            return;
        }

        setBusy(true);
        router.post(
            applyTag.url(),
            { tag: selected, ids: envelopeIds },
            {
                preserveScroll: true,
                onSuccess: () => {
                    onApplied?.();
                    onOpenChange(false);
                    setSelected(null);
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        Adicionar etiqueta a{' '}
                        {plural(envelopeIds.length, 'documento')}
                    </DialogTitle>
                    <DialogDescription>
                        Etiquetas organizam documentos e não alteram status nem
                        assinaturas. Documentos que você não pode editar são
                        ignorados.
                    </DialogDescription>
                </DialogHeader>

                {available.length === 0 ? (
                    <div className="text-muted-foreground flex flex-col items-start gap-2 text-[13px]">
                        <p>Esta conta ainda não tem etiquetas.</p>
                        {canManage && (
                            <Button asChild variant="outline" size="sm">
                                <Link href={settingsTags()}>
                                    <TagIcon className="size-3.5" />
                                    Criar etiquetas
                                </Link>
                            </Button>
                        )}
                    </div>
                ) : (
                    <div
                        role="radiogroup"
                        aria-label="Etiqueta"
                        className="flex max-h-[260px] flex-wrap gap-2 overflow-y-auto"
                    >
                        {available.map((tag) => (
                            <button
                                key={tag.id}
                                type="button"
                                role="radio"
                                aria-checked={selected === tag.id}
                                onClick={() => setSelected(tag.id)}
                                className={cn(
                                    'rounded-lg border-2 p-0.5 transition-colors',
                                    selected === tag.id
                                        ? 'border-primary'
                                        : 'border-transparent',
                                )}
                            >
                                <TagChip tag={tag} />
                            </button>
                        ))}
                    </div>
                )}

                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={busy}
                    >
                        Cancelar
                    </Button>
                    <Button onClick={submit} disabled={busy || !selected}>
                        {busy && <Spinner />}
                        Adicionar etiqueta
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
