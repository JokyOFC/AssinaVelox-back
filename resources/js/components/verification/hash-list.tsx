import { Info } from 'lucide-react';
import { CopyButton } from '@/components/copy-button';
import { cn } from '@/lib/utils';

/**
 * Os quatro resumos do dossiê (declaracao-de-aceite.md §5.1) mais o relatório
 * de evidências. Cada um é calculado sobre **bytes distintos** — dizer "o hash
 * do documento" sem dizer de quais bytes é o erro que estes rótulos evitam.
 */
export type HashKind =
    | 'original'
    | 'sent'
    | 'consolidated'
    | 'evidence'
    | 'final';

export const HASH_LABELS: Record<HashKind, string> = {
    original: 'Original',
    sent: 'Enviado',
    consolidated: 'Consolidado',
    evidence: 'Relatório de evidências',
    final: 'Final',
};

export const HASH_DESCRIPTIONS: Record<HashKind, string> = {
    original:
        'Dos bytes do arquivo exatamente como a organização remetente o enviou à plataforma (PDF, DOCX ou imagem), antes de qualquer conversão.',
    sent: 'Dos bytes da versão em PDF congelada no envio e apresentada a todos os signatários. É este resumo que cada declaração de aceite referencia. Se o original já era um PDF sem conversão, pode coincidir com o resumo original.',
    consolidated:
        'Dos bytes do PDF gerado após a coleta, com os campos preenchidos e as representações visuais de assinatura achatadas nas páginas, antes do acréscimo da página de evidências.',
    evidence:
        'Dos bytes do relatório de evidências gerado pela plataforma e anexado ao final do documento consolidado.',
    final: 'Dos bytes do arquivo final completo: documento consolidado, página de evidências e assinatura criptográfica da operadora, quando aplicada. É calculado depois de o arquivo ficar pronto e, por isso, não pode constar dentro dele — é publicado só aqui.',
};

export interface HashEntry {
    kind: HashKind;
    value: string | null | undefined;
    /** Sobrescreve o rótulo padrão (ex.: "PDF final" na página pública). */
    label?: string;
    /** Sobrescreve a explicação padrão (o servidor manda a sua em `hashes.items`). */
    description?: string;
}

/**
 * Item de resumo como o backend o publica (`HashLedger::items`). Rótulo e explicação
 * vêm prontos: são o mesmo texto impresso no relatório em PDF.
 */
export interface ServerHashItem {
    key: string;
    label: string;
    value: string | null;
    description: string;
}

const KIND_BY_KEY: Record<string, HashKind> = {
    original: 'original',
    sent: 'sent',
    consolidated: 'consolidated',
    evidence: 'evidence',
    final: 'final',
};

/** Converte os itens do servidor nas entradas da lista, preservando os textos dele. */
export function entriesFromServer(items: ServerHashItem[]): HashEntry[] {
    return items.map((item) => ({
        kind: KIND_BY_KEY[item.key] ?? 'final',
        value: item.value,
        label: item.label,
        description: item.description,
    }));
}

/** Texto do bloco "Como ler os resumos criptográficos" (declaração §5.1). */
export const HASH_PRIMER =
    'Um resumo SHA-256 é uma sequência de 64 caracteres que identifica um arquivo byte a byte: qualquer alteração no arquivo, por menor que seja, produz um resumo completamente diferente. Um resumo não é uma assinatura — ele permite conferir se dois arquivos são idênticos, e nada mais.';

export function HashRow({
    entry,
    showDescription = true,
}: {
    entry: HashEntry;
    showDescription?: boolean;
}) {
    const label = entry.label ?? HASH_LABELS[entry.kind];
    const description = entry.description ?? HASH_DESCRIPTIONS[entry.kind];

    return (
        <div className="border-border bg-sidebar rounded-lg border p-3 text-[12px]">
            <div className="flex items-center justify-between gap-2">
                <span className="text-text-secondary font-semibold">
                    {label}
                </span>
                {entry.value && (
                    <CopyButton
                        value={entry.value}
                        label={`Copiar resumo ${label}`}
                        className="size-6"
                    />
                )}
            </div>
            <code className="text-foreground mt-1 block font-mono break-all">
                {entry.value ?? '—'}
            </code>
            {showDescription && (
                <p className="text-muted-foreground mt-1.5 text-[11.5px] leading-[1.45]">
                    {description}
                </p>
            )}
        </div>
    );
}

/**
 * Lista de resumos com a explicação de quais bytes cada um identifica.
 * Entradas sem valor são omitidas (o resumo final só existe após a conclusão).
 */
export function HashList({
    entries,
    showDescriptions = true,
    primer = true,
    primerText,
    className,
}: {
    entries: HashEntry[];
    showDescriptions?: boolean;
    /** Exibe o parágrafo "o que é um resumo" abaixo da lista. */
    primer?: boolean;
    /** Texto do parágrafo; o servidor manda o dele em `hashes.primer`. */
    primerText?: string | null;
    className?: string;
}) {
    const present = entries.filter((entry) => Boolean(entry.value));

    if (present.length === 0) {
        return (
            <p className="text-muted-foreground text-[12.5px] leading-[1.5]">
                Os resumos do arquivo final são publicados quando o documento é
                concluído.
            </p>
        );
    }

    return (
        <div className={cn('flex flex-col gap-2', className)}>
            {present.map((entry) => (
                <HashRow
                    key={`${entry.kind}-${entry.label ?? ''}`}
                    entry={entry}
                    showDescription={showDescriptions}
                />
            ))}
            {primer && (
                <p className="text-muted-foreground flex items-start gap-2 text-[11.5px] leading-[1.5]">
                    <Info className="mt-[1px] size-3.5 shrink-0" />
                    <span>{primerText ?? HASH_PRIMER}</span>
                </p>
            )}
        </div>
    );
}
