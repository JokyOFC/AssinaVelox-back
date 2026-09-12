import { useState } from 'react';
import { CopyButton } from '@/components/copy-button';
import { cn } from '@/lib/utils';

export type CodeSample = { key: string; label: string; code: string };

/**
 * Bloco de código escuro (DESIGN §4.24): cabeçalho com rótulo, alternância de
 * linguagem opcional e botão copiar. Texto puro — sem realce de sintaxe.
 */
export function CodeBlock({
    label,
    samples,
    className,
}: {
    label: string;
    samples: CodeSample[];
    className?: string;
}) {
    const [active, setActive] = useState(samples[0]?.key ?? '');
    const current =
        samples.find((sample) => sample.key === active) ?? samples[0];

    return (
        <div
            className={cn(
                'bg-navy min-w-0 overflow-hidden rounded-xl',
                className,
            )}
        >
            <div className="text-on-navy-subtle flex items-center justify-between gap-2 border-b border-white/[.08] py-2 pr-2 pl-3.5 text-[12px] font-semibold">
                <span className="truncate">{label}</span>
                <div className="flex items-center gap-1.5">
                    {samples.length > 1 && (
                        <div
                            role="tablist"
                            aria-label="Linguagem do exemplo"
                            className="inline-flex gap-0.5 rounded-md bg-white/[.06] p-0.5"
                        >
                            {samples.map((sample) => (
                                <button
                                    key={sample.key}
                                    type="button"
                                    role="tab"
                                    aria-selected={sample.key === current?.key}
                                    onClick={() => setActive(sample.key)}
                                    className={cn(
                                        'rounded-[5px] px-2.5 py-[3px] text-[12px] font-semibold',
                                        sample.key === current?.key
                                            ? 'text-foreground bg-white'
                                            : 'text-on-navy-subtle hover:text-white',
                                    )}
                                >
                                    {sample.label}
                                </button>
                            ))}
                        </div>
                    )}
                    {current && (
                        <CopyButton
                            value={current.code}
                            label="Copiar exemplo"
                            className="text-on-navy-subtle hover:bg-white/10 hover:text-white"
                        />
                    )}
                </div>
            </div>
            <pre className="text-code-text m-0 overflow-x-auto p-4 font-mono text-[12.5px] leading-[1.6]">
                {current?.code}
            </pre>
        </div>
    );
}

/** Resposta de exemplo (fundo claro, DESIGN §4.24). */
export function ResponseBlock({
    status,
    label = 'Resposta',
    code,
    tone = 'success',
}: {
    status: string;
    label?: string;
    code: string;
    tone?: 'success' | 'danger';
}) {
    return (
        <div className="border-border bg-background min-w-0 overflow-hidden rounded-xl border">
            <div className="text-muted-foreground border-border flex items-center justify-between border-b px-3.5 py-2 text-[12px]">
                <span>{label}</span>
                <span
                    className={cn(
                        'font-bold',
                        tone === 'success' ? 'text-success' : 'text-danger',
                    )}
                >
                    {status}
                </span>
            </div>
            <pre className="text-foreground m-0 overflow-x-auto px-4 py-3.5 font-mono text-[12.5px] leading-[1.6]">
                {code}
            </pre>
        </div>
    );
}

/** Código em linha (`bg-muted`), usado na prosa da documentação. */
export function InlineCode({ children }: { children: string }) {
    return (
        <code className="bg-muted rounded px-[5px] py-px font-mono text-[12.5px]">
            {children}
        </code>
    );
}
