import { Head, Link, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    Download,
    FileSpreadsheet,
    Info,
    TriangleAlert,
} from 'lucide-react';
import type { FormEvent } from 'react';
import type {
    BulkLimits,
    DirectSend,
} from '@/components/bulk-generations/types';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import type {
    ConversionInfo,
    TemplateSummary,
} from '@/components/templates/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatNumber } from '@/lib/format';
import { index as bulkIndex, sample, store } from '@/routes/bulk_generations';
import { index as templatesIndex } from '@/routes/templates';

interface BulkCreateProps {
    template: TemplateSummary & {
        version: number;
        roles_count: number;
        variables_count: number;
    };
    columns: { label: string; group: string; required: boolean }[];
    direct_send: DirectSend;
    conversion: ConversionInfo;
    limits: BulkLimits;
}

const GROUPS = ['Participantes', 'Variáveis', 'Documento'];

/**
 * "Gerar em lote" a partir de um modelo (Fase 3 §3.1): explica a planilha,
 * oferece a planilha-modelo e recebe o arquivo. Nada é criado nesta etapa —
 * o próximo passo é conferir as colunas e validar.
 */
export default function BulkGenerationCreate({
    template,
    columns,
    direct_send,
    conversion,
    limits,
}: BulkCreateProps) {
    const form = useForm<{ file: File | null }>({ file: null });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(store.url(template.id), {
            forceFormData: true,
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title={`Gerar em lote · ${template.name}`} />
            <PageHeader
                size="detail"
                leading={
                    <Button
                        asChild
                        variant="outline"
                        size="icon"
                        aria-label="Voltar para Modelos"
                    >
                        <Link href={templatesIndex.url()}>
                            <ArrowLeft />
                        </Link>
                    </Button>
                }
                eyebrow="Gerar em lote"
                title={template.name}
                badge={<Badge variant="neutral">{template.source_label}</Badge>}
                subtitle="Envie uma planilha com um documento por linha. Antes de gerar, você confere as colunas e vê o resultado da validação de cada linha."
            />

            {conversion.required && !conversion.available && (
                <div className="border-warning-border bg-warning-bg text-warning flex gap-2 rounded-lg border p-3 text-[13px]">
                    <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                    <span>{conversion.message}</span>
                </div>
            )}

            {errors.template && (
                <div
                    className="border-danger-border bg-danger-bg text-danger rounded-lg border p-3 text-[13px]"
                    role="alert"
                >
                    {errors.template}
                </div>
            )}

            <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
                <section className="border-border bg-card rounded-xl border p-5">
                    <h2 className="text-[15px] font-semibold">
                        1. Monte a planilha
                    </h2>
                    <p className="text-text-secondary mt-1 text-[13px]">
                        A primeira linha tem os nomes das colunas e cada linha
                        seguinte vira um documento. Use os nomes abaixo e o
                        sistema liga as colunas sozinho — você confere antes de
                        validar.
                    </p>

                    <div className="mt-4 flex flex-col gap-4">
                        {GROUPS.map((group) => {
                            const items = columns.filter(
                                (column) => column.group === group,
                            );

                            if (items.length === 0) {
                                return null;
                            }

                            return (
                                <div key={group}>
                                    <p className="text-muted-foreground mb-1.5 text-[11px] font-bold tracking-[.14em] uppercase">
                                        {group}
                                    </p>
                                    <ul className="flex flex-wrap gap-1.5">
                                        {items.map((column) => (
                                            <li
                                                key={column.label}
                                                className="border-border bg-muted/40 rounded-md border px-2 py-1 text-[12.5px]"
                                            >
                                                {column.label}
                                                {column.required && (
                                                    <span className="text-danger ml-1">
                                                        *
                                                    </span>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            );
                        })}
                    </div>

                    <ul className="text-text-secondary mt-4 list-disc space-y-1 pl-5 text-[12.5px]">
                        <li>
                            <span className="text-danger">*</span> Coluna
                            obrigatória. Variável opcional em branco usa o valor
                            padrão do modelo.
                        </li>
                        <li>
                            Fórmulas não são calculadas: células com fórmula, ou
                            que começam com “=”, “+”, “-” ou “@” sem ser número,
                            são recusadas. Digite o valor final.
                        </li>
                        <li>
                            Datas como 05/10/2026, valores como 1.500,00 e
                            “sim”/“não” para sim ou não.
                        </li>
                    </ul>

                    <Button asChild variant="outline" className="mt-4">
                        <a href={sample.url(template.id)}>
                            <Download className="size-4" />
                            Baixar planilha modelo (CSV)
                        </a>
                    </Button>
                </section>

                <form
                    onSubmit={submit}
                    className="border-border bg-card flex h-fit flex-col gap-4 rounded-xl border p-5"
                >
                    <h2 className="text-[15px] font-semibold">
                        2. Envie o arquivo
                    </h2>
                    <div className="grid gap-1.5">
                        <Label htmlFor="bulk-file">
                            Planilha (CSV ou XLSX)
                        </Label>
                        <Input
                            id="bulk-file"
                            type="file"
                            accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            onChange={(event) =>
                                form.setData(
                                    'file',
                                    event.target.files?.[0] ?? null,
                                )
                            }
                        />
                        <InputError message={errors.file} />
                    </div>
                    <p className="text-muted-foreground text-[12px]">
                        Até {formatNumber(limits.max_rows)} linhas e{' '}
                        {limits.max_file_label}. No XLSX vale a primeira aba
                        visível.
                    </p>

                    <div className="border-info-border bg-info-bg/60 text-text-secondary flex gap-2 rounded-lg border p-3 text-[12.5px]">
                        <Info className="text-info mt-0.5 size-4 shrink-0" />
                        <span>
                            {direct_send.available
                                ? 'Os documentos deste modelo nascem prontos: na confirmação você escolhe revisar, enviar logo ou agendar.'
                                : direct_send.reason}
                        </span>
                    </div>

                    <Button
                        type="submit"
                        disabled={form.processing || form.data.file === null}
                    >
                        {form.processing ? (
                            <Spinner />
                        ) : (
                            <FileSpreadsheet className="size-4" />
                        )}
                        Enviar planilha
                    </Button>
                    <Link
                        href={bulkIndex()}
                        className="text-primary text-center text-[12.5px] hover:underline"
                    >
                        Ver lotes anteriores
                    </Link>
                </form>
            </div>
        </>
    );
}

BulkGenerationCreate.layout = {
    breadcrumbs: [
        { title: 'Modelos', href: templatesIndex() },
        { title: 'Gerar em lote', href: bulkIndex() },
    ],
};
