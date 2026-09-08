import { Head, Link } from '@inertiajs/react';
import { FileSearch, ShieldCheck, ShieldOff } from 'lucide-react';
import { useState } from 'react';
import { CopyButton } from '@/components/copy-button';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { EnvelopeStatusBadge } from '@/components/status/envelope-status-badge';
import { RecipientStatusBadge } from '@/components/status/recipient-status-badge';
import { HashBox } from '@/components/timeline';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime, formatVerificationCode } from '@/lib/format';
import { cn } from '@/lib/utils';
import { index as verifyIndex } from '@/routes/verify';
import type { VerificationResult } from '@/types';

export interface VerifyShowProps {
    code: string;
    found: boolean;
    result: VerificationResult | null;
    file_check: {
        matches: 'signed' | 'original' | 'none';
        checked_sha256: string;
    } | null;
}

async function sha256Hex(file: File): Promise<string> {
    const buffer = await file.arrayBuffer();
    const digest = await crypto.subtle.digest('SHA-256', buffer);

    return Array.from(new Uint8Array(digest))
        .map((b) => b.toString(16).padStart(2, '0'))
        .join('');
}

/**
 * Verificação pública — resultado (ROUTES §2.19; arquitetura §6). Comparação
 * de arquivo local por SHA-256 calculado no navegador (WebCrypto), sem upload.
 */
