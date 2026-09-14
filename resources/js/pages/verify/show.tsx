import { Head, Link } from '@inertiajs/react';
import { ArchiveX, EyeOff, ShieldOff } from 'lucide-react';
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
import { PublicParticipantSignatureList } from '@/components/verification/crypto-signature-list';
import { HashHistoryList } from '@/components/verification/long-term-state';
import { TimestampList } from '@/components/verification/timestamp-list';
import { formatDateTime, formatVerificationCode, plural } from '@/lib/format';
import { hasExternalParticipantSignatures } from '@/lib/labels';
import {
    check_file as verifyCheckFile,
    index as verifyIndex,
} from '@/routes/verify';
import type {
    ParticipantRole,
    RecipientStatus,
    SignatureStatus,
} from '@/types/enums';
import type { HashHistoryEntry } from '@/types/external-signing';
import type {
    PublicParticipantSignature,
    PublicRetentionNotice,
    PublicTimestamp,
} from '@/types/signatures';

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
    signature_state?: 'pending' | SignatureStatus;
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
        /** Fase 2 §2.4: só quando o envelope usa papel diferente de signatário. */
        participant_role?: ParticipantRole;
        participant_role_label?: string;
    }[];
    events_summary: { label: string; occurred_at: string }[];
    /**
     * Fase 2 §2.3: só quando o envelope tem mais de um arquivo (lista fechada de chaves
     * — privacidade §11). Com um arquivo, o resultado é o da Fase 1.
     */
    documents?: {
        position: number;
        name: string;
        pages: number;
        sent_sha256: string | null;
        final_sha256: string | null;
    }[];
    documents_count?: number;
    /**
     * Fase 2 §2.12: só quando há assinatura com o certificado do próprio participante
     * APLICADA — nome mascarado, sem CPF, série ou impressão digital.
     */
    participant_signatures?: PublicParticipantSignature[];
    /** Fase 2 §2.13 (`TimestampEvidence::forPublic`): carimbos do envelope, quando houver. */
    timestamps?: PublicTimestamp[];
    /** Fase 2 §2.19: registro excluído pela política de retenção (`RetentionTombstones`). */
    retention?: PublicRetentionNotice;
    /**
     * Fase 3 §3.6 (`VerificationHashHistory::publicProps`): só depois de um novo carimbo de
     * arquivamento e se o produto decidir publicá-lo (docs/fase-3/longo-prazo.md §6).
     */
    hash_history?: HashHistoryEntry[];
    hash_history_notice?: string | null;
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
        /** `signed_previous`: Fase 3 §3.6, versão anterior do final (depois da integração). */
        matches: 'signed' | 'original' | 'signed_previous' | 'none';
        checked_sha256: string;
        /** Fase 2 §2.3: com vários arquivos, qual deles conferiu. */
        document?: { position: number; name: string } | null;
        valid_from?: string | null;
        superseded_at?: string | null;
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

    const files = result.documents ?? [];
    const multi = files.length > 1;
    const fileName = (file: { position: number; name: string }) =>
        `${file.position}. ${file.name}`;

    const singleTargets: FileCheckTarget[] = [
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

    /*
     * Fase 3 §3.6: um arquivo baixado antes de um novo carimbo de arquivamento tem outro
     * resumo. Com o histórico publicado, a conferência local reconhece a versão anterior —
     * em âmbar, porque não é o arquivo final vigente.
     */
    const previousTargets: FileCheckTarget[] = (result.hash_history ?? [])
        .filter((entry) => !entry.current)
        .map((entry, index) => ({
            key: `previous-${entry.position}-${index}`,
            label: `versão anterior do arquivo final${multi ? ` (arquivo ${entry.position})` : ''}`,
            sha256: entry.sha256,
            hint: `Ela foi substituída${entry.superseded_at ? ` em ${formatDateTime(entry.superseded_at)}` : ''} por um novo carimbo do tempo de arquivamento; o conteúdo do documento não mudou.`,
        }));

    // Fase 2 §2.3: o arquivo em mãos pode ser qualquer um dos arquivos do envelope.
    const baseTargets: FileCheckTarget[] = multi
        ? files.flatMap((file) => [
              ...(file.final_sha256
                  ? [
                        {
                            key: `final-${file.position}`,
                            label: `Arquivo final de ${fileName(file)}`,
                            sha256: file.final_sha256,
                            canonical: true,
                        },
                    ]
                  : []),
              ...(file.sent_sha256 && file.sent_sha256 !== file.final_sha256
                  ? [
                        {
                            key: `sent-${file.position}`,
                            label: `documento enviado (${fileName(file)})`,
                            sha256: file.sent_sha256,
                            hint: 'É a versão apresentada aos participantes, antes dos campos preenchidos e do relatório de evidências — não é o arquivo final.',
                        },
                    ]
                  : []),
          ])
        : singleTargets;
    const checkTargets: FileCheckTarget[] = [
        ...baseTargets,
        ...previousTargets,
    ];
    // Fase 3 §3.4: assinatura por componente — simulada? a operadora assinou por último?
    // Fase 3 §3.5: devolução do portal no mesmo arquivo (o selo cita os dois meios).
    const portalSignature = (result.participant_signatures ?? []).some(
        (item) =>
            item.kind === 'participant_govbr' ||
            item.kind === 'participant_external_unverified',
    );
    const simulatedSignature = (result.participant_signatures ?? []).some(
        (item) => item.simulated === true,
    );
    const operatorLast =
        result.signature_status === 'mixed' ||
        (hasExternalParticipantSignatures(result.signature_status) &&
            result.certificate !== null);

    // Fase 2 §2.19: excluído pela política de retenção — só o aviso e o resumo final.
    if (result.retention?.purged) {
        return (
            <PurgedVerification
                code={code}
                formattedCode={formattedCode}
                retention={result.retention}
                hashEntries={hashEntries.filter(
                    (entry) => entry.kind === 'final',
                )}
                primer={result.hash_primer ?? null}
                checkTargets={checkTargets}
                fileCheck={file_check}
                multi={multi}
                files={files}
            />
        );
    }

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
                        {multi
                            ? ` · ${plural(result.documents_count ?? files.length, 'arquivo')}`
                            : result.pages > 0 &&
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
                    simulated={simulatedSignature}
                    portal={portalSignature}
                    operator={result.certificate !== null}
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
                                    {recipient.participant_role &&
                                        recipient.participant_role !==
                                            'signer' &&
                                        recipient.participant_role_label && (
                                            <span className="text-primary font-semibold">
                                                {' '}
                                                ·{' '}
                                                {
                                                    recipient.participant_role_label
                                                }
                                            </span>
                                        )}
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
                            multi
                                ? completed
                                    ? `Este documento reúne ${plural(files.length, 'arquivo')}. Cada arquivo tem os próprios resumos.`
                                    : `Este documento reúne ${plural(files.length, 'arquivo')}. O resumo final de cada um é publicado quando o documento é concluído.`
                                : completed
                                  ? 'Cada resumo identifica bytes diferentes do mesmo documento.'
                                  : 'O resumo do arquivo final é publicado quando o documento é concluído.'
                        }
                    />
                    {multi ? (
                        <ol className="flex flex-col gap-2">
                            {files.map((file) => (
                                <li
                                    key={file.position}
                                    className="border-border rounded-lg border p-3 text-[12.5px]"
                                >
                                    <p className="font-semibold">
                                        {fileName(file)}
                                        {file.pages > 0 && (
                                            <span className="text-muted-foreground font-normal">
                                                {' '}
                                                · {plural(file.pages, 'página')}
                                            </span>
                                        )}
                                    </p>
                                    <HashLine
                                        label="Enviado"
                                        value={file.sent_sha256}
                                    />
                                    <HashLine
                                        label="Final"
                                        value={file.final_sha256}
                                        empty="publicado na conclusão"
                                    />
                                </li>
                            ))}
                            {result.hash_primer && (
                                <p className="text-muted-foreground text-[12px] leading-[1.5]">
                                    {result.hash_primer}
                                </p>
                            )}
                        </ol>
                    ) : (
                        <HashList
                            entries={hashEntries}
                            primerText={result.hash_primer}
                        />
                    )}
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
                    {/* Fase 2 §2.12: com o nome mascarado, sem CPF nem série. */}
                    {result.participant_signatures &&
                        result.participant_signatures.length > 0 && (
                            <PublicParticipantSignatureList
                                signatures={result.participant_signatures}
                                profile={result.signature_profile ?? policy}
                                revocationLabel={
                                    result.validation?.available
                                        ? result.validation.revocation_label
                                        : null
                                }
                            />
                        )}
                    {result.certificate && (
                        <div className="border-border rounded-lg border p-3.5">
                            <p className="mb-2 text-[12.5px] font-semibold">
                                {operatorLast
                                    ? 'Certificado da operadora (assinou por último)'
                                    : 'Certificado da operadora'}
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
                    {result.signature_status !== 'none' &&
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

                {result.timestamps && result.timestamps.length > 0 && (
                    <section className="flex flex-col gap-3">
                        <Heading variant="small" title="Carimbo do tempo" />
                        <TimestampList items={result.timestamps} />
                    </section>
                )}

                {/* Fase 3 §3.6: só quando a chave existir (decisão de produto pendente). */}
                {result.hash_history && result.hash_history.length > 0 && (
                    <section className="flex flex-col gap-3">
                        <Heading
                            variant="small"
                            title="Resumos anteriores do arquivo final"
                        />
                        <HashHistoryList
                            entries={result.hash_history}
                            notice={result.hash_history_notice}
                        />
                    </section>
                )}

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

/**
 * Fase 2 §2.19 — o registro foi excluído pela política de retenção da organização
 * (`RetentionTombstones::result`). Só o aviso, a data e, conforme a regra vigente, o resumo
 * SHA-256 do arquivo final: sem título, organização, participantes nem linha do tempo. O
 * selo de "concluído" não aparece — afirmar o estado de um registro que não existe mais
 * seria enganoso.
 */
function PurgedVerification({
    code,
    formattedCode,
    retention,
    hashEntries,
    primer,
    checkTargets,
    fileCheck,
    multi,
    files,
}: {
    code: string;
    formattedCode: string;
    retention: PublicRetentionNotice;
    hashEntries: HashEntry[];
    primer: string | null;
    checkTargets: FileCheckTarget[];
    fileCheck: VerifyShowProps['file_check'];
    multi: boolean;
    files: NonNullable<PublicVerificationResult['documents']>;
}) {
    return (
        <>
            <Head title={`Verificação · ${formattedCode}`} />

            <div className="border-border bg-card shadow-card flex flex-col gap-5 rounded-xl border p-6 md:p-8">
                <header className="flex flex-col gap-3">
                    <p className="text-muted-foreground text-[11px] font-bold tracking-[.18em] uppercase">
                        Verificação pública
                    </p>
                    <h1 className="text-[22px] leading-[1.2] font-bold tracking-[-.01em]">
                        Registro removido por política de retenção
                    </h1>
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

                <div className="border-neutral-border bg-neutral-bg text-text-secondary flex items-start gap-3 rounded-xl border p-4">
                    <span className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-white/70">
                        <ArchiveX className="size-[18px]" />
                    </span>
                    <div className="min-w-0">
                        <p className="text-foreground text-[15px] leading-[1.3] font-bold">
                            {retention.purged_at
                                ? `Removido por política de retenção em ${formatDateTime(retention.purged_at)}`
                                : 'Removido por política de retenção'}
                        </p>
                        <p className="mt-1.5 text-[12.5px] leading-[1.55]">
                            {retention.message}
                        </p>
                    </div>
                </div>

                {checkTargets.length > 0 ? (
                    <section className="flex flex-col gap-3">
                        <Heading
                            variant="small"
                            title="Resumo do arquivo final (SHA-256)"
                            description="Só o resumo do arquivo final foi mantido. Ele não revela o conteúdo nem identifica pessoas; serve para quem guardou uma cópia conferir se ela é o arquivo emitido."
                        />
                        {multi ? (
                            <ol className="flex flex-col gap-2">
                                {files.map((file) => (
                                    <li
                                        key={file.position}
                                        className="border-border rounded-lg border p-3 text-[12.5px]"
                                    >
                                        <p className="font-semibold">
                                            {file.position}. {file.name}
                                        </p>
                                        <HashLine
                                            label="Final"
                                            value={file.final_sha256}
                                        />
                                    </li>
                                ))}
                            </ol>
                        ) : (
                            <HashList
                                entries={hashEntries}
                                primerText={primer}
                            />
                        )}
                        <FileCheck
                            targets={checkTargets}
                            manual={{
                                action: verifyCheckFile(code).url,
                                result: fileCheck ?? null,
                            }}
                        />
                    </section>
                ) : (
                    <p className="text-muted-foreground text-[12.5px] leading-[1.5]">
                        Nenhum resumo foi mantido para este registro.
                    </p>
                )}

                <Button asChild variant="outline" className="self-start">
                    <Link href={verifyIndex()}>Verificar outro código</Link>
                </Button>
            </div>
        </>
    );
}

/** Um resumo de um arquivo (Fase 2 §2.3), com cópia. */
function HashLine({
    label,
    value,
    empty = '—',
}: {
    label: string;
    value: string | null;
    empty?: string;
}) {
    return (
        <div className="mt-1.5 grid grid-cols-[64px_1fr] items-start gap-2">
            <span className="text-muted-foreground">{label}</span>
            {value ? (
                <span className="flex min-w-0 items-start gap-1">
                    <code className="min-w-0 font-mono text-[11.5px] break-all">
                        {value}
                    </code>
                    <CopyButton value={value} className="size-5 shrink-0" />
                </span>
            ) : (
                <span className="text-muted-foreground">{empty}</span>
            )}
        </div>
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
