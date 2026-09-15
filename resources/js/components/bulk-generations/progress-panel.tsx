import { router } from '@inertiajs/react';
import { Ban } from 'lucide-react';
import { useState } from 'react';
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
import { Progress } from '@/components/ui/progress';
import { formatNumber } from '@/lib/format';
import { cancel } from '@/routes/bulk_generations';
import type { BulkCounts, BulkDetail } from './types';

/**
 * Acompanhamento do lote confirmado: contagens por destino, barra de progresso
 * e o cancelamento (que para só as linhas que ainda não viraram documento).
 */
export function ProgressPanel({
    batch,
    counts,
    canCancel,
}: {
    batch: BulkDetail;
    counts: BulkCounts;
    canCancel: boolean;
}) {
    const [busy, setBusy] = useState(false);
    const total = counts.valid;
    const percent =
        total > 0
            ? Math.min(100, Math.round((counts.processed / total) * 100))
            : 0;

    const tiles: { label: string; value: number; tone?: string }[] = [
        { label: 'Gerados', value: counts.created },
        { label: 'Enviados', value: counts.sent },
        { label: 'Agendados', value: counts.scheduled },
        { label: 'Para revisar', value: counts.ready + counts.draft },
        {
            label: 'Gerados, não enviados',
            value: counts.not_sent,
            tone: counts.not_sent > 0 ? 'text-warning' : undefined,
        },
        {
            label: 'Falhas',
            value: counts.failed,
            tone: counts.failed > 0 ? 'text-danger' : undefined,
        },
        { label: 'Canceladas', value: counts.canceled },
        { label: 'Aguardando', value: counts.pending },
    ];

    const doCancel = () => {
        setBusy(true);
        router.post(
            cancel.url(batch.id),
            {},
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    return (
        <section className="border-border bg-card flex flex-col gap-4 rounded-xl border p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-[14px] font-semibold">
                        {batch.status === 'running'
                            ? 'Gerando os documentos'
                            : batch.status === 'canceled'
                              ? 'Lote cancelado'
                              : 'Lote concluído'}
                    </h2>
                    <p className="text-text-secondary text-[12.5px]">
                        {batch.mode_label}
                        {batch.scheduled_for_label &&
                            ` · ${batch.scheduled_for_label}`}
                    </p>
                </div>
                {canCancel && (
                    <AlertDialog>
                        <AlertDialogTrigger asChild>
                            <Button variant="outline" size="sm" disabled={busy}>
                                <Ban className="size-4" />
                                Cancelar lote
                            </Button>
                        </AlertDialogTrigger>
                        <AlertDialogContent>
                            <AlertDialogHeader>
                                <AlertDialogTitle>
                                    Cancelar este lote?
                                </AlertDialogTitle>
                                <AlertDialogDescription>
                                    As linhas que ainda não viraram documento
                                    param e a cota delas volta. Documentos já
                                    gerados, enviados ou agendados continuam
                                    como estão.
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                                <AlertDialogCancel>Voltar</AlertDialogCancel>
                                <AlertDialogAction onClick={doCancel}>
                                    Cancelar lote
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </AlertDialogContent>
                    </AlertDialog>
                )}
            </div>

            <div>
                <div className="text-text-secondary mb-1.5 flex justify-between text-[12.5px]">
                    <span>Progresso</span>
                    <span className="tabular">
                        <b className="text-foreground">
                            {formatNumber(counts.processed)}
                        </b>{' '}
                        / {formatNumber(total)}
                    </span>
                </div>
                <Progress
                    value={percent}
                    aria-label={`${percent}% processado`}
                />
            </div>

            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                {tiles.map((tile) => (
                    <div
                        key={tile.label}
                        className="border-border rounded-lg border px-3 py-2"
                    >
                        <dt className="text-muted-foreground text-[11.5px]">
                            {tile.label}
                        </dt>
                        <dd
                            className={`tabular text-[18px] font-semibold ${tile.tone ?? ''}`}
                        >
                            {formatNumber(tile.value)}
                        </dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}