export default function VerifyShow({ code, found, result }: VerifyShowProps) {
    const [localHash, setLocalHash] = useState<string | null>(null);
    const [hashing, setHashing] = useState(false);

    const handleFile = async (file: File | undefined) => {
        if (!file) {
            return;
        }

        setHashing(true);

        try {
            setLocalHash(await sha256Hex(file));
        } finally {
            setHashing(false);
        }
    };

    const match =
        localHash && result
            ? localHash === result.hashes.signed_sha256
                ? 'signed'
                : localHash === result.hashes.original_sha256
                  ? 'original'
                  : 'none'
            : null;

    if (!found || !result) {
        return (
            <>
                <Head title="Documento não encontrado" />
                <div className="border-border bg-card shadow-card rounded-xl border">
                    <EmptyState
                        icon={ShieldOff}
                        title="Nenhum documento encontrado para este código"
                        description={
                            <>
                                O código{' '}
                                <b className="font-mono">
                                    {formatVerificationCode(code)}
                                </b>{' '}
                                não corresponde a um documento enviado ou
                                concluído. Confira os caracteres (não usamos 0,
                                1, O e I) e tente novamente.
                            </>
                        }
                        action={
                            <Button asChild variant="outline">
                                <Link href={verifyIndex()}>
                                    Tentar outro código
                                </Link>
                            </Button>
                        }
                    />
                </div>
            </>
        );
    }

    const signedByOperator = result.signature_status === 'company_a1';

    return (
        <>
            <Head
                title={`Verificação · ${formatVerificationCode(result.verification_code)}`}
            />

            <div className="border-border bg-card shadow-card flex flex-col gap-4 rounded-xl border p-6 md:p-8">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <p className="text-muted-foreground text-[11px] font-bold tracking-[.18em] uppercase">
                            Verificação pública
                        </p>
                        <h1 className="mt-1.5 text-[22px] leading-[1.2] font-bold tracking-[-.01em]">
                            {result.title}
                        </h1>
                        <p className="text-text-secondary mt-1.5 text-[13.5px]">
                            Enviado por{' '}
                            <b className="text-foreground">
                                {result.organization_name}
                            </b>{' '}
                            · {result.pages}{' '}
                            {result.pages === 1 ? 'página' : 'páginas'}
                        </p>
                    </div>
                    <EnvelopeStatusBadge
                        status={result.status}
                        signedCount={result.status === 'in_progress' ? 0 : 1}
                        label={result.status_label}
                        className="px-[9px] py-[3px]"
                    />
                </div>

                <div className="border-border bg-sidebar flex items-center justify-between gap-2 rounded-lg border p-3">
                    <span className="tabular font-mono text-[16px] font-bold">
                        {formatVerificationCode(result.verification_code)}
                    </span>
                    <CopyButton
                        value={formatVerificationCode(result.verification_code)}
                        className="size-7"
                    />
                </div>

                <div
                    className={cn(
                        'flex items-start gap-3 rounded-[10px] border p-3.5 text-[13px] leading-[1.5]',
                        signedByOperator
                            ? 'border-success-border bg-success-bg text-success'
                            : 'border-neutral-border bg-neutral-bg text-text-secondary',
                    )}
                >
                    {signedByOperator ? (
                        <ShieldCheck className="mt-0.5 size-4 shrink-0" />
                    ) : (
                        <ShieldOff className="mt-0.5 size-4 shrink-0" />
                    )}
                    <span>
                        {signedByOperator
                            ? 'PDF final assinado criptograficamente com o certificado A1 da operadora (AssinaVelox). A assinatura identifica a operadora e protege a integridade do arquivo; não é assinatura pessoal ICP-Brasil dos participantes.'
                            : 'Documento concluído como aceite eletrônico com evidências, sem assinatura criptográfica.'}
                    </span>
                </div>

                <dl className="grid gap-x-6 gap-y-2 text-[13px] sm:grid-cols-2">
                    <div className="border-muted flex justify-between gap-3 border-b py-2">
                        <dt className="text-muted-foreground">Criado em</dt>
                        <dd className="tabular">
                            {formatDateTime(result.created_at)}
                        </dd>
                    </div>
                    <div className="border-muted flex justify-between gap-3 border-b py-2">
                        <dt className="text-muted-foreground">Enviado em</dt>
                        <dd className="tabular">
                            {formatDateTime(result.sent_at)}
                        </dd>
                    </div>
                    <div className="border-muted flex justify-between gap-3 border-b py-2">
                        <dt className="text-muted-foreground">Concluído em</dt>
                        <dd className="tabular">
                            {formatDateTime(result.completed_at)}
                        </dd>
                    </div>
                    <div className="border-muted flex justify-between gap-3 border-b py-2">
                        <dt className="text-muted-foreground">Participantes</dt>
                        <dd className="tabular">{result.recipients.length}</dd>
                    </div>
                </dl>

                <div>
                    <Heading
                        variant="small"
                        title="Participantes"
                        description="Nomes abreviados para preservar a privacidade."
                        className="mb-3"
                    />
                    <ul className="flex flex-col gap-2">
                        {result.recipients.map((r, i) => (
                            <li
                                key={`${r.name_masked}-${i}`}
                                className="border-border flex flex-wrap items-center justify-between gap-2 rounded-lg border px-3 py-2 text-[13px]"
                            >
                                <span>
                                    <b>{r.name_masked}</b>
                                    {r.role && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {r.role}
                                        </span>
                                    )}
                                </span>
                                <span className="flex items-center gap-2">
                                    <RecipientStatusBadge
                                        status={r.status}
                                        label={r.status_label}
                                    />
                                    {r.signed_at && (
                                        <span className="text-muted-foreground tabular text-[12px]">
                                            {formatDateTime(r.signed_at)}
                                        </span>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>

                <div className="flex flex-col gap-2">
                    <Heading variant="small" title="Integridade (SHA-256)" />
                    <HashBox
                        label="Documento enviado"
                        value={result.hashes.original_sha256}
                    />
                    {result.hashes.signed_sha256 && (
                        <HashBox
                            label="PDF final"
                            value={result.hashes.signed_sha256}
                        />
                    )}
                </div>

                {result.certificate && (
                    <dl className="border-border grid grid-cols-[110px_1fr] gap-x-3 gap-y-1.5 rounded-lg border p-3 text-[12.5px]">
                        <dt className="text-muted-foreground">Certificado</dt>
                        <dd>{result.certificate.subject_cn}</dd>
                        <dt className="text-muted-foreground">Emissor</dt>
                        <dd>{result.certificate.issuer_cn}</dd>
                        <dt className="text-muted-foreground">Válido até</dt>
                        <dd className="tabular">
                            {formatDateTime(result.certificate.valid_to)}
                        </dd>
                        <dt className="text-muted-foreground">Perfil</dt>
                        <dd>{result.certificate.policy}</dd>
                    </dl>
                )}

                {result.events_summary.length > 0 && (
                    <div>
                        <Heading
                            variant="small"
                            title="Marcos"
                            className="mb-2"
                        />
                        <ul className="flex flex-col gap-1 text-[12.5px]">
                            {result.events_summary.map((e, i) => (
                                <li
                                    key={`${e.label}-${i}`}
                                    className="border-muted flex justify-between gap-3 border-b py-1.5 last:border-b-0"
                                >
                                    <span>{e.label}</span>
                                    <span className="text-muted-foreground tabular">
                                        {formatDateTime(e.occurred_at)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                <div className="border-border-dashed rounded-[10px] border border-dashed p-4">
                    <div className="flex items-start gap-3">
                        <FileSearch className="text-primary mt-0.5 size-5 shrink-0" />
                        <div className="min-w-0 flex-1">
                            <div className="text-[13.5px] font-semibold">
                                Conferir um arquivo local
                            </div>
                            <p className="text-muted-foreground mt-0.5 text-[12.5px] leading-[1.5]">
                                O hash é calculado no seu navegador; o arquivo
                                não é enviado a nenhum servidor.
                            </p>
                            <input
                                type="file"
                                accept="application/pdf"
                                onChange={(e) =>
                                    void handleFile(e.target.files?.[0])
                                }
                                className="file:border-input mt-3 block w-full text-[13px] file:mr-3 file:rounded-lg file:border file:bg-white file:px-3 file:py-1.5 file:text-[13px] file:font-semibold"
                            />
                            {hashing && (
                                <p className="text-muted-foreground mt-2 text-[12.5px]">
                                    Calculando SHA-256…
                                </p>
                            )}
                            {localHash && match && (
                                <div className="mt-3 flex flex-col gap-2">
                                    <Badge
                                        variant={
                                            match === 'none'
                                                ? 'danger'
                                                : 'success'
                                        }
                                        dot
                                    >
                                        {match === 'signed'
                                            ? 'Corresponde ao PDF final'
                                            : match === 'original'
                                              ? 'Corresponde ao documento enviado (não ao PDF final)'
                                              : 'Não corresponde a este documento'}
                                    </Badge>
                                    <code className="text-muted-foreground block font-mono text-[11.5px] break-all">
                                        {localHash}
                                    </code>
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                <Button asChild variant="outline" className="self-start">
                    <Link href={verifyIndex()}>Verificar outro código</Link>
                </Button>
            </div>
        </>
    );
}
