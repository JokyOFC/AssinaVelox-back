import { Head, Link, router, usePoll } from '@inertiajs/react';
import { ArrowLeft, Check, Download, Trash2 } from 'lucide-react';
import { useEffect } from 'react';
import { ConfirmPanel } from '@/components/bulk-generations/confirm-panel';
import type {
    ConfirmOptions,
    QuotaInfo,
} from '@/components/bulk-generations/confirm-panel';
import { MappingForm } from '@/components/bulk-generations/mapping-form';
import { ProgressPanel } from '@/components/bulk-generations/progress-panel';
import { RowsTable } from '@/components/bulk-generations/rows-table';
import type { RowsPage } from '@/components/bulk-generations/rows-table';
import { BulkStatusBadge } from '@/components/bulk-generations/status-badge';
import type {
    BulkCounts,
    BulkDetail,
    BulkHeader,
    BulkTarget,
} from '@/components/bulk-generations/types';
import { PageHeader } from '@/components/page-header';
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
import { formatDateTime, formatNumber, plural } from '@/lib/format';
import { cn } from '@/lib/utils';
import { destroy, index as bulkIndex, report } from '@/routes/bulk_generations';

interface BulkShowProps {
    batch: BulkDetail;
    template: {
        id: string;
        name: string;
        source_label: string;
        version: number;
        usable: boolean;
    };
    headers: BulkHeader[];
    mapping: Record<string, string>;
    targets: BulkTarget[];
    counts: BulkCounts;
    rows: RowsPage;
    quota: QuotaInfo;
    options: ConfirmOptions;
    can: { manage: boolean; cancel: boolean };
}

const STEPS = ['Arquivo', 'Colunas', 'Pré-validação', 'Confirmação', 'Geração'];

/**
 * Detalhe do lote (Fase 3 §3.1): mapeamento de colunas → pré-validação com o
 * relatório por linha → confirmação com a cota → acompanhamento (atualiza
 * sozinho enquanto gera).
 */
