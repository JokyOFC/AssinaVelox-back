import { Link, router } from '@inertiajs/react';
import { FileText, LayoutTemplate, Search } from 'lucide-react';
import type { JSX } from 'react';
import { useEffect, useState } from 'react';
import { Spinner } from '@/components/ui/spinner';
import { plural } from '@/lib/format';
import { create as envelopesCreate } from '@/routes/envelopes';
import { index as templatesIndex, picker } from '@/routes/templates';
import type { TemplatePickerItem } from './types';

type PickerState =
    | { status: 'loading' }
    | { status: 'ready'; templates: TemplatePickerItem[]; canUse: boolean }
    | { status: 'unavailable' }
    | { status: 'error' };

/**
 * Lista curta de modelos da organização com busca (Fase 2 §2.1). Ao escolher,
 * chama `onPicked` (opcional) e navega para `envelopes.create?template={ulid}`,
 * que abre o formulário "Usar modelo".
 *
 * Os dados vêm de `templates.picker` (JSON). Com a flag `templates` desligada a
 * rota responde 404 e o componente mostra só um aviso discreto — quem o usa
 * (card "Ou comece por um modelo" do wizard) deve renderizá-lo apenas com
 * `features.templates` ligado.
 */
export function TemplatePicker(props: {
    onPicked?: (templateUlid: string) => void;
}): JSX.Element {
    const { onPicked } = props;
    const [query, setQuery] = useState('');
    const [state, setState] = useState<PickerState>({ status: 'loading' });

    useEffect(() => {
        const controller = new AbortController();
        const timer = window.setTimeout(
            () => {
                const url = picker.url({
                    query: query.trim() ? { q: query.trim() } : {},
                });

                fetch(url, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    signal: controller.signal,
                })
                    .then(async (response) => {
                        if (response.status === 404) {
                            setState({ status: 'unavailable' });

                            return;
                        }

                        if (!response.ok) {
                            setState({ status: 'error' });

                            return;
                        }

                        const data = (await response.json()) as {
                            templates: TemplatePickerItem[];
                            can_use: boolean;
                        };

                        setState({
                            status: 'ready',
                            templates: data.templates,
                            canUse: data.can_use,
                        });
                    })
                    .catch((error: unknown) => {
                        if (
                            error instanceof DOMException &&
                            error.name === 'AbortError'
                        ) {
                            return;
                        }

                        setState({ status: 'error' });
                    });
            },
            query ? 250 : 0,
        );

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [query]);

    const pick = (id: string): void => {
        onPicked?.(id);
        router.visit(envelopesCreate.url({ query: { template: id } }));
    };

    if (state.status === 'unavailable') {
        return (
            <p className="text-muted-foreground text-[13px]">
                Modelos não estão disponíveis no plano desta conta.
            </p>
        );
    }

    return (
        <div className="flex flex-col gap-3" data-testid="template-picker">
            <label className="border-input text-muted-foreground focus-within:border-primary focus-within:ring-primary/18 flex h-[34px] items-center gap-2 rounded-lg border bg-white px-[10px] focus-within:ring-[3px]">
                <Search className="size-[15px] shrink-0" />
                <input
                    type="search"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder="Buscar modelo por nome ou categoria"
                    aria-label="Buscar modelo"
                    className="text-foreground placeholder:text-muted-foreground min-w-0 flex-1 border-0 bg-transparent text-[13.5px] outline-none"
                />
            </label>

            {state.status === 'loading' && (
                <div className="text-muted-foreground flex items-center gap-2 py-3 text-[13px]">
                    <Spinner className="size-4" /> Carregando modelos…
                </div>
            )}

            {state.status === 'error' && (
                <p className="text-danger text-[13px]">
                    Não foi possível carregar os modelos. Tente de novo.
                </p>
            )}

            {state.status === 'ready' && state.templates.length === 0 && (
                <div className="text-muted-foreground py-2 text-[13px]">
                    {query
                        ? 'Nenhum modelo encontrado para essa busca.'
                        : 'Nenhum modelo cadastrado ainda.'}{' '}
                    <Link
                        href={templatesIndex.url()}
                        className="text-primary font-semibold hover:underline"
                    >
                        Ver modelos
                    </Link>
                </div>
            )}

            {state.status === 'ready' && state.templates.length > 0 && (
                <ul className="flex flex-col gap-1.5">
                    {state.templates.map((template) => (
                        <li key={template.id}>
                            <button
                                type="button"
                                disabled={!state.canUse}
                                onClick={() => pick(template.id)}
                                className="border-border hover:border-primary/40 hover:bg-accent-subtle flex w-full items-center gap-3 rounded-lg border bg-white px-3 py-2.5 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                <span className="bg-primary-soft text-primary flex size-8 shrink-0 items-center justify-center rounded-md">
                                    {template.source_type === 'html' ? (
                                        <LayoutTemplate className="size-4" />
                                    ) : (
                                        <FileText className="size-4" />
                                    )}
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="text-foreground block truncate text-[13.5px] font-semibold">
                                        {template.name}
                                    </span>
                                    <span className="text-muted-foreground block truncate text-[12px]">
                                        {[
                                            template.category,
                                            template.source_label,
                                            plural(
                                                template.roles_count,
                                                'participante',
                                            ),
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {state.status === 'ready' && !state.canUse && (
                <p className="text-muted-foreground text-[12px]">
                    Sua função não permite criar documentos.
                </p>
            )}

            {state.status === 'ready' && state.templates.length > 0 && (
                <Link
                    href={templatesIndex.url()}
                    className="text-primary self-start text-[12.5px] font-semibold hover:underline"
                >
                    Ver todos os modelos
                </Link>
            )}
        </div>
    );
}
