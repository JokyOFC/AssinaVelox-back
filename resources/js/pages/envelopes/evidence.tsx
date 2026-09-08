import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Download, ShieldCheck } from 'lucide-react';
import { AvatarInitials, recipientTone } from '@/components/avatar-initials';
import { CopyButton } from '@/components/copy-button';
import Heading from '@/components/heading';
import { PageHeader } from '@/components/page-header';
import { RecipientStatusBadge } from '@/components/status/recipient-status-badge';
import { HashBox, Timeline } from '@/components/timeline';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    formatDateTime,
    formatVerificationCode,
    initials as initialsOf,
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
    recipients: {
        name: string;
        email: string;
        role: string | null;
        status: RecipientStatus;
        status_label: string;
        auth_methods: AuthMethod[];
        signature_kind: SignatureKind | null;
        signature_image_url: string | null;
        sent_at: string | null;
        viewed_at: string | null;
        otp_verified_at: string | null;
        signed_at: string | null;
        refused_at: string | null;
        refusal_reason: string | null;
        ip: string | null;
        user_agent: string | null;
        geo_label: string | null;
        consent_text: string | null;
    }[];
    events: AuditEvent[];
    hashes: {
        original_sha256: string;
        signed_sha256: string | null;
        evidence_sha256: string | null;
    };
    signature_status: SignatureStatus;
    certificate: {
        subject: string;
        issuer: string;
        serial: string;
        valid_from: string;
        valid_to: string;
        policy: string;
    } | null;
    verify_url: string;
}

