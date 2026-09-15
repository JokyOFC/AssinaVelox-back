import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    ChevronDown,
    Copy as CopyIcon,
    Download,
    ExternalLink,
    FileText,
    Mail,
    MessageCircle,
    MessageSquare,
    MoreHorizontal,
    Pencil,
    RefreshCw,
    ShieldCheck,
    XCircle,
} from 'lucide-react';
import { Fragment, useState, type ReactNode } from 'react';
import { toast } from 'sonner';
import { AvatarInitials, recipientTone } from '@/components/avatar-initials';
import { SendBatchLinkButton } from '@/components/batch/send-batch-link-button';
import { StartInPersonLink } from '@/components/in-person/start-in-person-link';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DossierButton } from '@/components/dossier/dossier-buttons';
import { useEnvelopeLegalHold } from '@/components/envelopes/use-envelope-legal-hold';
import { EnvelopeFlowPanel } from '@/components/envelopes/steps/flow-panel';
import { EnvelopeLegalHoldPanel } from '@/components/retention/envelope-legal-hold-panel';
import { IdentityVideoPanel } from '@/components/identity/identity-video-panel';
import { PreservedBadge } from '@/components/retention/preserved-badge';
import type { EnvelopeLegalHold } from '@/components/retention/types';
import { ParticipantSignatureList } from '@/components/verification/crypto-signature-list';
import {
    FieldLayer,
    type LayerField,
} from '@/components/envelopes/field-layer';
import { recipientColor } from '@/components/envelopes/recipient-colors';
import { ScheduleSendCard } from '@/components/envelopes/schedule-send-card';
import { documentName } from '@/components/envelopes/wizard-document-list';
import { DocumentSwitcher } from '@/components/pdf/document-switcher';
import { DEFAULT_ZOOM } from '@/components/pdf/pdf-zoom-controls';
import { PdfViewer } from '@/components/pdf/pdf-viewer';
import { CopyButton } from '@/components/copy-button';
import { PageHeader } from '@/components/page-header';
import { SegmentedControl } from '@/components/segmented-control';
import { EnvelopeStatusBadge } from '@/components/status/envelope-status-badge';
import { RecipientStatusBadge } from '@/components/status/recipient-status-badge';
import { HashBox, Timeline } from '@/components/timeline';
import {
    TestCertificateWarning,
    type VerificationCertificate,
} from '@/components/verification/signature-statement';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    formatBytes,
    formatDateMedium,
    formatDateTime,
    formatProgress,
    formatVerificationCode,
    plural,
} from '@/lib/format';
import {
    authMethodLabels,
    deliveryChannelLabels,
    fieldTypeLabels,
    hasCryptographicSignature,
    hasExternalParticipantSignatures,
    isGovBrReturn,
    hasParticipantSignatures,
    participantRoleLabels,
    signingOrderLabels,
} from '@/lib/labels';
import { cn } from '@/lib/utils';
import {
    cancel as envelopeCancel,
    destroy as envelopeDestroy,
    duplicate as envelopeDuplicate,
    edit as envelopeEdit,
    evidence as envelopeEvidence,
    index as envelopesIndex,
    resend as envelopeResend,
    show as envelopeShow,
} from '@/routes/envelopes';
import {
    resend as resendRecipient,
    update as updateRecipient,
} from '@/routes/envelopes/recipients';
import { preview as documentPreview } from '@/routes/envelopes/document';
import { show as verifyShow } from '@/routes/verify';
import type {
    AuditEvent,
    Envelope,
    EnvelopeFile,
    EnvelopeReminders,
    FolderRef,
    Recipient,
    SignatureStatus,
    SigningField,
    EvidenceParticipantSignature,
} from '@/types';

type Tab = 'signers' | 'audit' | 'details';

/** Campo do detalhe adaptado ao contrato da camada (somente leitura). */
interface ShowLayerField extends LayerField {
    value: string | null;
    signed: boolean;
}

export interface EnvelopeShowProps {
    /**
     * `signature_status` e `certificate` só existem depois da finalização. São
     * opcionais para que a tela nunca *deduza* que houve assinatura
     * criptográfica: sem o dado, a linguagem usada é a de aceite eletrônico
     * com evidências (arquitetura §2).
     */
    envelope: Envelope & {
        signature_status?: SignatureStatus | null;
        certificate?: VerificationCertificate | null;
    };
    recipients: Recipient[];
    fields: SigningField[];
    events: AuditEvent[];
    folders: FolderRef[];
    sent: boolean;
    tab: Tab;
    /**
     * Fase 2 §2.5 (`ReminderProps::forEnvelope`). Ainda não exposta por
     * `EnvelopeController::show`; ausente, a aba mostra "Lembretes: manuais" (Fase 1).
     */
    reminders?: EnvelopeReminders | null;
    /**
     * Fase 2 §2.19 (`RetentionPresenter::forEnvelope`). Ainda não exposta pelo
     * controller: sem ela, o detalhe consulta `GET envelopes.legal_hold.show`.
     */
    legal_hold?: EnvelopeLegalHold | null;
    /**
     * Fase 2 §2.12 (`ParticipantSignatureViews::forEvidence`), opcional: sem ela o detalhe
     * mostra só o resumo e remete às evidências, onde a lista completa é publicada.
     */
    participant_signatures?: EvidenceParticipantSignature[];
    /**
     * Fase 2 §2.16 (`WebhookPresenter::envelopeSummary`): última entrega de webhook deste
     * documento. Ausente com a flag desligada ou sem endpoint ativo — tela da Fase 1.
     */
    webhook?: EnvelopeWebhookSummary | null;
}

export interface EnvelopeWebhookSummary {
    status: string | null;
    status_label: string;
    event_label: string | null;
    at: string | null;
    /** Histórico do endpoint; só para quem gerencia a API e as integrações. */
    href: string | null;
}

/**
 * Detalhe do documento (ROUTES §2.7; DESIGN §6.4): cabeçalho com status,
 * banner por estado, visualizador (placeholder até a integração com PDF.js)
 * e abas Signatários / Trilha / Detalhes.
 *
 * Fase 2 (tudo condicionado ao que existe no envelope, não à flag — um envelope
 * enviado com vários arquivos continua sendo mostrado assim mesmo se a flag desligar):
 * seletor de arquivo com downloads por arquivo (§2.3), papel de cada participante
 * (§2.4) e estado dos lembretes/agendamento (§2.5).
 */
