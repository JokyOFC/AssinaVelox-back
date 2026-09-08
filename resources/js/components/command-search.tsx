import { router } from '@inertiajs/react';
import { FileText, Search, UserRound } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { EnvelopeStatusBadge } from '@/components/status/envelope-status-badge';
import { Kbd } from '@/components/kbd';
import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
} from '@/components/ui/command';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { show as showEnvelope } from '@/routes/envelopes';
import { index as searchIndex } from '@/routes/search';
import type { EnvelopeStatus } from '@/types';

type SearchEnvelope = {
    id: string;
    display_code: string;
    title: string;
    status: EnvelopeStatus;
    status_label?: string;
    signed_count?: number;
    folder?: string | null;
};

type SearchRecipient = {
    id: string;
    envelope_id: string;
    name: string;
    email: string;
    envelope_title: string;
};

type SearchResponse = {
    envelopes: SearchEnvelope[];
    recipients: SearchRecipient[];
};

const EMPTY: SearchResponse = { envelopes: [], recipients: [] };

/**
 * Busca global ⌘K (ROUTES §5.3): consulta `search.index` com debounce de
 * 250 ms; grupos "Documentos" e "Signatários"; Enter abre `envelopes.show`.
 */
export function CommandSearch({ className }: { className?: string }) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<SearchResponse>(EMPTY);
    const [loading, setLoading] = useState(false);
    const abortRef = useRef<AbortController | null>(null);

    useEffect(() => {
        const handler = (event: KeyboardEvent) => {
            if (
                (event.metaKey || event.ctrlKey) &&
                event.key.toLowerCase() === 'k'
            ) {
                event.preventDefault();
                setOpen((prev) => !prev);
            }
        };

        window.addEventListener('keydown', handler);

        return () => window.removeEventListener('keydown', handler);
    }, []);

    useEffect(() => {
        if (!open) {
            return;
        }

        const term = query.trim();

        if (term.length < 2) {
            setResults(EMPTY);
            setLoading(false);

            return;
        }

        setLoading(true);

        const timer = window.setTimeout(async () => {
            abortRef.current?.abort();
            const controller = new AbortController();
            abortRef.current = controller;

            try {
                const response = await fetch(
                    searchIndex.url({ query: { q: term } }),
                    {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                        signal: controller.signal,
                    },
                );

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const data = (await response.json()) as Partial<SearchResponse>;
                setResults({
                    envelopes: data.envelopes ?? [],
                    recipients: data.recipients ?? [],
                });
            } catch (error) {
                if ((error as Error).name !== 'AbortError') {
                    setResults(EMPTY);
                }
            } finally {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            }
        }, 250);

        return () => window.clearTimeout(timer);
    }, [query, open]);

    const openEnvelope = (id: string) => {
        setOpen(false);
        setQuery('');
        router.visit(showEnvelope(id).url);
    };

    const hasResults =
        results.envelopes.length > 0 || results.recipients.length > 0;

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                aria-label="Buscar (⌘K)"
                className={cn(
                    'border-input text-muted-foreground hover:bg-accent-subtle flex h-[34px] w-[clamp(160px,24vw,260px)] items-center gap-2 rounded-lg border bg-white px-[10px] transition-colors',
                    className,
                )}
            >
                <Search className="size-[15px] shrink-0" />
                <span className="min-w-0 flex-1 truncate text-left text-[13.5px]">
                    Buscar...
                </span>
                <Kbd>⌘K</Kbd>
            </button>

            <CommandDialog
                open={open}
                onOpenChange={setOpen}
                title="Busca global"
                description="Busque documentos e signatários"
                commandProps={{ shouldFilter: false }}
            >
                <CommandInput
                    value={query}
                    onValueChange={setQuery}
                    placeholder="Buscar documentos, signatários ou códigos AV-…"
                />
                <CommandList>
                    {loading && (
                        <div className="text-muted-foreground flex items-center justify-center gap-2 py-6 text-[13px]">
                            <Spinner className="size-4" /> Buscando…
                        </div>
                    )}
                    {!loading && query.trim().length < 2 && (
                        <p className="text-muted-foreground px-4 py-6 text-center text-[13px]">
                            Digite ao menos 2 caracteres para buscar.
                        </p>
                    )}
                    {!loading && query.trim().length >= 2 && !hasResults && (
                        <CommandEmpty>
                            Nenhum resultado para “{query}”.
                        </CommandEmpty>
                    )}
                    {!loading && results.envelopes.length > 0 && (
                        <CommandGroup heading="Documentos">
                            {results.envelopes.map((envelope) => (
                                <CommandItem
                                    key={envelope.id}
                                    value={`env-${envelope.id}`}
                                    onSelect={() => openEnvelope(envelope.id)}
                                    className="gap-3"
                                >
                                    <span className="bg-accent-subtle text-primary flex size-8 shrink-0 items-center justify-center rounded-lg">
                                        <FileText className="size-4" />
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-semibold">
                                            {envelope.title}
                                        </span>
                                        <span className="text-muted-foreground tabular block truncate text-[12px]">
                                            {envelope.display_code}
                                            {envelope.folder &&
                                                ` · ${envelope.folder}`}
                                        </span>
                                    </span>
                                    <EnvelopeStatusBadge
                                        status={envelope.status}
                                        signedCount={envelope.signed_count}
                                        label={envelope.status_label}
                                    />
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    )}
                    {!loading &&
                        results.envelopes.length > 0 &&
                        results.recipients.length > 0 && <CommandSeparator />}
                    {!loading && results.recipients.length > 0 && (
                        <CommandGroup heading="Signatários">
                            {results.recipients.map((recipient) => (
                                <CommandItem
                                    key={recipient.id}
                                    value={`rec-${recipient.id}`}
                                    onSelect={() =>
                                        openEnvelope(recipient.envelope_id)
                                    }
                                    className="gap-3"
                                >
                                    <span className="bg-warning-bg text-warning flex size-8 shrink-0 items-center justify-center rounded-lg">
                                        <UserRound className="size-4" />
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-semibold">
                                            {recipient.name}
                                        </span>
                                        <span className="text-muted-foreground block truncate text-[12px]">
                                            {recipient.email} ·{' '}
                                            {recipient.envelope_title}
                                        </span>
                                    </span>
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    )}
                </CommandList>
            </CommandDialog>
        </>
    );
}