/** Página de evidências (ROUTES §2.8) — somente leitura. Versão inicial; refinada na etapa seguinte. */
export default function EnvelopeEvidence({
    envelope,
    organization,
    recipients,
    events,
    hashes,
    signature_status,
    certificate,
    verify_url,
}: EvidenceProps) {
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
                        aria-label="Voltar"
                    >
                        <Link href={envelopeShow(envelope.id)}>
                            <ArrowLeft className="size-4" />
                        </Link>
                    </Button>
                }
                title="Evidências do aceite eletrônico"
                subtitle={`${envelope.display_code} · ${envelope.title} · ${organization.name}`}
                actions={
                    envelope.downloads.evidence && (
                        <Button asChild>
                            <a href={envelope.downloads.evidence}>
                                <Download className="size-[15px]" />
                                Baixar relatório (PDF)
                            </a>
                        </Button>
                    )
                }
            />

            <div className="border-primary-soft-border bg-primary-soft text-primary rounded-[10px] border p-3.5 text-[12.5px] leading-[1.5]">
                {signature_status === 'company_a1'
                    ? 'O PDF final foi assinado criptograficamente com o certificado A1 da AssinaVelox (operadora). Isso identifica a operadora e protege a integridade do arquivo; não é assinatura pessoal ICP-Brasil de cada participante.'
                    : 'Este documento foi concluído como aceite eletrônico com evidências, sem assinatura criptográfica. As evidências abaixo comprovam a manifestação de vontade de cada participante.'}
            </div>

            <div className="flex flex-wrap items-start gap-4">
                <div className="flex min-w-0 flex-[1.4_1_420px] flex-col gap-4">
                    <div className="border-border bg-card shadow-card rounded-xl border p-5">
                        <Heading
                            variant="small"
                            title="Participantes"
                            description={`Ordem: ${signingOrderLabels[envelope.signing_order]}`}
                        />
                        <div className="mt-4 flex flex-col gap-3">
                            {recipients.map((r) => (
                                <div
                                    key={r.email}
                                    className="border-border rounded-[10px] border p-4"
                                >
                                    <div className="flex items-start gap-3">
                                        <AvatarInitials
                                            initials={initialsOf(r.name)}
                                            tone={recipientTone(r.status)}
                                            size="xl"
                                        />
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-semibold">
                                                    {r.name}
                                                </span>
                                                {r.role && (
                                                    <span className="text-muted-foreground text-[12px]">
                                                        · {r.role}
                                                    </span>
                                                )}
                                                <RecipientStatusBadge
                                                    status={r.status}
                                                    label={r.status_label}
                                                />
                                            </div>
                                            <div className="text-muted-foreground text-[12.5px]">
                                                {r.email}
                                            </div>
                                            <dl className="tabular mt-3 grid grid-cols-[130px_1fr] gap-x-3 gap-y-1 text-[12.5px]">
                                                <dt className="text-muted-foreground">
                                                    Autenticação
                                                </dt>
                                                <dd>
                                                    {r.auth_methods
                                                        .map(
                                                            (m) =>
                                                                authMethodLabels[
                                                                    m
                                                                ],
                                                        )
                                                        .join(', ')}
                                                    {r.otp_verified_at &&
                                                        ` · confirmada em ${formatDateTime(r.otp_verified_at)}`}
                                                </dd>
                                                <dt className="text-muted-foreground">
                                                    Convite enviado
                                                </dt>
                                                <dd>
                                                    {formatDateTime(r.sent_at)}
                                                </dd>
                                                <dt className="text-muted-foreground">
                                                    Link aberto
                                                </dt>
                                                <dd>
                                                    {formatDateTime(
                                                        r.viewed_at,
                                                    )}
                                                </dd>
                                                {r.signed_at && (
                                                    <>
                                                        <dt className="text-muted-foreground">
                                                            Aceite registrado
                                                        </dt>
                                                        <dd>
                                                            {formatDateTime(
                                                                r.signed_at,
                                                            )}
                                                        </dd>
                                                        <dt className="text-muted-foreground">
                                                            Assinatura
                                                        </dt>
                                                        <dd>
                                                            {r.signature_kind
                                                                ? signatureKindLabels[
                                                                      r
                                                                          .signature_kind
                                                                  ]
                                                                : '—'}
                                                        </dd>
                                                        <dt className="text-muted-foreground">
                                                            IP · dispositivo
                                                        </dt>
                                                        <dd className="break-all">
                                                            {r.ip ?? '—'}
                                                            {r.user_agent &&
                                                                ` · ${r.user_agent}`}
                                                        </dd>
                                                    </>
                                                )}
                                                {r.refused_at && (
                                                    <>
                                                        <dt className="text-muted-foreground">
                                                            Recusa
                                                        </dt>
                                                        <dd>
                                                            {formatDateTime(
                                                                r.refused_at,
                                                            )}
                                                            {r.refusal_reason &&
                                                                ` · “${r.refusal_reason}”`}
                                                        </dd>
                                                    </>
                                                )}
                                            </dl>
                                            {r.signature_image_url && (
                                                <img
                                                    src={r.signature_image_url}
                                                    alt={`Assinatura de ${r.name}`}
                                                    className="border-border mt-3 h-14 rounded border bg-white p-1"
                                                />
                                            )}
                                            {r.consent_text && (
                                                <p className="bg-sidebar text-text-secondary mt-3 rounded-lg p-2.5 text-[12px] leading-[1.5]">
                                                    “{r.consent_text}”
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="border-border bg-card shadow-card rounded-xl border p-5">
                        <Heading
                            variant="small"
                            title="Trilha de auditoria"
                            description={`${events.length} eventos registrados`}
                            className="mb-4"
                        />
                        <Timeline events={events} />
                    </div>
                </div>

                <div className="flex min-w-0 flex-[1_1_340px] flex-col gap-4">
                    <div className="border-border bg-card shadow-card flex flex-col gap-2 rounded-xl border p-5">
                        <Heading
                            variant="small"
                            title="Integridade"
                            description="Hashes SHA-256 dos arquivos."
                            className="mb-1"
                        />
                        <HashBox
                            label="Documento enviado"
                            value={hashes.original_sha256}
                            action={
                                <CopyButton
                                    value={hashes.original_sha256}
                                    className="size-6"
                                />
                            }
                        />
                        {hashes.signed_sha256 && (
                            <HashBox
                                label="PDF final"
                                value={hashes.signed_sha256}
                                action={
                                    <CopyButton
                                        value={hashes.signed_sha256}
                                        className="size-6"
                                    />
                                }
                            />
                        )}
                        {hashes.evidence_sha256 && (
                            <HashBox
                                label="Relatório de evidências"
                                value={hashes.evidence_sha256}
                                action={
                                    <CopyButton
                                        value={hashes.evidence_sha256}
                                        className="size-6"
                                    />
                                }
                            />
                        )}
                    </div>

                    <div className="border-border bg-card shadow-card flex flex-col gap-3 rounded-xl border p-5">
                        <Heading variant="small" title="Verificação pública" />
                        <div className="border-border bg-sidebar flex items-center justify-between gap-2 rounded-lg border p-3">
                            <span className="tabular font-mono text-[15px] font-bold">
                                {formatVerificationCode(
                                    envelope.verification_code,
                                )}
                            </span>
                            <CopyButton
                                value={formatVerificationCode(
                                    envelope.verification_code,
                                )}
                                className="size-6"
                            />
                        </div>
                        <Button asChild variant="outline" size="sm">
                            <a
                                href={verify_url}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <ShieldCheck className="size-[15px]" />
                                Abrir página de verificação
                            </a>
                        </Button>
                    </div>

                    <div className="border-border bg-card shadow-card flex flex-col gap-2 rounded-xl border p-5">
                        <Heading
                            variant="small"
                            title="Certificado da operadora"
                            action={
                                <Badge
                                    variant={
                                        signature_status === 'company_a1'
                                            ? 'success'
                                            : 'neutral'
                                    }
                                >
                                    {signature_status === 'company_a1'
                                        ? 'Assinado'
                                        : 'Sem assinatura'}
                                </Badge>
                            }
                        />
                        {certificate ? (
                            <dl className="grid grid-cols-[110px_1fr] gap-x-3 gap-y-1.5 text-[12.5px]">
                                <dt className="text-muted-foreground">
                                    Titular
                                </dt>
                                <dd className="break-words">
                                    {certificate.subject}
                                </dd>
                                <dt className="text-muted-foreground">
                                    Emissor
                                </dt>
                                <dd className="break-words">
                                    {certificate.issuer}
                                </dd>
                                <dt className="text-muted-foreground">Série</dt>
                                <dd className="font-mono break-all">
                                    {certificate.serial}
                                </dd>
                                <dt className="text-muted-foreground">
                                    Validade
                                </dt>
                                <dd className="tabular">
                                    {formatDateTime(certificate.valid_from)} →{' '}
                                    {formatDateTime(certificate.valid_to)}
                                </dd>
                                <dt className="text-muted-foreground">
                                    Perfil
                                </dt>
                                <dd>{certificate.policy}</dd>
                            </dl>
                        ) : (
                            <p className="text-muted-foreground text-[13px]">
                                Nenhum certificado aplicado a este documento.
                            </p>
                        )}
                        {organization.legal_name && (
                            <p className="text-muted-foreground mt-2 text-[12px]">
                                Remetente: {organization.legal_name}
                                {organization.tax_id_masked &&
                                    ` · ${organization.tax_id_masked}`}
                            </p>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

EnvelopeEvidence.layout = (props: EvidenceProps) => ({
    breadcrumbs: [
        { title: 'Documentos', href: envelopesIndex() },
        { title: props.envelope.title, href: envelopeShow(props.envelope.id) },
        { title: 'Evidências', href: envelopeEvidence(props.envelope.id) },
    ],
});
