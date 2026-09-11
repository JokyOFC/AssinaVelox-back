import {
    Check,
    ChevronDown,
    ChevronUp,
    Loader2,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { formatBytes, plural } from '@/lib/format';
import { documentProcessingLabels } from '@/lib/labels';
import { cn } from '@/lib/utils';
import type { EnvelopeDocument } from '@/types/models';

/** Selo curto do tipo de arquivo ("PDF", "DOCX", "IMG"). */
export function fileBadge(document: EnvelopeDocument): string {
    if (document.mime === 'application/pdf') {
        return 'PDF';
    }

    if (document.mime.includes('word')) {
        return 'DOCX';
    }

    if (document.mime.startsWith('image/')) {
        return 'IMG';
    }

    return 'ARQ';
}

/** Nome de exibição do arquivo (Fase 2 `name`; cai no nome original). */
export function documentName(document: {
    name?: string | null;
    original_name?: string | null;
}): string {
    return document.name?.trim() || document.original_name || 'Documento';
}

/**
 * Lista de arquivos do envelope no passo 1 (Fase 2 §2.3, flag `multi_document`): ordem
 * de apresentação com setas, estado do processamento por arquivo e remoção individual.
 *
 * A ordem aqui é a ordem em que os participantes veem os arquivos, a da página de
 * evidências e a da verificação pública — daí as setas explícitas em vez de só arrastar.
 */
export function WizardDocumentList({
    documents,
    onMove,
    onRemove,
    disabled,
}: {
    documents: EnvelopeDocument[];
    onMove: (from: number, to: number) => void;
    onRemove: (document: EnvelopeDocument) => void;
    disabled?: boolean;
}) {
    return (
        <ol className="flex flex-col gap-2" aria-label="Arquivos do documento">
            {documents.map((document, index) => {
                const processing = document.processing;
                const busy =
                    processing.status === 'uploaded' ||
                    processing.status === 'converting';
                const failed =
                    processing.status === 'failed' ||
                    processing.status === 'blocked';
                const name = documentName(document);

                return (
                    <li
                        key={document.id}
                        className={cn(
                            'flex flex-col gap-1.5 rounded-[10px] border p-3',
                            failed
                                ? 'border-danger-border bg-danger-bg'
                                : 'border-border',
                        )}
                    >
                        <div className="flex items-center gap-3">
                            <span className="bg-muted text-text-secondary tabular flex size-6 shrink-0 items-center justify-center rounded-md text-[11.5px] font-bold">
                                {index + 1}
                            </span>
                            <span className="bg-danger-bg text-danger flex size-9 shrink-0 items-center justify-center rounded-lg text-[10px] font-extrabold">
                                {fileBadge(document)}
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-[13.5px] font-semibold">
                                    {name}
                                </span>
                                <span className="text-muted-foreground tabular block truncate text-[12px]">
                                    {name !== document.original_name &&
                                        `${document.original_name} · `}
                                    {formatBytes(document.size_bytes)}
                                    {processing.pages != null &&
                                        ` · ${plural(processing.pages, 'página')}`}
                                </span>
                            </span>
                            <span
                                className={cn(
                                    'hidden shrink-0 items-center gap-1.5 text-[12px] font-semibold sm:inline-flex',
                                    processing.status === 'ready' &&
                                        'text-success',
                                    busy && 'text-primary',
                                    failed && 'text-danger',
                                )}
                            >
                                {processing.status === 'ready' && (
                                    <Check className="size-3.5 stroke-[2.5]" />
                                )}
                                {busy && (
                                    <Loader2 className="size-3.5 animate-spin" />
                                )}
                                {failed && (
                                    <TriangleAlert className="size-3.5" />
                                )}
                                {processing.label ??
                                    documentProcessingLabels[processing.status]}
                            </span>
                            <span className="flex shrink-0 items-center gap-0.5">
                                <Button
                                    variant="ghost"
                                    size="icon-xs"
                                    aria-label={`Mover ${name} para cima`}
                                    disabled={disabled || index === 0}
                                    onClick={() => onMove(index, index - 1)}
                                >
                                    <ChevronUp className="size-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon-xs"
                                    aria-label={`Mover ${name} para baixo`}
                                    disabled={
                                        disabled ||
                                        index === documents.length - 1
                                    }
                                    onClick={() => onMove(index, index + 1)}
                                >
                                    <ChevronDown className="size-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon-xs"
                                    aria-label={`Remover ${name}`}
                                    title="Remover"
                                    disabled={disabled}
                                    onClick={() => onRemove(document)}
                                    className="hover:bg-danger-bg hover:text-danger"
                                >
                                    <Trash2 className="size-[15px]" />
                                </Button>
                            </span>
                        </div>
                        {/* No celular o estado vai para uma linha própria. */}
                        <span
                            className={cn(
                                'text-[12px] font-semibold sm:hidden',
                                processing.status === 'ready' && 'text-success',
                                busy && 'text-primary',
                                failed && 'text-danger',
                            )}
                        >
                            {processing.label ??
                                documentProcessingLabels[processing.status]}
                        </span>
                        {failed && (
                            <p className="text-danger text-[12.5px] leading-[1.5]">
                                {processing.error ??
                                    (processing.status === 'blocked'
                                        ? 'Este arquivo está protegido por senha ou já contém assinatura digital e não pode receber campos. Remova-o e envie uma versão sem proteção.'
                                        : 'Falha ao processar o arquivo. Remova-o e envie um PDF válido.')}
                            </p>
                        )}
                    </li>
                );
            })}
        </ol>
    );
}
