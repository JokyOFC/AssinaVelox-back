export interface EvidenceRow {
    key: string;
    label: string;
    value: string;
}

/**
 * Evidência minimizada de um sinal, como foi gravada (só contagens, IDs
 * opacos e rede truncada — nunca conteúdo de documento, e-mail ou CPF).
 */
export function EvidenceList({ rows }: { rows: EvidenceRow[] }) {
    if (rows.length === 0) {
        return <span className="text-muted-foreground text-[13px]">—</span>;
    }

    return (
        <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-[12.5px]">
            {rows.map((row) => (
                <div key={row.key} className="contents">
                    <dt className="text-muted-foreground">{row.label}</dt>
                    <dd
                        className="tabular truncate font-medium"
                        title={row.value}
                    >
                        {row.value}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
