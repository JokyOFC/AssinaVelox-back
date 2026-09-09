import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Download, Eye, Info } from 'lucide-react';
import type { ReactNode } from 'react';
import { AvatarInitials, recipientTone } from '@/components/avatar-initials';
import Heading from '@/components/heading';
import { PageHeader } from '@/components/page-header';
import { RecipientStatusBadge } from '@/components/status/recipient-status-badge';
import { Timeline } from '@/components/timeline';
import { Button } from '@/components/ui/button';
import {
    FileCheck,
    type FileCheckTarget,
} from '@/components/verification/file-check';
import {
    entriesFromServer,
    HashList,
    type HashEntry,
    type ServerHashItem,
} from '@/components/verification/hash-list';
import {
    CertificateDetails,
    SignatureStatement,
    ValidationDetails,
    type VerificationCertificate,
    type VerificationValidation,
} from '@/components/verification/signature-statement';
import { VerificationCodeBlock } from '@/components/verification/verification-code';
import { VerificationSealCard } from '@/components/verification/verification-seal';
import {
    formatBytes,
    formatDateTime,
    formatVerificationCode,
    initials as initialsOf,
    plural,
} from '@/lib/format';
import {
    authMethodLabels,
    signatureKindLabels,
    signingOrderLabels,
} from '@/lib/labels';
import {
    evidence as envelopeEvidence,
    index as envelopesIndex,
    show as envelopeShow,
} from '@/routes/envelopes';
import type {
    AuditEvent,
    AuthMethod,
    Envelope,
    RecipientStatus,
    SignatureKind,
    SignatureStatus,
} from '@/types';

/** Participante como `App\Services\Verification\EvidenceDossier::recipients` o publica. */
export interface EvidenceRecipient {
    name: string;
    email: string;
    role: string | null;
    status: RecipientStatus;
    status_label: string;
    auth_methods: AuthMethod[];
    signature_kind: SignatureKind | null;
    signature_image_url: string | null;
    sent_at: string | null;
    /** Alias histórico de `opened_at`; o rótulo honesto é `opened_label`. */
    viewed_at: string | null;
    opened_at?: string | null;
    opened_label?: string | null;
    otp_verified_at: string | null;
    signed_at: string | null;
    accepted_at?: string | null;
    refused_at: string | null;
    refusal_reason: string | null;
    /** Já ajustado por `settings.evidence_show_ip` (pode vir mascarado ou nulo). */
    ip: string | null;
    ip_policy?: string | null;
    user_agent: string | null;
    geo_label: string | null;
    /** Texto exato da declaração aceita, como apareceu na tela. */
    consent_text: string | null;
    terms_version?: string | null;
    /** SHA-256 da versão que esta pessoa viu ao aceitar. */
    document_sha256?: string | null;
}

export interface EvidenceProps {
    envelope: Pick<
        Envelope,
        | 'id'
        | 'display_code'
        | 'verification_code'
        | 'title'
        | 'status'
        | 'status_label'
        | 'created_at'
        | 'sent_at'
        | 'completed_at'
        | 'document'
        | 'downloads'
        | 'signing_order'
    >;
    organization: {
        name: string;
        legal_name: string | null;
        tax_id_masked: string | null;
    };
    recipients: EvidenceRecipient[];
    events: AuditEvent[];
    hashes: {
        /** Alias do resumo **enviado** (é o rótulo impresso "Documento enviado"). */
        original_sha256: string;
        signed_sha256: string | null;
        evidence_sha256: string | null;
        /** Os quatro resumos com rótulo e explicação (`HashLedger::items`). */
        items?: ServerHashItem[];
        primer?: string | null;
    };
    signature_status: SignatureStatus;
    signature?: {
        state: 'pending' | 'none' | 'company_a1';
        status: SignatureStatus;
        label: string;
        statement: string;
        profile: string | null;
    };
    validation?: VerificationValidation | null;
    certificate: VerificationCertificate | null;
    notes?: {
        opened_vs_read?: string;
        hashes?: string;
        not_a_certificate?: string;
    };
    verify_url: string;
}

