import { Head, Link } from '@inertiajs/react';
import { EyeOff, ShieldOff } from 'lucide-react';
import { CopyButton } from '@/components/copy-button';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { RecipientStatusBadge } from '@/components/status/recipient-status-badge';
import { Button } from '@/components/ui/button';
import {
    FileCheck,
    type FileCheckTarget,
} from '@/components/verification/file-check';
import { HashList, type HashEntry } from '@/components/verification/hash-list';
import {
    CertificateDetails,
    SignatureStatement,
    ValidationDetails,
    type VerificationCertificate,
    type VerificationValidation,
} from '@/components/verification/signature-statement';
import { VerificationSealCard } from '@/components/verification/verification-seal';
import { formatDateTime, formatVerificationCode, plural } from '@/lib/format';
import {
    check_file as verifyCheckFile,
    index as verifyIndex,
} from '@/routes/verify';
import type { RecipientStatus, SignatureStatus } from '@/types/enums';

/**
 * Resultado publicável (`App\Services\Verification\PublicVerification::result`).
 *
 * O backend é a autoridade sobre **o que pode sair** (ROUTES §4.3): esta interface
 * só descreve o que ele publica. Os textos autorais (`signature_statement`,
 * `hash_primer`, rótulos da validação) vêm dele prontos e são exibidos como vieram
 * — duplicá-los aqui criaria uma segunda redação jurídica para manter.
 */
export interface PublicVerificationResult {
    verification_code: string;
    status: 'completed' | 'in_progress' | 'refused' | 'expired' | 'canceled';
    status_label: string;
    title: string;
    organization_name: string;
    created_at: string | null;
    sent_at: string | null;
    completed_at: string | null;
    pages: number;
    hashes: {
        /** Versão congelada no envio (alias histórico: `original_sha256`). */
        sent_sha256: string | null;
        final_sha256: string | null;
        original_sha256?: string | null;
        signed_sha256?: string | null;
    };
    hash_primer?: string | null;
    signature_status: SignatureStatus;
    signature_state?: 'pending' | 'none' | 'company_a1';
    signature_label?: string | null;
    signature_statement?: string | null;
    signature_profile?: string | null;
    certificate: VerificationCertificate | null;
    validation?: VerificationValidation | null;
    validation_summary?: string | null;
    verify_url?: string | null;
    recipients: {
        name_masked: string;
        role: string | null;
        status: RecipientStatus;
        status_label: string;
        signed_at: string | null;
    }[];
    events_summary: { label: string; occurred_at: string }[];
}

export interface VerifyShowProps {
    code: string;
    found: boolean;
    result: PublicVerificationResult | null;
    /**
     * Resultado da conferência **por resumo digitado** (`verify.check_file`), o
     * caminho alternativo para quem não tem WebCrypto. A conferência padrão roda
     * no navegador e não passa por aqui.
     */
    file_check?: {
        matches: 'signed' | 'original' | 'none';
        checked_sha256: string;
    } | null;
}

/**
 * Verificação pública — resultado (ROUTES §2.19 e §4; arquitetura §6).
 *
 * Nunca exibe: e-mails, telefones, IPs, user agents, geolocalização, imagens de
 * assinatura, valores de campos, conteúdo ou download do PDF, mensagem do
 * remetente, pasta, nome do criador, dados de plano ou IDs internos.
 */
