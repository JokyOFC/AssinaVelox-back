import { useForm } from '@inertiajs/react';
import { ListChecks } from 'lucide-react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { mapping as mappingRoute } from '@/routes/bulk_generations';
import type { BulkHeader, BulkTarget } from './types';

const GROUPS = ['Documento', 'Participantes', 'Variáveis'];

/**
 * Mapeamento "coluna da planilha → campo do modelo" com a sugestão do servidor
 * já preenchida. Enviar roda a pré-validação (nada é criado).
 */
export function MappingForm({
    batchId,
    headers,
    targets,
    mapping,
    revalidate,
}: {
    batchId: string;
    headers: BulkHeader[];
    targets: BulkTarget[];
    mapping: Record<string, string>;
    /** true quando já existe uma pré-validação (o botão vira "Validar de novo"). */
    revalidate: boolean;
}) {
    const form = useForm<{ mapping: Record<string, string> }>({
        mapping: Object.fromEntries(
            headers.map((header) => [
                String(header.column),
                mapping[String(header.column)] ?? '',
            ]),
        ),
    });

    const errors = form.errors as Record<string, string | undefined>;
    const chosen = Object.values(form.data.mapping).filter(Boolean);
    const missing = targets.filter(
        (target) => target.required && !chosen.includes(target.value),
    );

    const setTarget = (column: number, value: string) =>
        form.setData('mapping', {
            ...form.data.mapping,
            [String(column)]: value,
        });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(mappingRoute.url(batchId), { preserveScroll: true });
    };

    return (
        <form
            onSubmit={submit}
            className="border-border bg-card overflow-hidden rounded-xl border"
        >
            <div className="border-border border-b px-5 py-3">
                <h2 className="text-[14px] font-semibold">
                    Colunas da planilha
                </h2>
                <p className="text-text-secondary text-[12.5px]">
                    Confira a que campo do modelo cada coluna corresponde.
                    Colunas ignoradas não são guardadas.
                </p>
            </div>

            <div className="overflow-x-auto">
                <table className="w-full text-[13px]">
                    <thead className="bg-muted/40 text-muted-foreground text-left text-[11.5px] tracking-wide uppercase">
                        <tr>
                            <th className="w-1/2 px-5 py-2 font-semibold">
                                Coluna
                            </th>
                            <th className="px-5 py-2 font-semibold">
                                Usar como
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-border divide-y">
                        {headers.map((header) => {
                            const current =
                                form.data.mapping[String(header.column)] ?? '';

                            return (
                                <tr key={header.column}>
                                    <td className="px-5 py-2">
                                        <span className="text-muted-foreground mr-2 font-mono text-[12px]">
                                            {header.letter}
                                        </span>
                                        <span className="font-medium">
                                            {header.label}
                                        </span>
                                    </td>
                                    <td className="px-5 py-2">
                                        <select
                                            aria-label={`Campo da coluna ${header.label}`}
                                            value={current}
                                            onChange={(event) =>
                                                setTarget(
                                                    header.column,
                                                    event.target.value,
                                                )
                                            }
                                            className="border-input bg-background focus-visible:ring-ring/50 h-9 w-full rounded-md border px-2 text-[13px] outline-none focus-visible:ring-[3px]"
                                        >
                                            <option value="">
                                                Ignorar esta coluna
                                            </option>
                                            {GROUPS.map((group) => (
                                                <optgroup
                                                    key={group}
                                                    label={group}
                                                >
                                                    {targets
                                                        .filter(
                                                            (target) =>
                                                                target.group ===
                                                                group,
                                                        )
                                                        .map((target) => (
                                                            <option
                                                                key={
                                                                    target.value
                                                                }
                                                                value={
                                                                    target.value
                                                                }
                                                                disabled={
                                                                    target.value !==
                                                                        current &&
                                                                    chosen.includes(
                                                                        target.value,
                                                                    )
                                                                }
                                                            >
                                                                {target.label}
                                                                {target.required
                                                                    ? ' *'
                                                                    : ''}
                                                            </option>
                                                        ))}
                                                </optgroup>
                                            ))}
                                        </select>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            <div className="border-border flex flex-col gap-3 border-t px-5 py-3 md:flex-row md:items-center md:justify-between">
                <div className="text-[12.5px]">
                    {missing.length > 0 ? (
                        <p className="text-warning">
                            Falta escolher a coluna de:{' '}
                            {missing.map((target) => target.label).join(', ')}.
                        </p>
                    ) : (
                        <p className="text-text-secondary">
                            Todos os campos obrigatórios têm coluna.
                        </p>
                    )}
                    {(errors.mapping || errors.file || errors.template) && (
                        <p className="text-danger mt-1" role="alert">
                            {errors.mapping ?? errors.file ?? errors.template}
                        </p>
                    )}
                </div>
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? (
                        <Spinner />
                    ) : (
                        <ListChecks className="size-4" />
                    )}
                    {revalidate ? 'Validar de novo' : 'Validar planilha'}
                </Button>
            </div>
        </form>
    );
}
