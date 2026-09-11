import { Check, FileText } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export interface DocumentSwitcherItem {
    id: string;
    position: number;
    name: string;
    /** Linha secundária ("3 campos", "2 pendentes", "Conferido"). */
    meta?: ReactNode;
    /** `done` mostra o check verde; `attention`, a linha secundária em âmbar. */
    tone?: 'default' | 'done' | 'attention';
}

/**
 * Navegação entre os arquivos de um envelope com vários documentos (Fase 2 §2.3).
 *
 * Usada no editor de campos (passo 3), no detalhe do documento e na página pública.
 * Rola na horizontal no celular em vez de quebrar em várias linhas, para o documento
 * continuar logo abaixo. Com um arquivo só, quem usa não renderiza o componente — a tela
 * fica idêntica à da Fase 1.
 */
export function DocumentSwitcher({
    items,
    current,
    onSelect,
    label = 'Arquivos deste envelope',
    className,
}: {
    items: DocumentSwitcherItem[];
    current: string | null;
    onSelect: (id: string) => void;
    label?: string;
    className?: string;
}) {
    return (
        <nav aria-label={label} className={cn('min-w-0', className)}>
            <p className="text-muted-foreground mb-1.5 text-[12px] font-semibold">
                {label} ({items.length})
            </p>
            <div className="flex gap-2 overflow-x-auto pb-1">
                {items.map((item) => {
                    const active = item.id === current;

                    return (
                        <button
                            key={item.id}
                            type="button"
                            aria-pressed={active}
                            aria-current={active ? 'page' : undefined}
                            onClick={() => onSelect(item.id)}
                            className={cn(
                                'focus-ring flex max-w-[240px] min-w-[150px] shrink-0 items-start gap-2 rounded-[10px] border bg-white px-3 py-2 text-left transition-colors',
                                active
                                    ? 'border-primary bg-accent-subtle'
                                    : 'border-border hover:border-primary',
                            )}
                        >
                            <span
                                className={cn(
                                    'tabular mt-px flex size-5 shrink-0 items-center justify-center rounded-md text-[11px] font-bold',
                                    item.tone === 'done'
                                        ? 'bg-success-bg text-success'
                                        : active
                                          ? 'bg-primary text-white'
                                          : 'bg-muted text-text-secondary',
                                )}
                            >
                                {item.tone === 'done' ? (
                                    <Check className="size-3 stroke-[3]" />
                                ) : (
                                    item.position
                                )}
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="flex items-center gap-1 text-[12.5px] font-semibold">
                                    <FileText className="text-muted-foreground size-3 shrink-0" />
                                    <span className="truncate">
                                        {item.name}
                                    </span>
                                </span>
                                {item.meta && (
                                    <span
                                        className={cn(
                                            'block truncate text-[11.5px]',
                                            item.tone === 'attention'
                                                ? 'text-warning font-semibold'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {item.meta}
                                    </span>
                                )}
                            </span>
                        </button>
                    );
                })}
            </div>
        </nav>
    );
}