export default function VerifyShow({
    code,
    found,
    result,
    file_check = null,
}: VerifyShowProps) {
    if (!found || !result) {
        return (
            <>
                <Head title="Documento não encontrado" />
                <div className="border-border bg-card shadow-card rounded-xl border">
                    <EmptyState
                        icon={ShieldOff}
                        title="Nenhum documento encontrado com este código"
                        description={
                            <>
                                O código{' '}
                                <b className="font-mono">
                                    {formatVerificationCode(code)}
                                </b>{' '}
                                não corresponde a nenhum documento verificável.
                                Confira os caracteres — o alfabeto não usa 0, 1,
                                O nem I — e tente novamente.
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

    const formattedCode = formatVerificationCode(result.verification_code);
    const completed = result.status === 'completed';
    const finalHash =
        result.hashes.final_sha256 ?? result.hashes.signed_sha256 ?? null;
    const sentHash =
        result.hashes.sent_sha256 ?? result.hashes.original_sha256 ?? null;
    const policy =
        result.certificate?.policy ?? result.signature_profile ?? null;

    const hashEntries: HashEntry[] = [
        { kind: 'sent', value: sentHash, label: 'Documento enviado' },
        { kind: 'final', value: finalHash, label: 'Arquivo final' },
    ];

    const checkTargets: FileCheckTarget[] = [
        ...(finalHash
            ? [
                  {
                      key: 'final',
                      label: 'Arquivo final',
                      sha256: finalHash,
                      canonical: true,
                  },
              ]
            : []),
        ...(sentHash && sentHash !== finalHash
            ? [
                  {
                      key: 'sent',
                      label: 'documento enviado',
                      sha256: sentHash,
                      hint: 'É a versão apresentada aos signatários, antes dos campos preenchidos e do relatório de evidências — não é o arquivo final.',
                  },
              ]
            : []),
    ];

    return (
        <>
            <Head title={`Verificação · ${formattedCode}`} />

            <div className="border-border bg-card shadow-card flex flex-col gap-5 rounded-xl border p-6 md:p-8">
                <header className="flex flex-col gap-3">
                    <p className="text-muted-foreground text-[11px] font-bold tracking-[.18em] uppercase">
                        Verificação pública
                    </p>
                    <h1 className="text-[22px] leading-[1.2] font-bold tracking-[-.01em]">
                        {result.title}
                    </h1>
                    <p className="text-text-secondary text-[13.5px] leading-[1.5]">
                        Enviado por{' '}
                        <b className="text-foreground">
                            {result.organization_name}
                        </b>
                        {result.pages > 0 &&
                            ` · ${plural(result.pages, 'página')}`}{' '}
                        · {plural(result.recipients.length, 'participante')}
                    </p>
                    <div className="border-border bg-sidebar flex items-center justify-between gap-2 rounded-lg border p-3">
                        <span className="tabular font-mono text-[16px] font-bold">
                            {formattedCode}
                        </span>
                        <CopyButton
                            value={formattedCode}
                            label="Copiar código de verificação"
                            className="size-7"
                        />
                    </div>
                </header>

                <VerificationSealCard
                    status={result.status}
                    signatureStatus={result.signature_status}
                    policy={policy}
                />

                <dl className="grid gap-x-6 text-[13px] sm:grid-cols-2">
                    <Row
                        label="Criado em"
                        value={formatDateTime(result.created_at)}
                    />
                    <Row
                        label="Enviado em"
                        value={formatDateTime(result.sent_at)}
                    />
                    <Row
                        label="Concluído em"
                        value={formatDateTime(result.completed_at)}
                    />
                    <Row
                        label="Estado registrado"
                        value={result.status_label}
                    />
                </dl>

                <section>
                    <Heading
                        variant="small"
                        title="Participantes"
                        description="Nomes abreviados para preservar a privacidade. E-mails, telefones e IPs não são exibidos nesta página."
                        className="mb-3"
                    />
                    <ul className="flex flex-col gap-2">
                        {result.recipients.map((recipient, index) => (
                            <li
                                key={`${recipient.name_masked}-${index}`}
                                className="border-border flex flex-wrap items-center justify-between gap-2 rounded-lg border px-3 py-2 text-[13px]"
                            >
                                <span>
                                    <b>{recipient.name_masked}</b>
                                    {recipient.role && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {recipient.role}
                                        </span>
                                    )}
                                </span>
                                <span className="flex items-center gap-2">
                                    <RecipientStatusBadge
                                        status={recipient.status}
                                        label={recipient.status_label}
                                    />
                                    {recipient.signed_at && (
                                        <span className="text-muted-foreground tabular text-[12px]">
                                            {formatDateTime(
                                                recipient.signed_at,
                                            )}
                                        </span>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>

                {result.events_summary.length > 0 && (
                    <section>
                        <Heading
                            variant="small"
                            title="Marcos"
                            description="Sem IP, navegador ou geolocalização — esses dados ficam no relatório de evidências, acessível apenas a quem participou do documento."
                            className="mb-2"
                        />
                        <ul className="flex flex-col text-[12.5px]">
                            {result.events_summary.map((event, index) => (
                                <li
                                    key={`${event.label}-${index}`}
                                    className="border-muted flex flex-wrap justify-between gap-3 border-b py-1.5 last:border-b-0"
                                >
                                    <span>{event.label}</span>
                                    <span className="text-muted-foreground tabular">
                                        {formatDateTime(event.occurred_at)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section className="flex flex-col gap-3">
                    <Heading
                        variant="small"
                        title="Integridade (SHA-256)"
                        description={
                            completed
                                ? 'Cada resumo identifica bytes diferentes do mesmo documento.'
                                : 'O resumo do arquivo final é publicado quando o documento é concluído.'
                        }
                    />
                    <HashList
                        entries={hashEntries}
                        primerText={result.hash_primer}
                    />
                    {checkTargets.length > 0 && (
                        <FileCheck
                            targets={checkTargets}
                            manual={{
                                action: verifyCheckFile(code).url,
                                result: file_check,
                            }}
                        />
                    )}
                </section>

                <section className="flex flex-col gap-3">
                    <Heading variant="small" title="Situação da assinatura" />
                    <SignatureStatement
                        status={result.signature_status}
                        certificate={result.certificate}
                        statement={result.signature_statement}
                        verifyLabel="assinavelox.com.br/verificar"
                        verificationCode={formattedCode}
                        validationSummary={result.validation_summary}
                    />
                    {result.certificate && (
                        <div className="border-border rounded-lg border p-3.5">
                            <p className="mb-2 text-[12.5px] font-semibold">
                                Certificado da operadora
                            </p>
                            <CertificateDetails
                                certificate={result.certificate}
                            />
                        </div>
                    )}
                    {/*
                        O resultado técnico completo, igual ao da página interna de
                        evidências. `validation_summary` sozinho é `null` justamente no caso
                        inconclusivo — e onde a plataforma tem uma ressalva a fazer, o
                        silêncio ao lado do selo verde é lido como confirmação.
                    */}
                    {result.signature_status === 'company_a1' &&
                        result.validation && (
                            <div className="border-border rounded-lg border p-3.5">
                                <p className="mb-2 text-[12.5px] font-semibold">
                                    Resultado técnico da validação
                                </p>
                                <ValidationDetails
                                    validation={result.validation}
                                />
                            </div>
                        )}
                </section>

                <p className="text-muted-foreground flex items-start gap-2 text-[11.5px] leading-[1.5]">
                    <EyeOff className="mt-[1px] size-3.5 shrink-0" />
                    <span>
                        Esta página não exibe o conteúdo do documento, não
                        permite baixá-lo e não mostra e-mails, telefones, IPs,
                        navegadores, geolocalização, imagens de assinatura nem
                        os valores preenchidos. Ela informa o que a AssinaVelox
                        registrou; não é um certificado emitido por autoridade
                        certificadora e não substitui a análise das partes sobre
                        a validade do ato.
                    </span>
                </p>

                <Button asChild variant="outline" className="self-start">
                    <Link href={verifyIndex()}>Verificar outro código</Link>
                </Button>
            </div>
        </>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="border-muted flex justify-between gap-3 border-b py-2">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="tabular text-right">{value}</dd>
        </div>
    );
}
