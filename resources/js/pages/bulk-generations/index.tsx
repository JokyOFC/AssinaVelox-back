import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Layers } from 'lucide-react';
import { BulkStatusBadge } from '@/components/bulk-generations/status-badge';
import type { BulkSummary } from '@/components/bulk-generations/types';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { formatDateTime, plural } from '@/lib/format';
import { index as bulkIndex, show } from '@/routes/bulk_generations';
import { index as templatesIndex } from '@/routes/templates';

interface BulkGenerationsIndexProps {
    batches: BulkSummary[];
    pagination: { current_page: number; last_page: number; total: number };
}

/**
 * Lista de lotes de geração (Fase 3 §3.1). Quem vê todos os documentos vê
 * todos os lotes; os demais, só os próprios.
 */
export default function BulkGenerationsIndex({
    batches,
    pagination,
}: BulkGenerationsIndexProps) {
    const goTo = (page: number) =>
        router.get(
            bulkIndex.url({ query: { page } }),
            {},
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Geração em lote" />
            <PageHeader
                title="Geração em lote"
                subtitle="Documentos gerados a partir de um modelo e de uma planilha: um documento por linha."
                actions={
                    <Button asChild variant="outline">
                        <Link href={templatesIndex()}>Escolher um modelo</Link>
                    </Button>
                }
            />

            {batches.length === 0 ? (
                <EmptyState
                    icon={Layers}
                    title="Nenhum lote ainda"
                    description="Em Modelos, use “Gerar em lote” no modelo desejado e envie a planilha com um documento por linha."
                    action={
                        <Button asChild>
                            <Link href={templatesIndex()}>Ir para Modelos</Link>
                        </Button>
                    }
                />
            ) : (
                <div className="border-border bg-card overflow-hidden rounded-xl border">
                    <ul className="divide-border divide-y">
                        {batches.map((batch) => (
                            <BatchItem key={batch.id} batch={batch} />
                        ))}
                    </ul>
                </div>
            )}

            {pagination.last_page > 1 && (
                <div className="text-text-secondary flex items-center justify-end gap-2 text-[12.5px]">
                    <span>
                        Página {pagination.current_page} de{' '}
                        {pagination.last_page}
                    </span>
                    <Button
                        variant="outline"
                        size="icon-sm"
                        aria-label="Página anterior"
                        disabled={pagination.current_page <= 1}
                        onClick={() => goTo(pagination.current_page - 1)}
                    >
                        <ChevronLeft />
                    </Button>
                    <Button
                        variant="outline"
                        size="icon-sm"
                        aria-label="Próxima página"
                        disabled={
                            pagination.current_page >= pagination.last_page
                        }
                        onClick={() => goTo(pagination.current_page + 1)}
                    >
                        <ChevronRight />
                    </Button>
                </div>
            )}
        </>
    );
}

BulkGenerationsIndex.layout = {
    breadcrumbs: [{ title: 'Geração em lote', href: bulkIndex() }],
};

function BatchItem({ batch }: { batch: BulkSummary }) {
    const total = batch.valid_count || batch.row_count;
    const percent = total > 0 ? Math.round((batch.processed / total) * 100) : 0;

    return (
        <li className="flex flex-col gap-3 p-4 md:flex-row md:items-center md:gap-5 md:px-5">
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <Link
                        href={show(batch.id)}
                        className="hover:text-primary truncate text-[14.5px] font-semibold"
                    >
                        {batch.template?.name ?? 'Modelo'}
                    </Link>
                    <BulkStatusBadge
                        status={batch.status}
                        label={batch.status_label}
                    />
                </div>
                <p className="text-text-secondary mt-1 truncate text-[12.5px]">
                    {batch.source_filename} ·{' '}
                    {plural(batch.row_count, 'linha', 'linhas')}
                    {batch.created_by && ` · por ${batch.created_by}`}
                    {batch.created_at &&
                        ` · ${formatDateTime(batch.created_at)}`}
                </p>
                {batch.status === 'running' && (
                    <Progress
                        value={percent}
                        className="mt-2 max-w-sm"
                        aria-label={`${percent}% gerado`}
                    />
                )}
            </div>
            <div className="text-text-secondary flex flex-wrap items-center gap-4 text-[12.5px]">
                <span>
                    {plural(
                        batch.created_count,
                        'documento gerado',
                        'documentos gerados',
                    )}
                </span>
                {batch.failed_count > 0 && (
                    <span className="text-danger">
                        {plural(batch.failed_count, 'falha', 'falhas')}
                    </span>
                )}
                {batch.invalid_count > 0 && batch.status === 'validated' && (
                    <span className="text-warning">
                        {plural(
                            batch.invalid_count,
                            'linha com erro',
                            'linhas com erro',
                        )}
                    </span>
                )}
                <Button asChild variant="outline" size="sm">
                    <Link href={show(batch.id)}>Abrir</Link>
                </Button>
            </div>
        </li>
    );
}
