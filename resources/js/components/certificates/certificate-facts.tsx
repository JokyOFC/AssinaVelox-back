import { CircleAlert } from 'lucide-react';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';

/**
 * Fatos públicos de um certificado do PRÓPRIO participante, como o servidor os leu:
 * titular (CPF sempre mascarado), emissor, validade, série e impressão digital. Nada aqui
 * é "confirmado": o CPF é o que o certificado declara, e a cadeia não é validada até uma
 * raiz da ICP-Brasil (docs/fase-2/a1-do-participante.md §2).
 */
export interface CertificateFactsValue {
    holder_name?: string | null;
    holder_cpf_masked?: string | null;
    issuer_cn?: string | null;
    issuer?: string | null;
    serial?: string | null;
    fingerprint_sha256?: string | null;
    valid_from?: string | null;
    valid_to?: string | null;
    kind_label?: string | null;
}

export function CertificateFacts({
    certificate,
    showSerial = true,
    showFingerprint = true,
    className,
}: {
    certificate: CertificateFactsValue;
    showSerial?: boolean;
    showFingerprint?: boolean;
    className?: string;
}) {
    const validity =
        certificate.valid_from && certificate.valid_to
            ? `${formatDate(certificate.valid_from)} → ${formatDate(certificate.valid_to)}`
            : certificate.valid_to
              ? `até ${formatDate(certificate.valid_to)}`
              : null;

    const rows: [string, string | null | undefined, 'mono' | null][] = [
        [
            'Titular',
            [certificate.holder_name, certificate.holder_cpf_masked]
                .filter(Boolean)
                .join(' · ') || null,
            null,
        ],
        ['Emissor', certificate.issuer_cn ?? certificate.issuer, null],
        ['Validade', validity, null],
        ['Tipo', certificate.kind_label, null],
        ['Série', showSerial ? certificate.serial : null, 'mono'],
        [
            'Impressão digital',
            showFingerprint ? certificate.fingerprint_sha256 : null,
            'mono',
        ],
    ];

    return (
        <dl
            className={cn(
                'grid grid-cols-[112px_1fr] gap-x-3 gap-y-1.5 text-[12.5px]',
                className,
            )}
        >
            {rows
                .filter(([, value]) => Boolean(value))
                .map(([label, value, style]) => (
                    <div key={label} className="contents">
                        <dt className="text-muted-foreground">{label}</dt>
                        <dd
                            className={cn(
                                'min-w-0 break-words',
                                style === 'mono' &&
                                    'font-mono text-[11.5px] break-all',
                            )}
                        >
                            {value}
                        </dd>
                    </div>
                ))}
        </dl>
    );
}

/**
 * Aviso destacado de certificado de TESTE. O texto vem do servidor (`kind_label`) quando
 * existe; o padrão repete a mesma frase — nunca apresentar teste como ICP-Brasil.
 */
export function TestCertificateNotice({
    label,
    className,
}: {
    label?: string | null;
    className?: string;
}) {
    return (
        <p
            role="note"
            className={cn(
                'border-warning-border bg-warning-bg text-warning flex items-start gap-2 rounded-[10px] border p-3 text-[12.5px] leading-[1.5]',
                className,
            )}
        >
            <CircleAlert className="mt-0.5 size-4 shrink-0" />
            <span>
                <b>
                    {label ??
                        'Certificado de TESTE — não é ICP-Brasil e não tem validade jurídica'}
                </b>
                . Serve apenas para experimentar o recurso; a assinatura feita
                com ele não tem valor para uso real.
            </span>
        </p>
    );
}
