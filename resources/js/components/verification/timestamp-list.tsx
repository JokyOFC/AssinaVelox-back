import { Clock } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    EvidenceTimestampItem,
    PublicTimestamp,
} from '@/types/signatures';

/**
 * Carimbos do tempo do envelope (Fase 2 §2.13 — `TimestampEvidence`).
 *
 * O rótulo é SEMPRE o que o servidor manda (`label`), exibido como veio: para a TSA da
 * própria operadora ele diz que não é carimbo da ICP-Brasil (roadmap T3). Esta tela nunca
 * escreve um rótulo próprio para o tipo do carimbo, e carimbo de TESTE sempre mostra o aviso.
 */

const DEFAULT_NOTICE =
    'O carimbo do tempo da operadora prova apenas que a AssinaVelox atesta que o resumo existia no horário indicado. Não é carimbo emitido por autoridade credenciada da ICP-Brasil e não altera o perfil da assinatura (PAdES-B-B).';

const DEFAULT_TEST_NOTICE =
    'Carimbo emitido por serviço de TESTE: sem valor jurídico, apenas para desenvolvimento e homologação.';

type Item = EvidenceTimestampItem | PublicTimestamp;

function isDetailed(item: Item): item is EvidenceTimestampItem {
    return 'serial' in item;
}

/** "2026-09-11T13:02:01.123Z" → "11/09/2026 10:02 (13:02:01.123 UTC)". */
function genTimeLabel(value: string): string {
    const utc = value.match(/T(\d{2}:\d{2}:\d{2}(?:\.\d+)?)/)?.[1];

    return utc
        ? `${formatDateTime(value)} (${utc} UTC)`
        : formatDateTime(value);
}

export function TimestampList({
    items,
    notice,
    className,
}: {
    items: Item[];
    notice?: string | null;
    className?: string;
}) {
    if (items.length === 0) {
        return null;
    }

    return (
        <div className={cn('flex flex-col gap-2.5', className)}>
            <ul className="flex flex-col gap-2.5">
                {items.map((item, index) => {
                    const detailed = isDetailed(item) ? item : null;
                    const verification = detailed?.verification ?? null;
                    const valid =
                        verification && typeof verification.valid === 'boolean'
                            ? verification.valid
                            : null;

                    return (
                        <li
                            key={detailed?.id ?? `${item.gen_time}-${index}`}
                            className="border-border rounded-lg border p-3"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <p className="flex min-w-0 items-start gap-1.5 text-[12.5px] leading-[1.45] font-semibold">
                                    <Clock className="text-primary mt-0.5 size-3.5 shrink-0" />
                                    <span>{item.label}</span>
                                </p>
                                <Badge variant="neutral">
                                    {item.purpose_label}
                                </Badge>
                            </div>

                            <dl className="mt-2 grid grid-cols-[112px_1fr] gap-x-3 gap-y-1 text-[12.5px]">
                                <dt className="text-muted-foreground">
                                    Horário atestado
                                </dt>
                                <dd className="tabular">
                                    {genTimeLabel(item.gen_time)}
                                </dd>
                                {detailed?.serial != null && (
                                    <>
                                        <dt className="text-muted-foreground">
                                            Série
                                        </dt>
                                        <dd className="font-mono text-[11.5px] break-all">
                                            {String(detailed.serial)}
                                        </dd>
                                    </>
                                )}
                                {detailed?.policy_oid && (
                                    <>
                                        <dt className="text-muted-foreground">
                                            Política
                                        </dt>
                                        <dd className="font-mono text-[11.5px] break-all">
                                            {detailed.policy_oid}
                                        </dd>
                                    </>
                                )}
                                {detailed?.tsa_subject && (
                                    <>
                                        <dt className="text-muted-foreground">
                                            Emitido por
                                        </dt>
                                        <dd className="break-words">
                                            {detailed.tsa_subject}
                                        </dd>
                                    </>
                                )}
                                {detailed?.accuracy_ms != null && (
                                    <>
                                        <dt className="text-muted-foreground">
                                            Precisão
                                        </dt>
                                        <dd className="tabular">
                                            ± {detailed.accuracy_ms} ms
                                            (declarada)
                                        </dd>
                                    </>
                                )}
                                {detailed?.imprint && (
                                    <>
                                        <dt className="text-muted-foreground">
                                            Resumo
                                            {detailed.hash_algorithm
                                                ? ` (${detailed.hash_algorithm.toUpperCase()})`
                                                : ''}
                                        </dt>
                                        <dd className="font-mono text-[11.5px] break-all">
                                            {detailed.imprint}
                                        </dd>
                                    </>
                                )}
                                {valid !== null && (
                                    <>
                                        <dt className="text-muted-foreground">
                                            Conferência
                                        </dt>
                                        <dd>
                                            {valid
                                                ? 'Token conferido automaticamente: resumo e assinatura conferem.'
                                                : 'A conferência automática NÃO confirmou este carimbo.'}
                                        </dd>
                                    </>
                                )}
                            </dl>

                            {detailed?.statement && (
                                <p className="text-muted-foreground mt-2 text-[12px] leading-[1.5]">
                                    {detailed.statement}
                                </p>
                            )}

                            {item.is_test && (
                                <p className="border-warning-border bg-warning-bg text-warning mt-2 rounded-[10px] border p-2.5 text-[12px] leading-[1.45]">
                                    {detailed?.test_notice ??
                                        DEFAULT_TEST_NOTICE}
                                </p>
                            )}
                        </li>
                    );
                })}
            </ul>
            <p className="text-muted-foreground text-[11.5px] leading-[1.5]">
                {notice ?? DEFAULT_NOTICE}
            </p>
        </div>
    );
}
