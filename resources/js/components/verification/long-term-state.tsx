import { Archive, History } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    HashHistoryEntry,
    LtvTechnicalState,
} from '@/types/external-signing';

/**
 * Fase 3 §3.6 (docs/fase-3/longo-prazo.md §8) — o que o arquivo TEM de material de longo
 * prazo, como estado técnico. Não é o perfil anunciado (T2): o perfil exibido é
 * `announced_profile`, como veio (hoje sempre PAdES-B-B), e nenhum perfil é derivado de
 * `status`/`level` — por isso `level` não aparece aqui. O `notice` do servidor é sempre visível.
 */
export function LongTermState({
    ltv,
    className,
}: {
    ltv: LtvTechnicalState;
    className?: string;
}) {
    if (ltv.status === 'not_applicable' && !ltv.last_timestamp_at) {
        return null;
    }

    const rows: [string, string | null][] = [
        [
            'Último carimbo',
            ltv.last_timestamp_at
                ? formatDateTime(ltv.last_timestamp_at)
                : null,
        ],
        [
            'Revogação',
            ltv.revocation_embedded
                ? 'Informações de revogação embutidas no arquivo'
                : 'Informações de revogação não embutidas',
        ],
        [
            'Vencimento do carimbo',
            ltv.archive_expires_at
                ? formatDateTime(ltv.archive_expires_at)
                : null,
        ],
        [
            'Próxima renovação',
            ltv.next_refresh_at ? formatDateTime(ltv.next_refresh_at) : null,
        ],
        [
            'Conferido em',
            ltv.checked_at ? formatDateTime(ltv.checked_at) : null,
        ],
        ['Perfil anunciado', ltv.announced_profile ?? 'PAdES-B-B'],
    ];

    return (
        <div className={cn('flex flex-col gap-2.5', className)}>
            <div className="flex items-start gap-2">
                <Archive className="text-primary mt-0.5 size-4 shrink-0" />
                <p className="min-w-0 text-[13px] leading-[1.45] font-semibold">
                    {ltv.label}
                </p>
            </div>
            <dl className="grid grid-cols-[150px_1fr] gap-x-3 gap-y-1.5 text-[12.5px]">
                {rows
                    .filter(([, value]) => Boolean(value))
                    .map(([label, value]) => (
                        <div key={label} className="contents">
                            <dt className="text-muted-foreground">{label}</dt>
                            <dd className="tabular min-w-0 break-words">
                                {value}
                            </dd>
                        </div>
                    ))}
            </dl>
            <p className="border-neutral-border bg-neutral-bg text-text-secondary rounded-[10px] border p-3 text-[12px] leading-[1.5]">
                {ltv.notice}
            </p>
        </div>
    );
}

/**
 * Histórico de resumos do arquivo final (`VerificationHashHistory::publicProps`). Só existe
 * depois de um novo carimbo de arquivamento: a chave ausente = a lista não aparece.
 */
export function HashHistoryList({
    entries,
    notice,
    className,
}: {
    entries: HashHistoryEntry[];
    notice?: string | null;
    className?: string;
}) {
    if (entries.length === 0) {
        return null;
    }

    const positions = new Set(entries.map((entry) => entry.position));

    return (
        <div className={cn('flex flex-col gap-2.5', className)}>
            {notice && (
                <p className="text-text-secondary flex items-start gap-2 text-[12.5px] leading-[1.5]">
                    <History className="text-primary mt-0.5 size-4 shrink-0" />
                    <span>{notice}</span>
                </p>
            )}
            <ol className="flex flex-col gap-2">
                {entries.map((entry, index) => (
                    <li
                        key={`${entry.position}-${entry.sha256}-${index}`}
                        className={cn(
                            'rounded-lg border p-3',
                            entry.current
                                ? 'border-success-border bg-success-bg/40'
                                : 'border-border',
                        )}
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2 text-[12.5px]">
                            <span className="min-w-0 font-semibold">
                                {positions.size > 1
                                    ? `Arquivo ${entry.position} · `
                                    : ''}
                                {entry.reason_label}
                            </span>
                            <Badge
                                variant={entry.current ? 'success' : 'neutral'}
                            >
                                {entry.current ? 'Vigente' : 'Anterior'}
                            </Badge>
                        </div>
                        <p className="text-muted-foreground mt-1 text-[12px]">
                            {entry.valid_from
                                ? `Desde ${formatDateTime(entry.valid_from)}`
                                : 'Desde a conclusão'}
                            {entry.superseded_at
                                ? ` · substituído em ${formatDateTime(entry.superseded_at)}`
                                : ''}
                        </p>
                        <code className="mt-1.5 block font-mono text-[11.5px] break-all">
                            {entry.sha256}
                        </code>
                    </li>
                ))}
            </ol>
        </div>
    );
}
