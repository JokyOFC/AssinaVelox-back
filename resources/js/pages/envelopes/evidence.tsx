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
    AcceptanceAction,
    AuditEvent,
    Envelope,
    IdentityCaptureEvidence,
    ParticipantRole,
    RecipientStatus,
    SignatureKind,
    SignatureStatus,
    SignerAuthMethod,
} from '@/types';

/** Participante como `App\Services\Verification\EvidenceDossier::recipients` o publica. */
export interface EvidenceRecipient {
    name: string;
    email: string;
    role: string | null;
    status: RecipientStatus;
    status_label: string;
    /** Fase 2 §2.9: pode trazer `sms_otp`/`whatsapp_otp` e `sender_pin`. */
    auth_methods: SignerAuthMethod[];
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
    /** Fase 2 §2.4 (aditivos). */
    participant_role?: ParticipantRole;
    participant_role_label?: string;
    acceptance_action?: AcceptanceAction | null;
    acceptance_action_label?: string | null;
    /** Fase 2 §2.3: o que o aceite desta pessoa cobriu, arquivo por arquivo. */
    accepted_documents?: {
        document_id: string | null;
        position: number;
        name: string | null;
        sha256: string | null;
    }[];
    /**
     * Fase 2 §2.10 (`CaptureEvidence::forEnvelope`): fotos da captura simples, só para o
     * remetente (nunca na verificação pública nem no PDF de evidências).
     */
    identity_captures?: IdentityCaptureEvidence[];
    /** Fase 2 §2.9: rótulo do método confirmado e canal do aviso extra do convite. */
    auth_method_label?: string;
    /** Revisão da onda B: o código saiu pelo simulador (nada transmitido). */
    auth_method_simulated?: boolean;
    auth_method_note?: string | null;
    delivery_channel?: 'email' | 'sms' | 'whatsapp';
    /**
     * Fase 2 §2.6 (`InPersonEvidence::forEnvelope`): aceite registrado no
     * dispositivo presencial. O autor continua sendo o participante.
     */
    in_person?: {
        label: string;
        host_name: string | null;
        device_label: string;
        session_started_at: string | null;
        accepted_at: string | null;
    } | null;
}

/**
 * Arquivo do envelope no dossiê (`EvidenceDossier::documents`, Fase 2 §2.3): os resumos
 * de cada arquivo e quem registrou aceite sobre ele.
 */
export interface EvidenceDocument {
    id: string;
    position: number;
    name: string | null;
    original_name: string | null;
    hashes: {
        original_sha256: string | null;
        sent_sha256: string | null;
        consolidated_sha256: string | null;
        evidence_sha256: string | null;
        final_sha256: string | null;
    };
    accepted_by: {
        name: string | null;
        participant_role: ParticipantRole | null;
        action: AcceptanceAction | null;
        action_label: string | null;
        accepted_at: string | null;
        document_sha256: string | null;
    }[];
    downloads: { signed: string | null; evidence: string | null };
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
    /** Fase 2 §2.3: um item por arquivo (com um arquivo, a página é a da Fase 1). */
    documents?: EvidenceDocument[];
    /** Fase 2 §2.10: nota do servidor sobre as fotos (não houve verificação de identidade). */
    identity_capture_notice?: string | null;
}

