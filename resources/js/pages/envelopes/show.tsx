import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    ChevronDown,
    Copy as CopyIcon,
    Download,
    ExternalLink,
    FileText,
    Mail,
    MoreHorizontal,
    Pencil,
    RefreshCw,
    ShieldCheck,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { AvatarInitials, recipientTone } from '@/components/avatar-initials';
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    FieldLayer,
    type LayerField,
} from '@/components/envelopes/field-layer';
import { recipientColor } from '@/components/envelopes/recipient-colors';
import { DEFAULT_ZOOM } from '@/components/pdf/pdf-zoom-controls';
import { PdfViewer } from '@/components/pdf/pdf-viewer';
import { CopyButton } from '@/components/copy-button';
import { PageHeader } from '@/components/page-header';
import { SegmentedControl } from '@/components/segmented-control';
import { EnvelopeStatusBadge } from '@/components/status/envelope-status-badge';
import { RecipientStatusBadge } from '@/components/status/recipient-status-badge';
import { HashBox, Timeline } from '@/components/timeline';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
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
    FolderRef,
    Recipient,
    SigningField,
} from '@/types';

type Tab = 'signers' | 'audit' | 'details';

/** Campo do detalhe adaptado ao contrato da camada (somente leitura). */
interface ShowLayerField extends LayerField {
    value: string | null;
    signed: boolean;
}

export interface EnvelopeShowProps {
    envelope: Envelope;
    recipients: Recipient[];
    fields: SigningField[];
    events: AuditEvent[];
    folders: FolderRef[];
    sent: boolean;
    tab: Tab;
}

/**
 * Detalhe do documento (ROUTES §2.7; DESIGN §6.4): cabeçalho com status,
 * banner por estado, visualizador (placeholder até a integração com PDF.js)
 * e abas Signatários / Trilha / Detalhes.
 */
export default function EnvelopeShow({
    envelope,
    recipients,
    fields,
    events,
    sent,
    tab,
}: EnvelopeShowProps) {
    const [currentTab, setCurrentTab] = useState<Tab>(tab ?? 'signers');
    const [page, setPage] = useState(1);
    const [zoom, setZoom] = useState(DEFAULT_ZOOM);

    // Campos sobrepostos ao PDF: somente leitura, com a cor do destinatário.
    const recipientById = new Map(
        recipients.map((recipient) => [recipient.id, recipient]),
    );
    const pageFields: ShowLayerField[] = fields
        .filter((field) => field.page !== 'all' && Number(field.page) === page)
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
    const pendingCount = recipients.filter((r) =>
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
                                <DropdownMenuItem
                                    asChild
                                    disabled={!envelope.downloads.original}
                                >
                                    <a
                                        href={
                                            envelope.downloads.original ?? '#'
                                        }
                                    >
                                        Documento original
                                    </a>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    asChild
                                    disabled={!envelope.downloads.signed}
                                >
                                    <a href={envelope.downloads.signed ?? '#'}>
                                        PDF final assinado
                                    </a>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    asChild
                                    disabled={!envelope.downloads.evidence}
                                >
                                    <a
                                        href={
                                            envelope.downloads.evidence ?? '#'
                                        }
                                    >
                                        Relatório de evidências (PDF)
                                    </a>
                                </DropdownMenuItem>
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
                                {envelope.can.delete && isDraft && (
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
                <StatusBanner
                    tone="success"
                    title={`Documento concluído em ${formatDateTime(envelope.completed_at)}`}
                    action={
                        envelope.downloads.signed && (
                            <Button asChild variant="success" size="sm">
                                <a href={envelope.downloads.signed}>
                                    <Download className="size-[15px]" />
                                    Baixar PDF assinado
                                </a>
                            </Button>
                        )
                    }
                >
                    PDF final com trilha de auditoria disponível. Verificação
                    pública pelo código{' '}
                    <b className="tabular">
                        {formatVerificationCode(envelope.verification_code)}
                    </b>
                    .
                </StatusBanner>
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
                    {envelope.document ? (
                        <PdfViewer
                            // A versão *exibível* vem de `envelopes.document.preview`;
                            // `downloads.original` entrega o arquivo como foi enviado, que
                            // para DOCX e imagem não é um PDF.
                            url={documentPreview(envelope.id).url}
                            page={page}
                            onPageChange={setPage}
                            zoom={zoom}
                            onZoomChange={setZoom}
                            maxPageWidth={560}
                            stamp={`${envelope.display_code} · pág. ${page}/${envelope.document.pages}`}
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
                    {envelope.document && (
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
                                    label: 'Signatários',
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
                                        manuais
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
                                                            envelope.document
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
                                    'Arquivo',
                                    envelope.document
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
function recipientNote(recipient: Recipient, envelope: Envelope): string {
    const reminder = recipient.last_resent_at
        ? ` · último lembrete ${formatDateTime(recipient.last_resent_at)}`
        : '';

    if (recipient.status === 'signed') {
        return [
            recipient.signed_at
                ? `Aceite registrado em ${formatDateTime(recipient.signed_at)}`
                : 'Aceite registrado',
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
    onResend,
    onEdit,
}: {
    recipient: Recipient;
    envelope: Envelope;
    onResend: () => void;
    onEdit: () => void;
}) {
    // No sequencial, quem ainda não chegou na vez não tem link emitido:
    // reenviar não faria nada (RECONCILIACAO Q11).
    const awaitingTurn =
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
                        <span className="text-muted-foreground text-[11.5px]">
                            {[
                                recipient.role,
                                envelope.signing_order === 'sequential'
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
                            <Mail className="size-3" />
                            {deliveryChannelLabels[recipient.channel]}
                        </span>
                        {recipient.auth_methods.map((method) => (
                            <span
                                key={method}
                                className="bg-muted text-text-secondary inline-flex items-center gap-[5px] rounded-md px-[7px] py-0.5 text-[11.5px] font-semibold"
                            >
                                <ShieldCheck className="size-3" />
                                {authMethodLabels[method]}
                            </span>
                        ))}
                    </div>
                </div>
                <RecipientStatusBadge
                    status={recipient.status}
                    label={recipient.status_label}
                    className="shrink-0"
                />
            </div>

            <div className="border-muted text-muted-foreground mt-2.5 flex flex-wrap items-center justify-between gap-2 border-t pt-2.5 text-[12px]">
                <span className="tabular min-w-0">
                    {recipientNote(recipient, envelope)}
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
    const { data, setData, patch, processing, errors } = useForm({
        name: recipient.name,
        email: recipient.email,
    });

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