export default function BulkGenerationShow({
    batch,
    template,
    headers,
    mapping,
    targets,
    counts,
    rows,
    quota,
    options,
    can,
}: BulkShowProps) {
    const running = batch.status === 'running';
    const editable = batch.status === 'draft' || batch.status === 'validated';

    const { start, stop } = usePoll(
        2500,
        { only: ['batch', 'counts', 'rows', 'can'] },
        { autoStart: running },
    );

    useEffect(() => {
        if (running) {
            start();
        } else {
            stop();
        }
    }, [running, start, stop]);

    const step =
        batch.status === 'draft' ? 1 : batch.status === 'validated' ? 3 : 4;

    return (
        <>
            <Head title={`Lote · ${template.name}`} />
            <PageHeader
                size="detail"
                leading={
                    <Button
                        asChild
                        variant="outline"
                        size="icon"
                        aria-label="Voltar para os lotes"
                    >
                        <Link href={bulkIndex.url()}>
                            <ArrowLeft />
                        </Link>
                    </Button>
                }
                eyebrow="Geração em lote"
                title={template.name}
                badge={
                    <BulkStatusBadge
                        status={batch.status}
                        label={batch.status_label}
                    />
                }
                subtitle={`${batch.source_filename} · ${plural(batch.row_count, 'linha', 'linhas')} · versão ${template.version} do modelo${batch.created_at ? ` · enviado em ${formatDateTime(batch.created_at)}` : ''}`}
                actions={
                    <div className="flex flex-wrap gap-2">
                        {batch.status !== 'draft' && (
                            <Button asChild variant="outline">
                                <a href={report.url(batch.id)}>
                                    <Download className="size-4" />
                                    Relatório (CSV)
                                </a>
                            </Button>
                        )}
                        {editable && can.manage && (
                            <DiscardButton batchId={batch.id} />
                        )}
                    </div>
                }
            />

            <ol className="flex flex-wrap gap-x-5 gap-y-2 text-[12.5px]">
                {STEPS.map((label, index) => (
                    <li
                        key={label}
                        className={cn(
                            'flex items-center gap-1.5',
                            index < step
                                ? 'text-success'
                                : index === step
                                  ? 'text-foreground font-semibold'
                                  : 'text-muted-foreground',
                        )}
                    >
                        <span
                            className={cn(
                                'flex size-5 items-center justify-center rounded-full border text-[11px]',
                                index < step
                                    ? 'border-success-border bg-success-bg'
                                    : index === step
                                      ? 'border-primary'
                                      : 'border-border',
                            )}
                        >
                            {index < step ? (
                                <Check className="size-3" />
                            ) : (
                                index + 1
                            )}
                        </span>
                        {label}
                    </li>
                ))}
            </ol>

            {!template.usable && editable && (
                <div className="border-warning-border bg-warning-bg text-warning rounded-lg border p-3 text-[13px]">
                    O modelo foi arquivado. Restaure-o em Modelos para gerar
                    este lote.
                </div>
            )}

            {editable && batch.discard_after_days !== undefined && (
                <p className="text-muted-foreground text-[12.5px] leading-[1.5]">
                    Lote não confirmado é descartado automaticamente — planilha
                    e resultado da validação — depois de{' '}
                    {plural(batch.discard_after_days, 'dia', 'dias')} sem
                    alteração.
                </p>
            )}

            {editable && can.manage && (
                <MappingForm
                    key={batch.dry_run_at ?? 'draft'}
                    batchId={batch.id}
                    headers={headers}
                    targets={targets}
                    mapping={mapping}
                    revalidate={batch.status === 'validated'}
                />
            )}

            {batch.status === 'validated' && (
                <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_380px]">
                    <section className="border-border bg-card flex flex-col gap-3 rounded-xl border p-5">
                        <h2 className="text-[14px] font-semibold">
                            Resultado da pré-validação
                        </h2>
                        <div className="grid grid-cols-3 gap-3">
                            <Tile label="Linhas" value={counts.rows} />
                            <Tile
                                label="Válidas"
                                value={counts.valid}
                                tone="text-success"
                            />
                            <Tile
                                label="Com erro"
                                value={counts.invalid}
                                tone={
                                    counts.invalid > 0
                                        ? 'text-danger'
                                        : undefined
                                }
                            />
                        </div>
                        <p className="text-text-secondary text-[12.5px]">
                            Nenhum documento foi criado e nenhuma cota foi
                            usada. Corrija a planilha e envie de novo, ou gere
                            só as linhas válidas.
                            {batch.dry_run_at &&
                                ` Validado em ${formatDateTime(batch.dry_run_at)}.`}
                        </p>
                    </section>
                    {can.manage && (
                        <ConfirmPanel
                            batchId={batch.id}
                            counts={counts}
                            quota={quota}
                            options={options}
                        />
                    )}
                </div>
            )}

            {!editable && (
                <ProgressPanel
                    batch={batch}
                    counts={counts}
                    canCancel={can.cancel}
                />
            )}

            {batch.status !== 'draft' && (
                <RowsTable batchId={batch.id} rows={rows} />
            )}

            {batch.status === 'draft' && (
                <p className="text-muted-foreground text-[12.5px]">
                    {formatNumber(batch.row_count)} linhas de dados encontradas.
                    Valide para ver o resultado de cada linha.
                </p>
            )}
        </>
    );
}

BulkGenerationShow.layout = {
    breadcrumbs: [{ title: 'Geração em lote', href: bulkIndex() }],
};

function Tile({
    label,
    value,
    tone,
}: {
    label: string;
    value: number;
    tone?: string;
}) {
    return (
        <div className="border-border rounded-lg border px-3 py-2">
            <p className="text-muted-foreground text-[11.5px]">{label}</p>
            <p className={cn('tabular text-[20px] font-semibold', tone)}>
                {formatNumber(value)}
            </p>
        </div>
    );
}

function DiscardButton({ batchId }: { batchId: string }) {
    return (
        <AlertDialog>
            <AlertDialogTrigger asChild>
                <Button variant="outline">
                    <Trash2 className="size-4" />
                    Descartar
                </Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Descartar este lote?</AlertDialogTitle>
                    <AlertDialogDescription>
                        A planilha e o resultado da validação são apagados.
                        Nenhum documento foi criado ainda.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Voltar</AlertDialogCancel>
                    <AlertDialogAction
                        onClick={() => router.delete(destroy.url(batchId))}
                    >
                        Descartar lote
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