/** Texto usado se o servidor não mandar `identity_capture_notice` (docs/fase-2/identidade.md §5.5). */
const CAPTURE_EVIDENCE_NOTICE =
    'Não houve verificação de identidade: a plataforma não compara rostos, não analisa a imagem e não lê o documento fotografado.';

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
    documents = [],
    identity_capture_notice = null,
}: EvidenceProps) {
    const multi = documents.length > 1;
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

    /*
     * Fase 2 §2.3: com vários arquivos o arquivo local pode ser QUALQUER um deles. Os
     * finais são os alvos "registrados" (verde); enviado e consolidado de cada um são
     * etapas intermediárias, com a mesma explicação da versão de um arquivo.
     */
    const checkTargets: FileCheckTarget[] = multi
        ? documents.flatMap((file) => {
              const name = fileTitle(file);
              const { final_sha256, sent_sha256, consolidated_sha256 } =
                  file.hashes;

              return [
                  ...(final_sha256
                      ? [
                            {
                                key: `final-${file.id}`,
                                label: `Arquivo final de ${name}`,
                                sha256: final_sha256,
                                canonical: true,
                            },
                        ]
                      : []),
                  ...(sent_sha256 && sent_sha256 !== final_sha256
                      ? [
                            {
                                key: `sent-${file.id}`,
                                label: `documento enviado (${name})`,
                                sha256: sent_sha256,
                                hint: 'É a versão apresentada aos participantes, antes dos campos preenchidos e da página de evidências.',
                            },
                        ]
                      : []),
                  ...(consolidated_sha256 &&
                  consolidated_sha256 !== final_sha256
                      ? [
                            {
                                key: `consolidated-${file.id}`,
                                label: `documento consolidado (${name})`,
                                sha256: consolidated_sha256,
                                hint: 'É o PDF com os campos achatados, antes da página de evidências e da assinatura da operadora.',
                            },
                        ]
                      : []),
              ];
          })
        : singleTargets;

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
                            {multi && (
                                <Row
                                    label="Arquivos"
                                    value={plural(documents.length, 'arquivo')}
                                />
                            )}
                            {!multi && envelope.document && (
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
                                                {recipient.participant_role &&
                                                    recipient.participant_role !==
                                                        'signer' && (
                                                        <span className="border-primary-soft-border bg-primary-soft text-primary rounded-md border px-1.5 py-px text-[11px] font-semibold">
                                                            {recipient.participant_role_label ??
                                                                recipient.participant_role}
                                                        </span>
                                                    )}
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
                                                    {recipient.auth_method_note && (
                                                        <span className="text-warning block font-semibold">
                                                            {
                                                                recipient.auth_method_note
                                                            }
                                                        </span>
                                                    )}
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
                                                        <Field
                                                            label={
                                                                recipient.acceptance_action ===
                                                                'approve'
                                                                    ? 'Aprovação registrada'
                                                                    : 'Aceite registrado'
                                                            }
                                                        >
                                                            {formatDateTime(
                                                                recipient.accepted_at ??
                                                                    recipient.signed_at,
                                                            )}
                                                        </Field>
                                                        <Field label="Representação visual">
                                                            {recipient.acceptance_action ===
                                                            'approve'
                                                                ? 'Nenhuma — aprovação eletrônica, sem representação visual'
                                                                : recipient.signature_kind
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
                                                        {recipient.acceptance_action &&
                                                            recipient.acceptance_action !==
                                                                'sign' &&
                                                            recipient.acceptance_action_label && (
                                                                <Field label="Registro">
                                                                    {
                                                                        recipient.acceptance_action_label
                                                                    }
                                                                </Field>
                                                            )}
                                                        {(recipient
                                                            .accepted_documents
                                                            ?.length ?? 0) >
                                                            1 && (
                                                            <Field label="Arquivos aceitos">
                                                                <ul className="flex flex-col gap-0.5">
                                                                    {recipient.accepted_documents?.map(
                                                                        (
                                                                            item,
                                                                        ) => (
                                                                            <li
                                                                                key={`${item.position}-${item.document_id}`}
                                                                                className="min-w-0"
                                                                            >
                                                                                {
                                                                                    item.position
                                                                                }
                                                                                .{' '}
                                                                                {item.name ??
                                                                                    `Arquivo ${item.position}`}
                                                                                {item.sha256 && (
                                                                                    <span className="text-muted-foreground block font-mono break-all">
                                                                                        {
                                                                                            item.sha256
                                                                                        }
                                                                                    </span>
                                                                                )}
                                                                            </li>
                                                                        ),
                                                                    )}
                                                                </ul>
                                                            </Field>
                                                        )}
                                                        {recipient.terms_version && (
                                                            <Field label="Texto aceito">
                                                                {
                                                                    recipient.terms_version
                                                                }
                                                            </Field>
                                                        )}
                                                        {recipient.in_person && (
                                                            <Field label="Modo">
                                                                {
                                                                    recipient
                                                                        .in_person
                                                                        .label
                                                                }
                                                                {' · '}
                                                                {
                                                                    recipient
                                                                        .in_person
                                                                        .device_label
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

                                            {(recipient.identity_captures
                                                ?.length ?? 0) > 0 && (
                                                <div className="mt-3 flex flex-col gap-2">
                                                    <p className="text-[12.5px] font-semibold">
                                                        {/* Genérico: a origem (declarada pelo navegador) vai em cada legenda. */}
                                                        Imagens enviadas pelo
                                                        participante
                                                    </p>
                                                    <div className="flex flex-wrap gap-3">
                                                        {recipient.identity_captures?.map(
                                                            (capture) => (
                                                                <figure
                                                                    key={
                                                                        capture.id
                                                                    }
                                                                    className="border-border w-[168px] rounded-[10px] border p-2"
                                                                >
                                                                    {capture.thumbnail &&
                                                                    capture.available ? (
                                                                        <img
                                                                            src={
                                                                                capture.thumbnail
                                                                            }
                                                                            alt={`${capture.kind_label} enviada por ${recipient.name}`}
                                                                            className="bg-muted h-[112px] w-full rounded object-contain"
                                                                        />
                                                                    ) : (
                                                                        <div className="bg-muted text-muted-foreground flex h-[112px] items-center justify-center rounded p-2 text-center text-[11px] leading-[1.4]">
                                                                            {capture.purged_at
                                                                                ? `Arquivo apagado pela retenção em ${formatDateTime(capture.purged_at)}`
                                                                                : 'Imagem indisponível'}
                                                                        </div>
                                                                    )}
                                                                    <figcaption className="mt-1.5 text-[11.5px] leading-[1.4]">
                                                                        <span className="block font-semibold">
                                                                            {
                                                                                capture.kind_label
                                                                            }
                                                                        </span>
                                                                        {capture.source_label && (
                                                                            <span className="text-muted-foreground block">
                                                                                {
                                                                                    capture.source_label
                                                                                }
                                                                            </span>
                                                                        )}
                                                                        <span className="text-muted-foreground block">
                                                                            {formatDateTime(
                                                                                capture.captured_at,
                                                                            )}
                                                                            {capture.width &&
                                                                                capture.height &&
                                                                                ` · ${capture.width}×${capture.height}`}
                                                                        </span>
                                                                        {capture.sha256 && (
                                                                            <span
                                                                                className="text-muted-foreground block truncate font-mono text-[10.5px]"
                                                                                title={
                                                                                    capture.sha256
                                                                                }
                                                                            >
                                                                                SHA-256{' '}
                                                                                {
                                                                                    capture.sha256
                                                                                }
                                                                            </span>
                                                                        )}
                                                                    </figcaption>
                                                                </figure>
                                                            ),
                                                        )}
                                                    </div>
                                                    <p className="text-muted-foreground text-[11.5px] leading-[1.5]">
                                                        {identity_capture_notice ??
                                                            CAPTURE_EVIDENCE_NOTICE}
                                                    </p>
                                                </div>
                                            )}

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

                    {multi && <EvidenceFiles documents={documents} />}

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
                            description={
                                multi
                                    ? 'Resumos do primeiro arquivo. Os de cada arquivo estão em “Documentos deste envelope”; a conferência abaixo aceita qualquer um deles.'
                                    : 'Cada resumo identifica bytes diferentes do mesmo documento.'
                            }
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

function fileTitle(file: { position: number; name: string | null }): string {
    return `${file.position}. ${file.name?.trim() || `Arquivo ${file.position}`}`;
}

/**
 * "Documentos deste envelope (N)" — Fase 2 §2.3. Para cada arquivo, os resumos (mesmos
 * rótulos e explicações da lista do envelope) e quem registrou aceite sobre ele, com o
 * resumo exato da versão aceita. É o espelho da seção homônima da página de evidências
 * em PDF (docs/fase-2/multi-documento-e-papeis.md §9).
 */
function EvidenceFiles({ documents }: { documents: EvidenceDocument[] }) {
    return (
        <section className="border-border bg-card shadow-card rounded-xl border p-5">
            <Heading
                variant="small"
                title={`Documentos deste envelope (${documents.length})`}
                description="Cada arquivo tem versões e resumos próprios. Um único aceite de cada participante cobre o conjunto, registrado arquivo por arquivo."
                className="mb-3"
            />
            <div className="flex flex-col gap-3">
                {documents.map((file) => (
                    <article
                        key={file.id}
                        className="border-border rounded-[10px] border p-4"
                    >
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div className="min-w-0">
                                <p className="font-semibold">
                                    {fileTitle(file)}
                                </p>
                                {file.original_name &&
                                    file.original_name !== file.name && (
                                        <p className="text-muted-foreground text-[12px]">
                                            {file.original_name}
                                        </p>
                                    )}
                            </div>
                            <span className="flex flex-wrap gap-1.5">
                                {file.downloads.signed && (
                                    <Button asChild variant="outline" size="xs">
                                        <a href={file.downloads.signed}>
                                            <Download className="size-3.5" />
                                            Arquivo final
                                        </a>
                                    </Button>
                                )}
                                {file.downloads.evidence && (
                                    <Button asChild variant="outline" size="xs">
                                        <a href={file.downloads.evidence}>
                                            <Download className="size-3.5" />
                                            Evidências
                                        </a>
                                    </Button>
                                )}
                            </span>
                        </div>

                        <HashList
                            className="mt-3"
                            entries={[
                                {
                                    kind: 'original',
                                    value: file.hashes.original_sha256,
                                },
                                {
                                    kind: 'sent',
                                    value: file.hashes.sent_sha256,
                                },
                                {
                                    kind: 'consolidated',
                                    value: file.hashes.consolidated_sha256,
                                },
                                {
                                    kind: 'evidence',
                                    value: file.hashes.evidence_sha256,
                                },
                                {
                                    kind: 'final',
                                    value: file.hashes.final_sha256,
                                },
                            ]}
                        />

                        <div className="mt-3">
                            <p className="text-muted-foreground text-[11px] font-semibold tracking-[.08em] uppercase">
                                Registros sobre este arquivo
                            </p>
                            {file.accepted_by.length === 0 ? (
                                <p className="text-muted-foreground mt-1 text-[12.5px]">
                                    Nenhum aceite registrado ainda.
                                </p>
                            ) : (
                                <ul className="mt-1 flex flex-col text-[12.5px]">
                                    {file.accepted_by.map((entry, index) => (
                                        <li
                                            key={`${entry.name}-${index}`}
                                            className="border-muted flex flex-col gap-0.5 border-t py-1.5 first:border-t-0"
                                        >
                                            <span>
                                                <b>{entry.name ?? '—'}</b>
                                                <span className="text-muted-foreground">
                                                    {' · '}
                                                    {entry.action_label ??
                                                        'Aceite eletrônico'}
                                                    {entry.accepted_at &&
                                                        ` · ${formatDateTime(entry.accepted_at)}`}
                                                </span>
                                            </span>
                                            {entry.document_sha256 && (
                                                <span className="text-muted-foreground font-mono text-[11.5px] break-all">
                                                    {entry.document_sha256}
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </article>
                ))}
            </div>
        </section>
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