/**
 * Dossiê de evidências do envelope (ROUTES §2.8; DESIGN §4.18 e §6.4).
 *
 * Somente leitura. A linguagem segue arquitetura §2: aceite eletrônico é a
 * manifestação de vontade; a assinatura criptográfica, quando existe, é da
 * **operadora**; e o que a trilha registra como "abertura" é o acesso ao link,
 * não a leitura do documento. As frases que precisam ser ditas com estas
 * palavras vêm do servidor em `notes` — a tela não as reescreve.
 */
export default function EnvelopeEvidence({
    envelope,
    organization,
    recipients,
    events,
    hashes,
    signature_status,
    signature,
    validation,
    certificate,
    notes,
    verify_url,
}: EvidenceProps) {
    const items = hashes.items ?? [];
    const byKey = (key: string) =>
        items.find((item) => item.key === key)?.value ?? null;

    const finalHash = byKey('final') ?? hashes.signed_sha256;
    const sentHash = byKey('sent') ?? hashes.original_sha256 ?? null;
    const consolidatedHash = byKey('consolidated');
    const policy = certificate?.policy ?? signature?.profile ?? null;

    /*
     * Com `items` do servidor, os textos são os mesmos do relatório em PDF; sem eles
     * (contrato antigo), a lista cai nas descrições locais. O resumo do relatório de
     * evidências entra **antes** do final porque é essa a ordem do pipeline: o
     * relatório é anexado ao consolidado e só então o arquivo final existe.
     */
    const serverEntries = entriesFromServer(items);
    const evidenceEntry: HashEntry = {
        kind: 'evidence',
        value: hashes.evidence_sha256,
    };
    const finalIndex = serverEntries.findIndex(
        (entry) => entry.kind === 'final',
    );
    const hashEntries: HashEntry[] =
        serverEntries.length > 0
            ? [
                  ...serverEntries.slice(
                      0,
                      finalIndex === -1 ? serverEntries.length : finalIndex,
                  ),
                  evidenceEntry,
                  ...(finalIndex === -1 ? [] : serverEntries.slice(finalIndex)),
              ]
            : [
                  { kind: 'sent', value: sentHash, label: 'Documento enviado' },
                  evidenceEntry,
                  { kind: 'final', value: finalHash },
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
                      hint: 'É a versão apresentada aos signatários, antes dos campos preenchidos e do relatório de evidências.',
                  },
              ]
            : []),
        ...(consolidatedHash && consolidatedHash !== finalHash
            ? [
                  {
                      key: 'consolidated',
                      label: 'documento consolidado',
                      sha256: consolidatedHash,
                      hint: 'É o PDF com os campos achatados, antes do relatório de evidências e da assinatura da operadora.',
                  },
              ]
            : []),
    ];

    return (
        <>
            <Head title={`Evidências · ${envelope.title}`} />

            <PageHeader
                size="detail"
                leading={
                    <Button
                        asChild
                        variant="outline"
                        size="icon-sm"
                        aria-label="Voltar ao documento"
                    >
                        <Link href={envelopeShow(envelope.id)}>
                            <ArrowLeft className="size-4" />
                        </Link>
                    </Button>
                }
                title="Evidências do aceite eletrônico"
                subtitle={`${envelope.display_code} · ${envelope.title} · ${organization.name}`}
                actions={
                    <Button asChild disabled={!envelope.downloads.evidence}>
                        <a href={envelope.downloads.evidence ?? '#'}>
                            <Download className="size-[15px]" />
                            Baixar relatório (PDF)
                        </a>
                    </Button>
                }
            />

            <VerificationSealCard
                status={envelope.status}
                signatureStatus={signature_status}
                policy={policy}
            />

            <div className="flex flex-wrap items-start gap-4">
                <div className="flex min-w-0 flex-[1.4_1_420px] flex-col gap-4">
                    <section className="border-border bg-card shadow-card rounded-xl border p-5">
                        <Heading
                            variant="small"
                            title="Identificação"
                            description="Dados do envelope como registrados pela plataforma."
                            className="mb-3"
                        />
                        <dl className="grid gap-x-6 text-[13px] sm:grid-cols-2">
                            <Row
                                label="Documento"
                                value={envelope.display_code}
                            />
                            <Row label="Estado" value={envelope.status_label} />
                            <Row
                                label="Organização"
                                value={
                                    organization.legal_name ?? organization.name
                                }
                            />
                            {organization.tax_id_masked && (
                                <Row
                                    label="CNPJ/CPF"
                                    value={organization.tax_id_masked}
                                />
                            )}
                            <Row
                                label="Criado em"
                                value={formatDateTime(envelope.created_at)}
                            />
                            <Row
                                label="Enviado em"
                                value={formatDateTime(envelope.sent_at)}
                            />
                            <Row
                                label="Concluído em"
                                value={formatDateTime(envelope.completed_at)}
                            />
                            <Row
                                label="Ordem"
                                value={
                                    signingOrderLabels[envelope.signing_order]
                                }
                            />
                            {envelope.document && (
                                <Row
                                    label="Arquivo"
                                    value={`${envelope.document.original_name} · ${formatBytes(envelope.document.size_bytes)} · ${plural(envelope.document.pages, 'página')}`}
                                />
                            )}
                        </dl>
                    </section>

                    <section className="border-border bg-card shadow-card rounded-xl border p-5">
                        <Heading
                            variant="small"
                            title="Participantes"
                            description="Cada linha reúne o que foi registrado sobre a manifestação de vontade daquela pessoa."
                        />
                        <div className="mt-4 flex flex-col gap-3">
                            {recipients.map((recipient) => (
                                <article
                                    key={recipient.email}
                                    className="border-border rounded-[10px] border p-4"
                                >
                                    <div className="flex items-start gap-3">
                                        <AvatarInitials
                                            initials={initialsOf(
                                                recipient.name,
                                            )}
                                            tone={recipientTone(
                                                recipient.status,
                                            )}
                                            size="xl"
                                        />
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-semibold">
                                                    {recipient.name}
                                                </span>
                                                {recipient.role && (
                                                    <span className="text-muted-foreground text-[12px]">
                                                        · {recipient.role}
                                                    </span>
                                                )}
                                                <RecipientStatusBadge
                                                    status={recipient.status}
                                                    label={
                                                        recipient.status_label
                                                    }
                                                />
                                            </div>
                                            <div className="text-muted-foreground text-[12.5px]">
                                                {recipient.email}
                                            </div>

                                            <dl className="tabular mt-3 grid grid-cols-[132px_1fr] gap-x-3 gap-y-1 text-[12.5px]">
                                                <Field label="Autenticação">
                                                    {recipient.auth_methods
                                                        .map(
                                                            (method) =>
                                                                authMethodLabels[
                                                                    method
                                                                ],
                                                        )
                                                        .join(', ')}
                                                    {recipient.otp_verified_at &&
                                                        ` · confirmada em ${formatDateTime(recipient.otp_verified_at)}`}
                                                </Field>
                                                <Field label="Convite enviado">
                                                    {formatDateTime(
                                                        recipient.sent_at,
                                                    )}
                                                </Field>
                                                <Field label="Link aberto">
                                                    {(() => {
                                                        const openedAt =
                                                            recipient.opened_at ??
                                                            recipient.viewed_at;
                                                        const label =
                                                            recipient.opened_label ??
                                                            (openedAt
                                                                ? 'Abertura detectada (não comprova leitura)'
                                                                : 'Nenhuma abertura do link detectada');

                                                        // Sem carimbo, só o rótulo: "— · Nenhuma
                                                        // abertura detectada" é ruído.
                                                        return openedAt ? (
                                                            <>
                                                                {formatDateTime(
                                                                    openedAt,
                                                                )}
                                                                <span className="text-muted-foreground">
                                                                    {' · '}
                                                                    {label}
                                                                </span>
                                                            </>
                                                        ) : (
                                                            <span className="text-muted-foreground">
                                                                {label}
                                                            </span>
                                                        );
                                                    })()}
                                                </Field>
                                                {recipient.signed_at && (
                                                    <>
                                                        <Field label="Aceite registrado">
                                                            {formatDateTime(
                                                                recipient.accepted_at ??
                                                                    recipient.signed_at,
                                                            )}
                                                        </Field>
                                                        <Field label="Representação visual">
                                                            {recipient.signature_kind
                                                                ? signatureKindLabels[
                                                                      recipient
                                                                          .signature_kind
                                                                  ]
                                                                : '—'}
                                                        </Field>
                                                        <Field label="IP · dispositivo">
                                                            <span className="break-all">
                                                                {recipient.ip ??
                                                                    'não registrado'}
                                                                {recipient.user_agent &&
                                                                    ` · ${recipient.user_agent}`}
                                                            </span>
                                                        </Field>
                                                        {recipient.document_sha256 && (
                                                            <Field label="Versão aceita">
                                                                <span className="font-mono break-all">
                                                                    {
                                                                        recipient.document_sha256
                                                                    }
                                                                </span>
                                                            </Field>
                                                        )}
                                                        {recipient.terms_version && (
                                                            <Field label="Texto aceito">
                                                                {
                                                                    recipient.terms_version
                                                                }
                                                            </Field>
                                                        )}
                                                    </>
                                                )}
                                                {recipient.refused_at && (
                                                    <Field label="Recusa">
                                                        {formatDateTime(
                                                            recipient.refused_at,
                                                        )}
                                                        {recipient.refusal_reason &&
                                                            ` · “${recipient.refusal_reason}”`}
                                                    </Field>
                                                )}
                                            </dl>

                                            {recipient.signature_image_url && (
                                                <figure className="mt-3">
                                                    <img
                                                        src={
                                                            recipient.signature_image_url
                                                        }
                                                        alt={`Representação visual da assinatura de ${recipient.name}`}
                                                        className="border-border h-14 rounded border bg-white p-1"
                                                    />
                                                    <figcaption className="text-muted-foreground mt-1 text-[11.5px]">
                                                        Representação visual —
                                                        não prova, por si, a
                                                        autoria; a manifestação
                                                        de vontade é o aceite
                                                        registrado acima.
                                                    </figcaption>
                                                </figure>
                                            )}

                                            {recipient.consent_text && (
                                                <div className="bg-sidebar mt-3 rounded-lg p-2.5">
                                                    <p className="text-muted-foreground text-[11px] font-semibold tracking-[.08em] uppercase">
                                                        Declaração aceita
                                                    </p>
                                                    <blockquote className="text-text-secondary mt-1 text-[12px] leading-[1.5]">
                                                        {recipient.consent_text}
                                                    </blockquote>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </article>
                            ))}
                        </div>
                    </section>

                    <section className="border-border bg-card shadow-card rounded-xl border p-5">
                        <Heading
                            variant="small"
                            title="Trilha de auditoria"
                            description={`${plural(events.length, 'evento registrado', 'eventos registrados')}, na ordem em que aconteceram.`}
                            className="mb-3"
                        />
                        <p className="border-primary-soft-border bg-primary-soft text-primary mb-4 flex items-start gap-2 rounded-[10px] border p-3 text-[12px] leading-[1.5]">
                            <Eye className="mt-0.5 size-3.5 shrink-0" />
                            <span>
                                <b>Abertura detectada não é leitura.</b>{' '}
                                {notes?.opened_vs_read ??
                                    'A trilha registra que alguém pediu a URL do convite, e nada além disso. O que se comprova é o aceite eletrônico, com data, IP, navegador e o código confirmado por e-mail.'}
                            </span>
                        </p>
                        <Timeline events={events} markers="icon" showNotes />
                    </section>
                </div>

                <div className="flex min-w-0 flex-[1_1_340px] flex-col gap-4">
                    <section className="border-border bg-card shadow-card flex flex-col gap-3 rounded-xl border p-5">
                        <Heading
                            variant="small"
                            title="Situação da assinatura"
                            description={signature?.label}
                        />
                        <SignatureStatement
                            status={signature_status}
                            certificate={certificate}
                            statement={signature?.statement}
                            verifyLabel="assinavelox.com.br/verificar"
                            verificationCode={formatVerificationCode(
                                envelope.verification_code,
                            )}
                            /*
                             * Sem `validationSummary`: o bloco "Resultado técnico da
                             * validação" logo abaixo traz o mesmo resultado por extenso,
                             * e repetir a frase-resumo dentro da declaração só duplicaria
                             * texto. Na página pública, onde esse bloco não existe, o
                             * resumo continua dentro da declaração.
                             */
                        />
                        {certificate && (
                            <div className="border-border rounded-lg border p-3.5">
                                <p className="mb-2 text-[12.5px] font-semibold">
                                    Certificado da operadora
                                </p>
                                <CertificateDetails certificate={certificate} />
                            </div>
                        )}
                        {validation && (
                            <div className="border-border rounded-lg border p-3.5">
                                <p className="mb-2 text-[12.5px] font-semibold">
                                    Resultado técnico da validação
                                </p>
                                <ValidationDetails validation={validation} />
                            </div>
                        )}
                    </section>

                    <section className="border-border bg-card shadow-card flex flex-col gap-3 rounded-xl border p-5">
                        <Heading
                            variant="small"
                            title="Integridade (SHA-256)"
                            description="Cada resumo identifica bytes diferentes do mesmo documento."
                        />
                        <HashList
                            entries={hashEntries}
                            primerText={hashes.primer ?? notes?.hashes}
                        />
                        {checkTargets.length > 0 && (
                            <FileCheck
                                targets={checkTargets}
                                title="Conferir um arquivo local"
                            />
                        )}
                    </section>

                    <section className="border-border bg-card shadow-card flex flex-col gap-3 rounded-xl border p-5">
                        <Heading
                            variant="small"
                            title="Verificação pública"
                            description="Qualquer pessoa com o código confere o estado do documento — sem ver o conteúdo nem dados pessoais."
                        />
                        <VerificationCodeBlock
                            code={envelope.verification_code}
                            url={verify_url}
                        />
                    </section>

                    <p className="text-muted-foreground flex items-start gap-2 px-1 text-[11.5px] leading-[1.5]">
                        <Info className="mt-[1px] size-3.5 shrink-0" />
                        <span>
                            {notes?.not_a_certificate ??
                                'Esta página não é um certificado digital nem é emitida por autoridade certificadora, e não substitui a análise das partes sobre a validade do ato documentado.'}
                        </span>
                    </p>
                </div>
            </div>
        </>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="border-muted flex justify-between gap-3 border-b py-2">
            <dt className="text-muted-foreground shrink-0">{label}</dt>
            <dd className="tabular min-w-0 text-right break-words">{value}</dd>
        </div>
    );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="contents">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0">{children}</dd>
        </div>
    );
}

EnvelopeEvidence.layout = (props: EvidenceProps) => ({
    breadcrumbs: [
        { title: 'Documentos', href: envelopesIndex() },
        { title: props.envelope.title, href: envelopeShow(props.envelope.id) },
        { title: 'Evidências', href: envelopeEvidence(props.envelope.id) },
    ],
});