export default function EnvelopeShow({
    envelope,
    recipients,
    fields,
    events,
    sent,
    tab,
    reminders,
    legal_hold = null,
    participant_signatures = [],
    webhook = null,
}: EnvelopeShowProps) {
    const { errors } = usePage().props;
    const [currentTab, setCurrentTab] = useState<Tab>(tab ?? 'signers');
    // Fase 2 §2.19: selo "Preservado" e o bloco de preservação.
    const legalHold = useEnvelopeLegalHold(envelope.id, legal_hold);
    const preserved = legalHold?.preserved === true;
    // Fase 2 §2.12: "assinado" vale para a operadora e para o participante com certificado.
    const signedFile = hasCryptographicSignature(envelope.signature_status);
    const [page, setPage] = useState(1);
    const [zoom, setZoom] = useState(DEFAULT_ZOOM);

    // Fase 2 §2.3: com mais de um arquivo, o visualizador ganha um seletor.
    const files: EnvelopeFile[] = envelope.documents ?? [];
    const multi = files.length > 1;
    const [fileId, setFileId] = useState<string | null>(files[0]?.id ?? null);
    const currentFile =
        files.find((file) => file.id === fileId) ?? files[0] ?? null;
    const fileOf = (field: SigningField): string | null =>
        field.document_id ?? files[0]?.id ?? null;

    const remindersOn = reminders?.available === true;
    const rolesInUse = recipients.some(
        (recipient) =>
            recipient.participant_role !== undefined &&
            recipient.participant_role !== 'signer',
    );

    // Campos sobrepostos ao PDF: somente leitura, com a cor do destinatário.
    const recipientById = new Map(
        recipients.map((recipient) => [recipient.id, recipient]),
    );
    const pageFields: ShowLayerField[] = fields
        .filter((field) => field.page !== 'all' && Number(field.page) === page)
        .filter((field) => !multi || fileOf(field) === currentFile?.id)
        .map((field) => ({
            client_id: field.id,
            recipient_client_id: field.recipient_id,
            type: field.type,
            page: field.page,
            x: field.x,
            y: field.y,
            w: field.w,
            h: field.h,
            value: field.value,
            signed: field.signed,
        }));
    const [editing, setEditing] = useState<Recipient | null>(null);
    const [cancelOpen, setCancelOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);

    const isDraft = ['draft', 'preparing', 'ready'].includes(envelope.status);
    // Visualizador nunca é pendência (Fase 2 §2.4).
    const pendingCount = recipients.filter(
        (r) =>
            r.participant_role !== 'viewer' &&
            ['pending', 'notified', 'viewed'].includes(r.status),
    ).length;

    const resendOne = (recipient: Recipient) => {
        router.post(
            resendRecipient({ envelope: envelope.id, recipient: recipient.id })
                .url,
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(`Convite reenviado para ${recipient.name}`),
                onError: () =>
                    toast.error('Aguarde alguns minutos antes de reenviar'),
            },
        );
    };

    const resendAll = () => {
        router.post(
            envelopeResend(envelope.id).url,
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(`Convites reenviados (${pendingCount})`),
            },
        );
    };

    const confirmCancel = () => {
        setBusy(true);
        router.post(
            envelopeCancel(envelope.id).url,
            { reason: reason || undefined },
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusy(false);
                    setCancelOpen(false);
                },
            },
        );
    };

    const confirmDelete = () => {
        setBusy(true);
        router.delete(envelopeDestroy(envelope.id).url, {
            onFinish: () => setBusy(false),
        });
    };

    const verifyUrl = envelope.verification_code
        ? verifyShow(envelope.verification_code).url
        : null;

    return (
        <>
            <Head title={envelope.title} />

            <PageHeader
                size="detail"
                leading={
                    <Button
                        asChild
                        variant="outline"
                        size="icon-sm"
                        aria-label="Voltar"
                    >
                        <Link href={envelopesIndex()}>
                            <ArrowLeft className="size-4" />
                        </Link>
                    </Button>
                }
                title={envelope.title}
                badge={
                    <span className="inline-flex flex-wrap items-center gap-1.5">
                        <EnvelopeStatusBadge
                            status={envelope.status}
                            signedCount={envelope.signed_count}
                            label={
                                envelope.status === 'in_progress'
                                    ? `${envelope.status_label} · ${formatProgress(envelope.signed_count, envelope.recipients_count)}`
                                    : envelope.status_label
                            }
                            className="px-[9px] py-[3px]"
                        />
                        {preserved && (
                            <PreservedBadge
                                since={legalHold?.holds[0]?.starts_at}
                                until={legalHold?.holds[0]?.ends_at}
                                className="px-[9px] py-[3px]"
                            />
                        )}
                    </span>
                }
                subtitle={
                    <span className="tabular inline-flex flex-wrap items-center gap-1.5">
                        {envelope.display_code}
                        {envelope.folder && <> · {envelope.folder.name}</>}
                        {/* DESIGN §6.4: "… · Criado por Ana Ribeiro em 1 set 2026 · …" */}
                        <>
                            {' · '}
                            Criado por {envelope.creator.name} em{' '}
                            {formatDateMedium(envelope.created_at)}
                        </>
                        {envelope.expires_label && (
                            <>
                                {' · '}
                                <span
                                    className={cn(
                                        envelope.expiring_soon &&
                                            'text-warning font-semibold',
                                    )}
                                >
                                    {envelope.expires_label}
                                </span>
                            </>
                        )}
                    </span>
                }
                actions={
                    // DESIGN §6.4: [↓ Baixar ▾] [↻ Lembrar pendentes] [⋯] — "Baixar" é
                    // secundário e a ação primária (azul) é "Lembrar pendentes"
                    // (ou "Continuar edição" enquanto o documento é rascunho).
                    <>
                        {/* Fase 2 §2.6: só com a flag `in_person` (o componente decide). */}
                        {envelope.status === 'in_progress' && (
                            <StartInPersonLink
                                envelopeId={envelope.id}
                                size="default"
                            />
                        )}
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="outline"
                                    disabled={
                                        !envelope.downloads.original &&
                                        !envelope.downloads.signed
                                    }
                                >
                                    <Download className="size-[15px]" />
                                    Baixar
                                    <ChevronDown className="size-3.5 opacity-70" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                {multi ? (
                                    // Fase 2 §2.3: original, final e evidências de cada arquivo.
                                    files.map((file, index) => (
                                        <Fragment key={file.id}>
                                            {index > 0 && (
                                                <DropdownMenuSeparator />
                                            )}
                                            <DropdownMenuLabel className="text-muted-foreground max-w-[260px] truncate text-[11.5px]">
                                                {file.position}.{' '}
                                                {documentName(file)}
                                            </DropdownMenuLabel>
                                            <DropdownMenuItem
                                                asChild
                                                disabled={
                                                    !file.downloads.original
                                                }
                                            >
                                                <a
                                                    href={
                                                        file.downloads
                                                            .original ?? '#'
                                                    }
                                                >
                                                    Original
                                                </a>
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                asChild
                                                disabled={
                                                    !file.downloads.signed
                                                }
                                            >
                                                <a
                                                    href={
                                                        file.downloads.signed ??
                                                        '#'
                                                    }
                                                >
                                                    {signedFile
                                                        ? 'PDF final assinado'
                                                        : 'Arquivo final (PDF)'}
                                                </a>
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                asChild
                                                disabled={
                                                    !file.downloads.evidence
                                                }
                                            >
                                                <a
                                                    href={
                                                        file.downloads
                                                            .evidence ?? '#'
                                                    }
                                                >
                                                    Relatório de evidências
                                                </a>
                                            </DropdownMenuItem>
                                        </Fragment>
                                    ))
                                ) : (
                                    <>
                                        <DropdownMenuItem
                                            asChild
                                            disabled={
                                                !envelope.downloads.original
                                            }
                                        >
                                            <a
                                                href={
                                                    envelope.downloads
                                                        .original ?? '#'
                                                }
                                            >
                                                Documento original
                                            </a>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            asChild
                                            disabled={
                                                !envelope.downloads.signed
                                            }
                                        >
                                            <a
                                                href={
                                                    envelope.downloads.signed ??
                                                    '#'
                                                }
                                            >
                                                {/*
                                                 * O rótulo segue o `signature_status`, não o status do
                                                 * envelope: sem certificado da operadora não existe
                                                 * "PDF assinado" para oferecer (arquitetura §2).
                                                 */}
                                                {signedFile
                                                    ? 'PDF final assinado'
                                                    : 'Arquivo final (PDF)'}
                                            </a>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            asChild
                                            disabled={
                                                !envelope.downloads.evidence
                                            }
                                        >
                                            <a
                                                href={
                                                    envelope.downloads
                                                        .evidence ?? '#'
                                                }
                                            >
                                                Relatório de evidências (PDF)
                                            </a>
                                        </DropdownMenuItem>
                                    </>
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                        {envelope.can.resend && pendingCount > 0 && (
                            <Button onClick={resendAll}>
                                <Mail className="size-[15px]" />
                                Lembrar pendentes
                            </Button>
                        )}
                        {isDraft && envelope.can.update && (
                            <Button asChild>
                                <Link href={envelopeEdit(envelope.id)}>
                                    <Pencil className="size-[15px]" />
                                    Continuar edição
                                </Link>
                            </Button>
                        )}
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="outline"
                                    size="icon"
                                    aria-label="Mais ações"
                                >
                                    <MoreHorizontal className="size-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                {envelope.can.duplicate && (
                                    <DropdownMenuItem
                                        onSelect={() =>
                                            router.post(
                                                envelopeDuplicate(envelope.id)
                                                    .url,
                                            )
                                        }
                                    >
                                        Duplicar
                                    </DropdownMenuItem>
                                )}
                                <DropdownMenuItem asChild>
                                    <Link href={envelopeEvidence(envelope.id)}>
                                        <ShieldCheck className="size-3.5" />
                                        Ver evidências
                                    </Link>
                                </DropdownMenuItem>
                                {envelope.verification_code && (
                                    <>
                                        <DropdownMenuItem
                                            onSelect={() => {
                                                void navigator.clipboard?.writeText(
                                                    formatVerificationCode(
                                                        envelope.verification_code,
                                                    ),
                                                );
                                                toast.success(
                                                    'Código de verificação copiado',
                                                );
                                            }}
                                        >
                                            <CopyIcon className="size-3.5" />
                                            Copiar código de verificação
                                        </DropdownMenuItem>
                                        {verifyUrl && (
                                            <DropdownMenuItem asChild>
                                                <a
                                                    href={verifyUrl}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                >
                                                    <ExternalLink className="size-3.5" />
                                                    Abrir página pública de
                                                    verificação
                                                </a>
                                            </DropdownMenuItem>
                                        )}
                                    </>
                                )}
                                {(envelope.can.cancel ||
                                    envelope.can.delete) && (
                                    <DropdownMenuSeparator />
                                )}
                                {envelope.can.cancel &&
                                    envelope.status === 'in_progress' && (
                                        <DropdownMenuItem
                                            variant="destructive"
                                            onSelect={() => setCancelOpen(true)}
                                        >
                                            <XCircle className="size-3.5" />
                                            Cancelar documento
                                        </DropdownMenuItem>
                                    )}
                                {/* Fase 2 §2.19: documento preservado não pode ser excluído. */}
                                {envelope.can.delete &&
                                    isDraft &&
                                    !preserved && (
                                        <DropdownMenuItem
                                            variant="destructive"
                                            onSelect={() => setDeleteOpen(true)}
                                        >
                                            Excluir rascunho
                                        </DropdownMenuItem>
                                    )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </>
                }
            />

            {sent && envelope.status === 'in_progress' && (
                <StatusBanner tone="success" title="Enviado para assinatura">
                    {envelope.signing_order === 'sequential'
                        ? 'O primeiro signatário recebeu o convite por e-mail. Os demais serão avisados na sua vez.'
                        : 'Todos os signatários receberam o convite por e-mail.'}
                </StatusBanner>
            )}
            {/* Fase 2 §2.5: envio agendado de um rascunho pronto. */}
            {remindersOn &&
                reminders?.scheduled_send &&
                isDraft &&
                envelope.can.update && (
                    <ScheduleSendCard
                        envelopeId={envelope.id}
                        reminders={reminders}
                        canSchedule={false}
                        allowCreate={false}
                        errors={errors}
                    />
                )}
            {envelope.status === 'finalizing' && (
                <StatusBanner
                    tone="info"
                    title="Todos assinaram. Finalizando o documento…"
                >
                    {/*
                     * Três coisas que o texto anterior afirmava e que não são verdade aqui:
                     * (1) "PDF assinado" — a assinatura criptográfica da operadora só é
                     * aplicada quando há certificado ativo E adaptador configurado; sem
                     * isso o envelope conclui como aceite eletrônico com evidências
                     * (arquitetura §2); (2) "menos de um minuto" — a finalização é do
                     * incremento 4 e ainda não tem quem escute
                     * `EnvelopeReadyForFinalization`; (3) "atualiza automaticamente" —
                     * esta página não faz polling. O texto agora só descreve o estado.
                     */}
                    Os aceites foram registrados e o arquivo final está sendo
                    preparado. Recarregue a página para acompanhar.
                </StatusBanner>
            )}
            {envelope.status === 'completed' && (
                <CompletionPanel
                    envelope={envelope}
                    verifyUrl={verifyUrl}
                    simulated={participant_signatures.some(
                        (signature) => signature.simulated === true,
                    )}
                    portal={participant_signatures.some(
                        (signature) =>
                            signature.kind === 'participant_govbr' ||
                            signature.kind ===
                                'participant_external_unverified',
                    )}
                />
            )}
            {participant_signatures.length > 0 && (
                <section className="border-border bg-card shadow-card rounded-xl border p-5">
                    <ParticipantSignatureList
                        signatures={participant_signatures}
                        profile={envelope.certificate?.policy ?? null}
                    />
                </section>
            )}
            {envelope.status === 'refused' && (
                <StatusBanner tone="danger" title="Documento recusado">
                    {recipients.find((r) => r.status === 'refused')
                        ?.refusal_reason
                        ? `Motivo: “${recipients.find((r) => r.status === 'refused')?.refusal_reason}”`
                        : 'Um signatário recusou a assinatura.'}
                </StatusBanner>
            )}
            {(envelope.status === 'expired' ||
                envelope.status === 'canceled') && (
                <StatusBanner
                    tone="neutral"
                    title={
                        envelope.status === 'expired'
                            ? 'Prazo de assinatura encerrado'
                            : 'Documento cancelado'
                    }
                >
                    {envelope.status === 'canceled' && envelope.cancel_reason
                        ? `Motivo: “${envelope.cancel_reason}”`
                        : 'Duplique o documento para enviar novamente.'}
                </StatusBanner>
            )}

            <div className="flex flex-wrap items-start gap-4">
                <div className="min-w-0 flex-[1.4_1_420px]">
                    {multi && (
                        <DocumentSwitcher
                            className="mb-3"
                            items={files.map((file) => ({
                                id: file.id,
                                position: file.position,
                                name: documentName(file),
                                meta: `${plural(file.pages, 'página')} · ${plural(
                                    fields.filter(
                                        (field) => fileOf(field) === file.id,
                                    ).length,
                                    'campo',
                                )}`,
                                tone: file.sha256_final ? 'done' : 'default',
                            }))}
                            current={currentFile?.id ?? null}
                            onSelect={(id) => {
                                setFileId(id);
                                setPage(1);
                            }}
                        />
                    )}
                    {envelope.document ? (
                        <PdfViewer
                            key={multi ? currentFile?.id : undefined}
                            // A versão *exibível* vem de `envelopes.document.preview`;
                            // `downloads.original` entrega o arquivo como foi enviado, que
                            // para DOCX e imagem não é um PDF.
                            url={
                                multi && currentFile?.pdf_url
                                    ? currentFile.pdf_url
                                    : documentPreview(envelope.id).url
                            }
                            page={page}
                            onPageChange={setPage}
                            zoom={zoom}
                            onZoomChange={setZoom}
                            maxPageWidth={560}
                            stamp={
                                multi && currentFile
                                    ? `${envelope.display_code} · arq. ${currentFile.position}/${files.length} · pág. ${page}/${currentFile.pages}`
                                    : `${envelope.display_code} · pág. ${page}/${envelope.document.pages}`
                            }
                            toolbarEnd={
                                <Link
                                    href={envelopeEvidence(envelope.id)}
                                    className="text-primary inline-flex items-center gap-1.5 text-[13px] font-semibold"
                                >
                                    <ShieldCheck className="size-3.5" />
                                    Evidências
                                </Link>
                            }
                            overlay={(size) => (
                                <FieldLayer<ShowLayerField>
                                    fields={pageFields}
                                    page={size}
                                    readOnly
                                    selectedId={null}
                                    onSelect={() => undefined}
                                    onChange={() => undefined}
                                    onDelete={() => undefined}
                                    onDuplicate={() => undefined}
                                    colorOf={(field) =>
                                        recipientColor(
                                            recipientById.get(
                                                field.recipient_client_id,
                                            )?.color_index ?? 0,
                                        )
                                    }
                                    tagOf={(field) =>
                                        `${recipientById.get(field.recipient_client_id)?.name.split(' ')[0] ?? 'Signatário'} · ${fieldTypeLabels[field.type]}`
                                    }
                                    hintOf={(field) => field.value}
                                    variantOf={(field) =>
                                        field.signed ? 'signed' : 'pending'
                                    }
                                    footnoteOf={(field) =>
                                        field.signed
                                            ? 'Aceite registrado'
                                            : 'Aguardando assinatura'
                                    }
                                />
                            )}
                        />
                    ) : (
                        <div className="border-border bg-card shadow-card text-muted-foreground rounded-xl border p-6 text-center text-[13px]">
                            Nenhum arquivo enviado ainda.
                        </div>
                    )}
                    {multi && <FilesPanel files={files} signed={signedFile} />}
                    {!multi && envelope.document && (
                        <p className="text-muted-foreground mt-2 flex flex-wrap items-center gap-1.5 text-[12px]">
                            <FileText className="size-3.5" />
                            <span className="tabular">
                                {envelope.document.original_name} ·{' '}
                                {plural(envelope.document.pages, 'página')} ·{' '}
                                {formatBytes(envelope.document.size_bytes)}
                            </span>
                        </p>
                    )}
                </div>

                <div className="border-border bg-card shadow-card min-w-0 flex-[1_1_340px] rounded-xl border">
                    <div className="border-border border-b px-4 py-3">
                        <SegmentedControl
                            value={currentTab}
                            onChange={setCurrentTab}
                            options={[
                                {
                                    value: 'signers',
                                    label: rolesInUse
                                        ? 'Participantes'
                                        : 'Signatários',
                                    count: recipients.length,
                                },
                                {
                                    value: 'audit',
                                    label: 'Trilha',
                                    count: events.length,
                                },
                                { value: 'details', label: 'Detalhes' },
                            ]}
                        />
                    </div>

                    {currentTab === 'signers' && (
                        <div className="flex flex-col gap-3 p-4">
                            {/* DESIGN §6.4: linha "Ordem de assinatura: **sequencial**" no topo da aba. */}
                            <p className="text-text-secondary flex flex-wrap items-center gap-x-3 gap-y-1 text-[12.5px]">
                                <span>
                                    Ordem de assinatura:{' '}
                                    <span className="font-semibold">
                                        {signingOrderLabels[
                                            envelope.signing_order
                                        ].toLowerCase()}
                                    </span>
                                </span>
                                <span>
                                    Lembretes:{' '}
                                    <span className="font-semibold">
                                        {remindersOn && reminders
                                            ? reminders.summary
                                                  .charAt(0)
                                                  .toLowerCase() +
                                              reminders.summary.slice(1)
                                            : 'manuais'}
                                    </span>
                                </span>
                            </p>
                            {recipients.length === 0 && (
                                <p className="text-muted-foreground py-6 text-center text-[13px]">
                                    Nenhum signatário adicionado.
                                </p>
                            )}
                            {recipients.map((recipient) => (
                                <RecipientCard
                                    key={recipient.id}
                                    recipient={recipient}
                                    envelope={envelope}
                                    automatic={
                                        remindersOn
                                            ? (reminders?.recipients?.[
                                                  recipient.id
                                              ] ?? null)
                                            : null
                                    }
                                    onResend={() => resendOne(recipient)}
                                    onEdit={() => setEditing(recipient)}
                                />
                            ))}
                        </div>
                    )}

                    {currentTab === 'audit' && (
                        <div className="p-4">
                            {/* DESIGN §6.4: cabeçalho da aba + atalho para o relatório. */}
                            <div className="mb-3.5 flex items-start justify-between gap-3">
                                <div>
                                    <p className="text-[15px] font-semibold">
                                        Trilha de auditoria
                                    </p>
                                    <p className="text-muted-foreground mt-[3px] text-[12.5px]">
                                        Todos os eventos, com carimbo de tempo e
                                        IP
                                    </p>
                                </div>
                                <Button
                                    asChild={Boolean(
                                        envelope.downloads.evidence,
                                    )}
                                    variant="outline"
                                    size="xs"
                                    disabled={!envelope.downloads.evidence}
                                    title={
                                        envelope.downloads.evidence
                                            ? undefined
                                            : 'Disponível quando o documento for concluído'
                                    }
                                >
                                    {envelope.downloads.evidence ? (
                                        <a href={envelope.downloads.evidence}>
                                            <FileText className="size-3.5" />
                                            Relatório PDF
                                        </a>
                                    ) : (
                                        <span>
                                            <FileText className="size-3.5" />
                                            Relatório PDF
                                        </span>
                                    )}
                                </Button>
                            </div>
                            <Timeline
                                events={events}
                                markers="icon"
                                showNotes
                                footer={
                                    multi ? (
                                        <div className="mt-4 flex flex-col gap-2">
                                            {files.map((file) => {
                                                const value =
                                                    file.sha256_final ??
                                                    file.sha256_sent ??
                                                    file.sha256_original;

                                                return value ? (
                                                    <HashBox
                                                        key={file.id}
                                                        label={`SHA-256 ${file.sha256_final ? 'do PDF final' : file.sha256_sent ? 'do arquivo enviado' : 'do original'} · ${file.position}. ${documentName(file)}`}
                                                        value={value}
                                                        action={
                                                            <CopyButton
                                                                value={value}
                                                                className="size-6"
                                                            />
                                                        }
                                                    />
                                                ) : null;
                                            })}
                                            <Button
                                                asChild
                                                variant="link"
                                                size="sm"
                                                className="self-start"
                                            >
                                                <Link
                                                    href={envelopeEvidence(
                                                        envelope.id,
                                                    )}
                                                >
                                                    <ShieldCheck className="size-3.5" />
                                                    Ver página de evidências
                                                </Link>
                                            </Button>
                                        </div>
                                    ) : (
                                        envelope.document && (
                                            <div className="mt-4 flex flex-col gap-2">
                                                <HashBox
                                                    label="SHA-256 do documento enviado"
                                                    value={
                                                        envelope.document
                                                            .sha256_original
                                                    }
                                                    action={
                                                        <CopyButton
                                                            value={
                                                                envelope
                                                                    .document
                                                                    .sha256_original
                                                            }
                                                            className="size-6"
                                                        />
                                                    }
                                                />
                                                {envelope.document
                                                    .sha256_signed && (
                                                    <HashBox
                                                        label="SHA-256 do PDF final"
                                                        value={
                                                            envelope.document
                                                                .sha256_signed
                                                        }
                                                        action={
                                                            <CopyButton
                                                                value={
                                                                    envelope
                                                                        .document
                                                                        .sha256_signed
                                                                }
                                                                className="size-6"
                                                            />
                                                        }
                                                    />
                                                )}
                                                <Button
                                                    asChild
                                                    variant="link"
                                                    size="sm"
                                                    className="self-start"
                                                >
                                                    <Link
                                                        href={envelopeEvidence(
                                                            envelope.id,
                                                        )}
                                                    >
                                                        <ShieldCheck className="size-3.5" />
                                                        Ver página de evidências
                                                    </Link>
                                                </Button>
                                            </div>
                                        )
                                    )
                                }
                            />
                        </div>
                    )}

                    {currentTab === 'details' && (
                        <dl className="text-[13px]">
                            {[
                                ['ID', envelope.display_code],
                                [
                                    'Código de verificação',
                                    envelope.verification_code
                                        ? formatVerificationCode(
                                              envelope.verification_code,
                                          )
                                        : 'Gerado no envio',
                                ],
                                ['Status', envelope.status_label],
                                ['Criado por', envelope.creator.name],
                                [
                                    'Criado em',
                                    formatDateTime(envelope.created_at),
                                ],
                                [
                                    'Enviado em',
                                    envelope.sent_at
                                        ? formatDateTime(envelope.sent_at)
                                        : '—',
                                ],
                                [
                                    'Expira em',
                                    envelope.expires_at
                                        ? formatDateTime(envelope.expires_at)
                                        : '—',
                                ],
                                ['Pasta', envelope.folder?.name ?? '—'],
                                [
                                    multi ? 'Arquivos' : 'Arquivo',
                                    multi
                                        ? `${plural(files.length, 'arquivo')}: ${files.map((file) => `${file.position}. ${documentName(file)}`).join(' · ')}`
                                        : envelope.document
                                          ? `${envelope.document.original_name} · ${formatBytes(envelope.document.size_bytes)} · ${plural(envelope.document.pages, 'página')}`
                                          : '—',
                                ],
                                [
                                    'Ordem',
                                    signingOrderLabels[envelope.signing_order],
                                ],
                                ['Mensagem', envelope.message || '—'],
                                [
                                    'Cópia final para todos',
                                    envelope.send_copy_to_all ? 'Sim' : 'Não',
                                ],
                                ...((envelope.viewers_count ?? 0) > 0
                                    ? [
                                          [
                                              'Visualizadores',
                                              plural(
                                                  envelope.viewers_count ?? 0,
                                                  'pessoa recebe cópia',
                                                  'pessoas recebem cópia',
                                              ),
                                          ],
                                      ]
                                    : []),
                                ...(remindersOn && reminders
                                    ? [
                                          ['Lembretes', reminders.summary],
                                          [
                                              'Envio agendado',
                                              reminders.scheduled_send
                                                  ? `${reminders.scheduled_send.at_local} (${reminders.scheduled_send.timezone})`
                                                  : '—',
                                          ],
                                      ]
                                    : []),
                                ...(webhook
                                    ? [
                                          [
                                              'Webhook',
                                              <span key="webhook">
                                                  {[
                                                      webhook.status_label,
                                                      webhook.event_label,
                                                      webhook.at
                                                          ? formatDateTime(
                                                                webhook.at,
                                                            )
                                                          : null,
                                                  ]
                                                      .filter(Boolean)
                                                      .join(' · ')}
                                                  {webhook.href && (
                                                      <>
                                                          {' · '}
                                                          <Link
                                                              href={
                                                                  webhook.href
                                                              }
                                                              className="text-primary underline-offset-2 hover:underline"
                                                          >
                                                              Ver histórico
                                                          </Link>
                                                      </>
                                                  )}
                                              </span>,
                                          ] as [string, ReactNode],
                                      ]
                                    : []),
                            ].map(([label, value]) => (
                                <div
                                    key={label}
                                    className="border-muted grid grid-cols-[140px_1fr] gap-3 border-b px-5 py-[11px] last:border-b-0"
                                >
                                    <dt className="text-muted-foreground">
                                        {label}
                                    </dt>
                                    <dd className="tabular min-w-0 break-words">
                                        {value}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    )}
                </div>
            </div>

            {/* Fase 2 §2.19: sem a flag e sem preservação, o painel não renderiza nada. */}
            {legalHold && <EnvelopeLegalHoldPanel legalHold={legalHold} />}

            {/* Fase 3 §3.3 (F-FLOW): sem etapas nem delegações (ou flags desligadas), nada renderiza. */}
            <EnvelopeFlowPanel envelopeId={envelope.id} />

            {/* Fase 3 §3.3 (F-VIDEO): sem a flag `identity_video` ou sem vídeo, não renderiza nada. */}
            <IdentityVideoPanel envelopeId={envelope.id} />

            <ConfirmDialog
                open={cancelOpen}
                onOpenChange={setCancelOpen}
                destructive
                processing={busy}
                title="Cancelar este documento?"
                description="Os signatários pendentes serão avisados e o link de assinatura deixa de funcionar. Assinaturas já registradas ficam na trilha de auditoria."
                confirmLabel="Cancelar documento"
                onConfirm={confirmCancel}
            >
                <div className="grid gap-1.5">
                    <label
                        htmlFor="cancel-reason"
                        className="text-[13px] font-semibold"
                    >
                        Motivo{' '}
                        <span className="text-muted-foreground font-normal">
                            (opcional)
                        </span>
                    </label>
                    <Textarea
                        id="cancel-reason"
                        rows={3}
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        placeholder="Ex.: valores desatualizados"
                        maxLength={500}
                    />
                </div>
            </ConfirmDialog>

            <ConfirmDialog
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                destructive
                processing={busy}
                title="Excluir este rascunho?"
                description="O rascunho e o arquivo enviado serão removidos. Esta ação não pode ser desfeita."
                confirmLabel="Excluir rascunho"
                onConfirm={confirmDelete}
            />

            {editing && (
                <RecipientEditDialog
                    key={editing.id}
                    envelopeId={envelope.id}
                    recipient={editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

/**
 * Nota do rodape do card do signatario (ROUTES 6.2; DESIGN 6.4).
 *
 * "Abertura detectada" e nao "leu": o que o sistema registra e o acesso ao
 * link (arquitetura 4.1). Chamar isso de leitura seria afirmar algo que
 * nenhuma evidencia sustenta.
 */
function recipientNote(
    recipient: Recipient,
    envelope: Envelope,
    automatic: { sent: number; last_sent_at: string | null } | null = null,
): string {
    const reminder = automatic?.last_sent_at
        ? ` · último lembrete automático ${formatDateTime(automatic.last_sent_at)}`
        : recipient.last_resent_at
          ? ` · último lembrete ${formatDateTime(recipient.last_resent_at)}`
          : '';

    // Visualizador (Fase 2 §2.4): recebe cópia, não assina — nunca "aguarda a vez".
    if (recipient.participant_role === 'viewer') {
        if (recipient.status === 'viewed') {
            return `Abertura detectada em ${formatDateTime(recipient.viewed_at)} · somente leitura`;
        }

        if (recipient.status === 'notified') {
            return `Cópia para acompanhamento enviada em ${formatDateTime(recipient.sent_at)} · sem abertura detectada`;
        }

        if (recipient.status === 'pending') {
            return envelope.status === 'in_progress'
                ? 'Ainda não notificado'
                : 'Recebe o documento no envio, só para acompanhar';
        }
    }

    if (recipient.status === 'signed') {
        const approval =
            recipient.acceptance_action === 'approve' ||
            recipient.participant_role === 'approver';
        const noun = approval ? 'Aprovação registrada' : 'Aceite registrado';

        return [
            recipient.signed_at
                ? `${noun} em ${formatDateTime(recipient.signed_at)}`
                : noun,
            recipient.evidence?.ip ? `IP ${recipient.evidence.ip}` : null,
            recipient.evidence?.user_agent_label ?? null,
        ]
            .filter((part) => part !== null)
            .join(' · ');
    }

    if (recipient.status === 'refused') {
        return `Recusou em ${formatDateTime(recipient.refused_at)}${
            recipient.refusal_reason
                ? ` · Motivo: “${recipient.refusal_reason}”`
                : ''
        }`;
    }

    if (recipient.status === 'viewed') {
        return `Abertura detectada em ${formatDateTime(recipient.viewed_at)}${reminder}`;
    }

    if (recipient.status === 'notified') {
        return `Convite enviado em ${formatDateTime(recipient.sent_at)} · sem abertura detectada${reminder}`;
    }

    if (recipient.status === 'pending') {
        return envelope.status === 'in_progress' &&
            envelope.signing_order === 'sequential'
            ? `Aguarda a vez · ${recipient.order}º na ordem`
            : 'Ainda não notificado';
    }

    if (recipient.status === 'expired') {
        return envelope.expires_at
            ? `Prazo encerrado em ${formatDateTime(envelope.expires_at)}`
            : 'Prazo encerrado';
    }

    return 'Documento cancelado';
}

/** Card de um signatário na aba "Signatários" (DESIGN 6.4). */
function RecipientCard({
    recipient,
    envelope,
    automatic = null,
    onResend,
    onEdit,
}: {
    recipient: Recipient;
    envelope: Envelope;
    /** Lembretes automáticos já enviados a esta pessoa (Fase 2 §2.5). */
    automatic?: { sent: number; last_sent_at: string | null } | null;
    onResend: () => void;
    onEdit: () => void;
}) {
    const viewer = recipient.participant_role === 'viewer';
    const specialRole =
        recipient.participant_role !== undefined &&
        recipient.participant_role !== 'signer';
    // No sequencial, quem ainda não chegou na vez não tem link emitido:
    // reenviar não faria nada (RECONCILIACAO Q11).
    const awaitingTurn =
        !viewer &&
        recipient.status === 'pending' &&
        envelope.status === 'in_progress' &&
        envelope.signing_order === 'sequential';
    const remindable =
        recipient.can_resend &&
        (recipient.status === 'notified' || recipient.status === 'viewed');

    return (
        <div className="border-border rounded-[10px] border p-4">
            <div className="flex items-start gap-3">
                <AvatarInitials
                    initials={recipient.initials}
                    tone={recipientTone(recipient.status)}
                    size="xl"
                />
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span className="truncate text-[13.5px] font-semibold">
                            {recipient.name}
                        </span>
                        {specialRole && recipient.participant_role && (
                            <span className="border-primary-soft-border bg-primary-soft text-primary rounded-md border px-1.5 py-px text-[11px] font-semibold">
                                {recipient.participant_role_label ??
                                    participantRoleLabels[
                                        recipient.participant_role
                                    ]}
                            </span>
                        )}
                        <span className="text-muted-foreground text-[11.5px]">
                            {[
                                recipient.role,
                                envelope.signing_order === 'sequential' &&
                                !viewer
                                    ? `${recipient.order}º`
                                    : null,
                            ]
                                .filter((part) => part)
                                .join(' · ')}
                        </span>
                    </div>
                    <div className="text-text-secondary truncate text-[12.5px]">
                        {recipient.email}
                    </div>
                    <div className="mt-2 flex flex-wrap items-center gap-1.5">
                        <span className="bg-muted text-text-secondary inline-flex items-center gap-[5px] rounded-md px-[7px] py-0.5 text-[11.5px] font-semibold">
                            {recipient.channel === 'sms' ? (
                                <MessageSquare className="size-3" />
                            ) : recipient.channel === 'whatsapp' ? (
                                <MessageCircle className="size-3" />
                            ) : (
                                <Mail className="size-3" />
                            )}
                            {/* Fase 2 §2.9: o convite sai sempre por e-mail; SMS/WhatsApp somam um aviso. */}
                            {recipient.channel === 'email'
                                ? deliveryChannelLabels.email
                                : `E-mail + ${deliveryChannelLabels[recipient.channel]}`}
                        </span>
                        {recipient.auth_methods.map((method) => {
                            const pinStuck =
                                method === 'sender_pin' &&
                                (recipient.pin_state === 'blocked' ||
                                    recipient.pin_state === 'locked');

                            return (
                                <span
                                    key={method}
                                    className={cn(
                                        'inline-flex items-center gap-[5px] rounded-md px-[7px] py-0.5 text-[11.5px] font-semibold',
                                        pinStuck
                                            ? 'bg-warning-bg text-warning'
                                            : 'bg-muted text-text-secondary',
                                    )}
                                >
                                    <ShieldCheck className="size-3" />
                                    {authMethodLabels[method]}
                                    {pinStuck &&
                                        (recipient.pin_state === 'blocked'
                                            ? ' · bloqueado'
                                            : ' · bloqueado por alguns minutos')}
                                </span>
                            );
                        })}
                    </div>
                    {recipient.pin_state === 'blocked' && (
                        <p className="border-warning-border bg-warning-bg text-warning mt-2 rounded-[10px] border p-2.5 text-[12px] leading-[1.45]">
                            O PIN foi bloqueado depois de várias tentativas
                            incorretas e o participante não consegue mais abrir
                            o documento.
                            {recipient.can_edit &&
                                ' Use “Editar” para definir um PIN novo e combine-o com ele por fora do sistema.'}
                        </p>
                    )}
                </div>
                <RecipientStatusBadge
                    status={recipient.status}
                    label={recipient.status_label}
                    className="shrink-0"
                />
            </div>

            <div className="border-muted text-muted-foreground mt-2.5 flex flex-wrap items-center justify-between gap-2 border-t pt-2.5 text-[12px]">
                <span className="tabular min-w-0">
                    {recipientNote(recipient, envelope, automatic)}
                </span>
                <span className="flex shrink-0 gap-1.5">
                    {recipient.status === 'signed' && (
                        <Link
                            href={envelopeEvidence(envelope.id)}
                            className="text-primary font-semibold"
                        >
                            Ver evidências
                        </Link>
                    )}
                    {remindable && (
                        <Button
                            variant="outline-sm"
                            size="xxs"
                            onClick={onResend}
                        >
                            <RefreshCw className="size-3" />
                            {recipient.status === 'viewed'
                                ? 'Lembrar'
                                : 'Reenviar'}
                        </Button>
                    )}
                    {/* Fase 2 §2.7: só com a flag `batch_signing` (o componente decide). */}
                    {remindable && !viewer && (
                        <SendBatchLinkButton
                            envelopeId={envelope.id}
                            recipientId={recipient.id}
                            recipientName={recipient.name}
                            size="xs"
                        />
                    )}
                    {awaitingTurn && (
                        <Button
                            variant="outline-sm"
                            size="xxs"
                            disabled
                            title="O convite é enviado automaticamente quando chegar a vez deste signatário."
                        >
                            <RefreshCw className="size-3" />
                            Reenviar
                        </Button>
                    )}
                    {recipient.can_edit && (
                        <Button
                            variant="outline-sm"
                            size="xxs"
                            onClick={onEdit}
                        >
                            <Pencil className="size-3" />
                            Editar
                        </Button>
                    )}
                </span>
            </div>
        </div>
    );
}

type CodeMethod = Exclude<Recipient['auth_methods'][number], 'sender_pin'>;

const CODE_METHODS: CodeMethod[] = ['email_otp', 'sms_otp', 'whatsapp_otp'];

/**
 * Edição de um signatário pendente (ROUTES 2.7).
 *
 * Trocar o e-mail revoga na hora os links de acesso ativos (invariante do
 * serviço de destinatários) — o diálogo avisa antes de confirmar.
 */
function RecipientEditDialog({
    envelopeId,
    recipient,
    onClose,
}: {
    envelopeId: string;
    recipient: Recipient;
    onClose: () => void;
}) {
    // Revisão da onda B: depois do envio o remetente também corrige o celular,
    // troca o método do código e define um PIN novo (o PIN bloqueado manda o
    // participante "falar com quem enviou"). O servidor confere tudo de novo.
    const features = usePage().props.features;
    const hasPin = recipient.auth_methods.includes('sender_pin');
    const currentMethod: CodeMethod =
        recipient.auth_method ??
        (recipient.auth_methods.find((method) => method !== 'sender_pin') as
            | CodeMethod
            | undefined) ??
        'email_otp';
    const methodOptions = CODE_METHODS.filter(
        (method) =>
            method === 'email_otp' ||
            method === currentMethod ||
            Boolean(features?.sms_whatsapp),
    );
    const showChannel = methodOptions.length > 1;
    const showPin = hasPin && Boolean(features?.pin_auth);

    const { data, setData, transform, patch, processing, errors } = useForm({
        name: recipient.name,
        email: recipient.email,
        auth_method: currentMethod as string,
        phone: '',
        pin: '',
    });

    // Só vai ao servidor o que mudou: sem canal/PIN, o PATCH é o da Fase 1.
    transform((values) => ({
        name: values.name,
        email: values.email,
        ...(values.auth_method !== currentMethod
            ? { auth_method: values.auth_method }
            : {}),
        ...(values.phone.trim() !== '' ? { phone: values.phone.trim() } : {}),
        ...(values.pin.trim() !== '' ? { pin: values.pin.trim() } : {}),
    }));

    const needsPhone = data.auth_method !== 'email_otp';
    const channelChanged =
        data.auth_method !== currentMethod || data.phone.trim() !== '';

    const emailChanged =
        data.email.trim().toLowerCase() !== recipient.email.toLowerCase();

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-[420px]">
                <DialogHeader>
                    <DialogTitle>Editar signatário</DialogTitle>
                    <DialogDescription>
                        Só é possível editar quem ainda não assinou.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="recipient-name">Nome</Label>
                        <Input
                            id="recipient-name"
                            value={data.name}
                            aria-invalid={Boolean(errors.name)}
                            onChange={(event) =>
                                setData('name', event.target.value)
                            }
                        />
                        {errors.name && (
                            <p className="text-danger text-[12.5px]">
                                {errors.name}
                            </p>
                        )}
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="recipient-email">E-mail</Label>
                        <Input
                            id="recipient-email"
                            type="email"
                            value={data.email}
                            aria-invalid={Boolean(errors.email)}
                            onChange={(event) =>
                                setData('email', event.target.value)
                            }
                        />
                        {errors.email && (
                            <p className="text-danger text-[12.5px]">
                                {errors.email}
                            </p>
                        )}
                    </div>
                    {showChannel && (
                        <div className="grid gap-1.5">
                            <Label htmlFor="recipient-auth-method">
                                Código de confirmação
                            </Label>
                            <Select
                                value={data.auth_method}
                                onValueChange={(value) =>
                                    setData('auth_method', value)
                                }
                            >
                                <SelectTrigger
                                    id="recipient-auth-method"
                                    aria-invalid={Boolean(errors.auth_method)}
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {methodOptions.map((method) => (
                                        <SelectItem key={method} value={method}>
                                            {authMethodLabels[method]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {errors.auth_method && (
                                <p className="text-danger text-[12.5px]">
                                    {errors.auth_method}
                                </p>
                            )}
                        </div>
                    )}
                    {needsPhone && (
                        <div className="grid gap-1.5">
                            <Label htmlFor="recipient-phone">
                                Celular (com DDD)
                            </Label>
                            <Input
                                id="recipient-phone"
                                type="tel"
                                inputMode="tel"
                                autoComplete="off"
                                value={data.phone}
                                placeholder={
                                    recipient.phone_masked
                                        ? `Atual: ${recipient.phone_masked} — digite outro para corrigir`
                                        : '+55 11 91234-5678'
                                }
                                aria-invalid={Boolean(errors.phone)}
                                onChange={(event) =>
                                    setData('phone', event.target.value)
                                }
                            />
                            {errors.phone && (
                                <p className="text-danger text-[12.5px]">
                                    {errors.phone}
                                </p>
                            )}
                        </div>
                    )}
                    {showPin && (
                        <div className="grid gap-1.5">
                            <Label htmlFor="recipient-pin">PIN novo</Label>
                            <Input
                                id="recipient-pin"
                                inputMode="numeric"
                                autoComplete="off"
                                maxLength={8}
                                value={data.pin}
                                placeholder="Deixe em branco para manter o atual"
                                aria-invalid={Boolean(errors.pin)}
                                onChange={(event) =>
                                    setData(
                                        'pin',
                                        event.target.value.replace(/\D/g, ''),
                                    )
                                }
                            />
                            <p className="text-muted-foreground text-[12px] leading-[1.45]">
                                {recipient.pin_state === 'blocked' ||
                                recipient.pin_state === 'locked'
                                    ? 'O PIN atual está bloqueado. Um PIN novo zera as tentativas; combine-o com o participante por fora do sistema.'
                                    : 'Um PIN novo substitui o atual e zera as tentativas. Combine-o com o participante por fora do sistema.'}
                            </p>
                            {errors.pin && (
                                <p className="text-danger text-[12.5px]">
                                    {errors.pin}
                                </p>
                            )}
                        </div>
                    )}
                    {channelChanged && (
                        <p className="border-warning-border bg-warning-bg text-warning rounded-[10px] border p-2.5 text-[12.5px] leading-[1.45]">
                            Os códigos já enviados e as sessões abertas deste
                            participante serão encerrados. Ao abrir o link de
                            novo, ele pede um código novo.
                        </p>
                    )}
                    {emailChanged && (
                        <p className="border-warning-border bg-warning-bg text-warning rounded-[10px] border p-2.5 text-[12.5px] leading-[1.45]">
                            O link enviado ao endereço anterior será revogado
                            imediatamente. Reenvie o convite para o novo e-mail
                            depois de salvar.
                        </p>
                    )}
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button
                        disabled={processing}
                        onClick={() =>
                            patch(
                                updateRecipient({
                                    envelope: envelopeId,
                                    recipient: recipient.id,
                                }).url,
                                {
                                    preserveScroll: true,
                                    onSuccess: onClose,
                                },
                            )
                        }
                    >
                        {processing && <Spinner className="size-4" />}
                        Salvar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Estado concluído (DESIGN §6.4): downloads disponíveis, código de verificação
 * e — o ponto sensível — a frase correta.
 *
 * Sem `signature_status = company_a1` **não** se diz "PDF assinado": o envelope
 * conclui como aceite eletrônico com evidências (arquitetura §2). O rótulo do
 * botão e o texto do painel seguem esse dado, nunca o status do envelope.
 */
function CompletionPanel({
    envelope,
    verifyUrl,
    simulated = false,
    portal = false,
}: {
    envelope: EnvelopeShowProps['envelope'];
    verifyUrl: string | null;
    /** Fase 3 §3.4: alguma assinatura por componente veio do simulador. */
    simulated?: boolean;
    /**
     * Fase 3 §3.5: algum participante devolveu o arquivo assinado no portal. Com mais de um meio
     * externo o `signature_status` é o genérico `participant_external`; o texto cita os dois.
     */
    portal?: boolean;
}) {
    /*
     * Três estados, não dois. `signature_status` ausente não é "sem certificado":
     * é *não sei*. Afirmar a negativa sem o dado seria tão falso quanto afirmar a
     * positiva, então o texto neutro manda o leitor à página de evidências, que é
     * onde a situação da assinatura é apurada de verdade.
     */
    const status = envelope.signature_status ?? null;
    const signedByOperator = status === 'company_a1';
    // Fase 2 §2.12: participantes com o próprio certificado (com ou sem a operadora).
    const participants = hasParticipantSignatures(status);
    // Fase 3 §3.4: feita FORA da plataforma, por componente (A3 real ou externo/simulado).
    const external = hasExternalParticipantSignatures(status);
    const signedFile = hasCryptographicSignature(status);
    const knownStatus = status != null;
    const code = formatVerificationCode(envelope.verification_code);
    const completedAt = formatDateTime(envelope.completed_at);
    // Fase 3 §3.5: documento devolvido pelo portal gov.br ("gov.br" só com a cadeia validada).
    const portalReturn = isGovBrReturn(status);
    const externalTitle =
        status === 'participant_a3'
            ? `Concluído e assinado com certificado A3 de participante em ${completedAt}`
            : status === 'participant_govbr'
              ? `Concluído · assinatura gov.br (avançada) de participante em ${completedAt}`
              : status === 'participant_external_unverified'
                ? `Concluído · documento devolvido com assinatura de terceiro (cadeia não verificada) em ${completedAt}`
                : simulated
                  ? `Concluído · assinatura de participante por componente externo (simulada) em ${completedAt}`
                  : `Concluído e assinado por participante com componente externo em ${completedAt}`;
    const externalText = portalReturn
        ? `${status === 'participant_govbr' ? 'O participante assinou no portal gov.br a versão reservada pela plataforma e devolveu o arquivo' : 'O participante devolveu a versão reservada pela plataforma com uma assinatura digital acrescentada'}; a plataforma conferiu que ele começa pela versão entregue e só acrescenta uma assinatura. ${status === 'participant_govbr' ? 'A cadeia foi conferida contra a âncora gov.br fixada; não é assinatura com certificado ICP-Brasil.' : 'A cadeia não foi verificada: não se afirma que seja assinatura gov.br.'} A assinatura se soma ao aceite eletrônico, sem substituí-lo.${envelope.certificate ? ' Por último, a operadora aplicou a sua própria assinatura, que não é a assinatura pessoal de ninguém.' : ''} A lista está na página de evidências.`
        : `O arquivo final recebeu assinatura feita pelo participante fora da plataforma, com um componente no próprio computador, sobre um resumo preparado pela plataforma — a chave do certificado não passou por ela. A assinatura se soma ao aceite eletrônico, sem substituí-lo.${envelope.certificate ? ' Por último, a operadora aplicou a sua própria assinatura, que não é a assinatura pessoal de ninguém.' : ''}${simulated ? ' Atenção: ao menos uma assinatura foi produzida pelo simulador — nenhum token foi usado — e não tem valor para uso real.' : ''}${portal ? ' Além disso, um participante devolveu a versão reservada pela plataforma com uma assinatura digital acrescentada; sem a cadeia validada até a âncora fixada, não se afirma que seja assinatura gov.br.' : ''} A lista está na página de evidências.`;

    return (
        <section className="border-success-border bg-success-bg text-success flex flex-col gap-3 rounded-[10px] border p-4">
            <div className="flex items-start gap-2.5">
                <Check className="mt-0.5 size-4 shrink-0" />
                <div className="min-w-0">
                    <p className="text-[13.5px] font-semibold">
                        {!knownStatus
                            ? `Documento concluído em ${completedAt}`
                            : external
                              ? externalTitle
                              : status === 'mixed'
                                ? `Concluído e assinado com certificados dos participantes e da operadora em ${completedAt}`
                                : participants
                                  ? `Concluído e assinado com certificado dos participantes em ${completedAt}`
                                  : signedByOperator
                                    ? `Concluído e assinado digitalmente pela operadora em ${completedAt}`
                                    : `Concluído com aceite eletrônico e evidências em ${completedAt}`}
                    </p>
                    <p className="mt-1 text-[12.5px] leading-[1.55] opacity-90">
                        {!knownStatus
                            ? 'O arquivo final e o relatório de evidências estão disponíveis. A situação da assinatura — com ou sem certificado da operadora — está na página de evidências.'
                            : external
                              ? externalText
                              : status === 'mixed'
                                ? 'O arquivo final recebeu assinaturas feitas com o certificado A1 do próprio participante e, por último, a assinatura da operadora, que lacra o arquivo e não é a assinatura pessoal de ninguém. Cada assinatura de participante se soma ao aceite eletrônico dele, sem substituí-lo. A lista está na página de evidências.'
                                : participants
                                  ? 'O arquivo final recebeu assinaturas feitas com o certificado A1 do próprio participante, acrescentadas depois das evidências. Elas identificam o titular de cada certificado e se somam ao aceite eletrônico, sem substituí-lo. A operadora não aplicou assinatura própria. A lista está na página de evidências.'
                                  : signedByOperator
                                    ? 'O arquivo final foi lacrado com o certificado A1 da AssinaVelox. A assinatura identifica a operadora e permite detectar alterações posteriores no arquivo; não é a assinatura pessoal dos participantes.'
                                    : 'Nenhum certificado da operadora estava ativo na finalização, então o arquivo final não tem assinatura criptográfica. As evidências de cada aceite — data, IP, navegador, código confirmado por e-mail e a versão exata do documento — estão no relatório.'}
                    </p>
                </div>
            </div>

            <TestCertificateWarning
                environment={envelope.certificate?.environment}
            />

            <div className="flex flex-wrap items-center gap-2">
                {envelope.downloads.signed && (
                    <Button asChild variant="success" size="sm">
                        <a href={envelope.downloads.signed}>
                            <Download className="size-[15px]" />
                            {signedFile
                                ? 'Baixar PDF assinado'
                                : 'Baixar arquivo final'}
                        </a>
                    </Button>
                )}
                {envelope.downloads.evidence && (
                    <Button asChild variant="outline" size="sm">
                        <a href={envelope.downloads.evidence}>
                            <FileText className="size-[15px]" />
                            Relatório de evidências
                        </a>
                    </Button>
                )}
                <Button asChild variant="outline" size="sm">
                    <Link href={envelopeEvidence(envelope.id)}>
                        <ShieldCheck className="size-[15px]" />
                        Ver evidências
                    </Link>
                </Button>
                {/* Fase 2 §2.13: só com a flag `dossier_export`. */}
                <DossierButton
                    envelopeId={envelope.id}
                    status={envelope.status}
                    size="sm"
                />
            </div>

            {envelope.verification_code && (
                <div className="text-text-secondary flex flex-wrap items-center gap-2 rounded-lg border border-white/60 bg-white/70 px-3 py-2 text-[12.5px]">
                    <span>Código de verificação pública</span>
                    <b className="tabular text-foreground font-mono">{code}</b>
                    <CopyButton
                        value={code}
                        label="Copiar código de verificação"
                        className="size-6"
                    />
                    {verifyUrl && (
                        <a
                            href={verifyUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-primary inline-flex items-center gap-1 font-semibold hover:underline"
                        >
                            <ExternalLink className="size-3.5" />
                            Abrir página pública
                        </a>
                    )}
                </div>
            )}
        </section>
    );
}

/**
 * Arquivos do envelope sob o visualizador (Fase 2 §2.3): nome, páginas, tamanho e os
 * downloads de cada um (original; final e evidências só depois da conclusão). `signed` =
 * o arquivo final tem assinatura criptográfica (operadora e/ou participantes, §2.12).
 */
function FilesPanel({
    files,
    signed,
}: {
    files: EnvelopeFile[];
    signed: boolean;
}) {
    return (
        <ol className="border-border bg-card shadow-card mt-3 flex flex-col rounded-xl border px-4 py-1.5 text-[12.5px]">
            {files.map((file) => (
                <li
                    key={file.id}
                    className="border-muted flex flex-wrap items-center gap-x-3 gap-y-1 border-t py-2 first:border-t-0"
                >
                    <span className="flex min-w-0 flex-1 items-center gap-1.5">
                        <FileText className="text-muted-foreground size-3.5 shrink-0" />
                        <span className="truncate font-semibold">
                            {file.position}. {documentName(file)}
                        </span>
                        <span className="text-muted-foreground tabular shrink-0">
                            · {plural(file.pages, 'página')} ·{' '}
                            {formatBytes(file.size_bytes)}
                        </span>
                    </span>
                    <span className="flex shrink-0 flex-wrap gap-2">
                        {file.downloads.original && (
                            <a
                                href={file.downloads.original}
                                className="text-primary font-semibold hover:underline"
                            >
                                Original
                            </a>
                        )}
                        {file.downloads.signed && (
                            <a
                                href={file.downloads.signed}
                                className="text-primary font-semibold hover:underline"
                            >
                                {signed ? 'PDF assinado' : 'Arquivo final'}
                            </a>
                        )}
                        {file.downloads.evidence && (
                            <a
                                href={file.downloads.evidence}
                                className="text-primary font-semibold hover:underline"
                            >
                                Evidências
                            </a>
                        )}
                    </span>
                </li>
            ))}
        </ol>
    );
}

function StatusBanner({
    tone,
    title,
    children,
    action,
}: {
    tone: 'success' | 'info' | 'danger' | 'neutral';
    title: string;
    children?: React.ReactNode;
    action?: React.ReactNode;
}) {
    const classes = {
        success: 'border-success-border bg-success-bg text-success',
        info: 'border-primary-soft-border bg-primary-soft text-primary',
        danger: 'border-danger-border bg-danger-bg text-danger',
        neutral: 'border-neutral-border bg-neutral-bg text-text-secondary',
    }[tone];

    return (
        <div
            className={cn(
                'flex flex-wrap items-center justify-between gap-3 rounded-[10px] border p-3.5 text-[13px]',
                classes,
            )}
        >
            <div className="flex items-start gap-2.5">
                {tone === 'success' ? (
                    <Check className="mt-0.5 size-4 shrink-0" />
                ) : (
                    <ShieldCheck className="mt-0.5 size-4 shrink-0" />
                )}
                <div>
                    <div className="font-semibold">{title}</div>
                    {children && (
                        <div className="mt-0.5 leading-[1.5] opacity-90">
                            {children}
                        </div>
                    )}
                </div>
            </div>
            {action}
        </div>
    );
}

EnvelopeShow.layout = (props: EnvelopeShowProps) => ({
    breadcrumbs: [
        { title: 'Documentos', href: envelopesIndex() },
        ...(props.envelope.folder
            ? [
                  {
                      title: props.envelope.folder.name,
                      href: envelopesIndex({
                          query: { folder: props.envelope.folder.id },
                      }),
                  },
              ]
            : []),
        { title: props.envelope.title, href: envelopeShow(props.envelope.id) },
    ],
});
