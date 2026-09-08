import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Check, ChevronDown, Copy as CopyIcon, Download, ExternalLink, FileText, Mail, MoreHorizontal, Pencil, RefreshCw, ShieldCheck, XCircle } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { AvatarInitials, recipientTone } from '@/components/avatar-initials';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { CopyButton } from '@/components/copy-button';
import { PageHeader } from '@/components/page-header';
import { SegmentedControl } from '@/components/segmented-control';
import { EnvelopeStatusBadge } from '@/components/status/envelope-status-badge';
import { RecipientStatusBadge } from '@/components/status/recipient-status-badge';
import { HashBox, Timeline } from '@/components/timeline';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Textarea } from '@/components/ui/textarea';
import { formatBytes, formatDateTime, formatProgress, formatVerificationCode, plural } from '@/lib/format';
import { authMethodLabels, signingOrderLabels } from '@/lib/labels';
import { cn } from '@/lib/utils';
import { cancel as envelopeCancel, destroy as envelopeDestroy, duplicate as envelopeDuplicate, edit as envelopeEdit, evidence as envelopeEvidence, index as envelopesIndex, resend as envelopeResend, show as envelopeShow } from '@/routes/envelopes';
import { resend as resendRecipient } from '@/routes/envelopes/recipients';
import { show as verifyShow } from '@/routes/verify';
import type { AuditEvent, Envelope, FolderRef, Recipient, SigningField } from '@/types';

type Tab = 'signers' | 'audit' | 'details';

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
 * banner por estado, visualizador (placeholder até a Wave B integrar PDF.js)
 * e abas Signatários / Trilha / Detalhes.
 */
export default function EnvelopeShow({ envelope, recipients, events, sent, tab }: EnvelopeShowProps) {
    const [currentTab, setCurrentTab] = useState<Tab>(tab ?? 'signers');
    const [cancelOpen, setCancelOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);

    const isDraft = ['draft', 'preparing', 'ready'].includes(envelope.status);
    const pendingCount = recipients.filter((r) => ['pending', 'notified', 'viewed'].includes(r.status)).length;

    const resendOne = (recipient: Recipient) => {
        router.post(resendRecipient({ envelope: envelope.id, recipient: recipient.id }).url, {}, {
            preserveScroll: true,
            onSuccess: () => toast.success(`Convite reenviado para ${recipient.name}`),
            onError: () => toast.error('Aguarde alguns minutos antes de reenviar'),
        });
    };

    const resendAll = () => {
        router.post(envelopeResend(envelope.id).url, {}, {
            preserveScroll: true,
            onSuccess: () => toast.success(`Convites reenviados (${pendingCount})`),
        });
    };

    const confirmCancel = () => {
        setBusy(true);
        router.post(envelopeCancel(envelope.id).url, { reason: reason || undefined }, {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setCancelOpen(false);
            },
        });
    };

    const confirmDelete = () => {
        setBusy(true);
        router.delete(envelopeDestroy(envelope.id).url, { onFinish: () => setBusy(false) });
    };

    const verifyUrl = envelope.verification_code ? verifyShow(envelope.verification_code).url : null;

    return (
        <>
            <Head title={envelope.title} />

            <PageHeader
                size="detail"
                leading={
                    <Button asChild variant="outline" size="icon-sm" aria-label="Voltar">
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
                    <span className="inline-flex flex-wrap items-center gap-1.5 tabular">
                        {envelope.display_code}
                        {envelope.folder && <> · {envelope.folder.name}</>}
                        {envelope.expires_label && (
                            <>
                                {' · '}
                                <span className={cn(envelope.expiring_soon && 'font-semibold text-warning')}>{envelope.expires_label}</span>
                            </>
                        )}
                    </span>
                }
                actions={
                    <>
                        {envelope.can.resend && pendingCount > 0 && (
                            <Button variant="outline" onClick={resendAll}>
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
                                <Button variant={isDraft ? 'outline' : 'default'} disabled={!envelope.downloads.original && !envelope.downloads.signed}>
                                    <Download className="size-[15px]" />
                                    Baixar
                                    <ChevronDown className="size-3.5 opacity-70" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem asChild disabled={!envelope.downloads.original}>
                                    <a href={envelope.downloads.original ?? '#'}>Documento original</a>
                                </DropdownMenuItem>
                                <DropdownMenuItem asChild disabled={!envelope.downloads.signed}>
                                    <a href={envelope.downloads.signed ?? '#'}>PDF final assinado</a>
                                </DropdownMenuItem>
                                <DropdownMenuItem asChild disabled={!envelope.downloads.evidence}>
                                    <a href={envelope.downloads.evidence ?? '#'}>Relatório de evidências (PDF)</a>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="outline" size="icon" aria-label="Mais ações">
                                    <MoreHorizontal className="size-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                {envelope.can.duplicate && (
                                    <DropdownMenuItem onSelect={() => router.post(envelopeDuplicate(envelope.id).url)}>Duplicar</DropdownMenuItem>
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
                                                void navigator.clipboard?.writeText(formatVerificationCode(envelope.verification_code));
                                                toast.success('Código de verificação copiado');
                                            }}
                                        >
                                            <CopyIcon className="size-3.5" />
                                            Copiar código de verificação
                                        </DropdownMenuItem>
                                        {verifyUrl && (
                                            <DropdownMenuItem asChild>
                                                <a href={verifyUrl} target="_blank" rel="noopener noreferrer">
                                                    <ExternalLink className="size-3.5" />
                                                    Abrir página pública de verificação
                                                </a>
                                            </DropdownMenuItem>
                                        )}
                                    </>
                                )}
                                {(envelope.can.cancel || envelope.can.delete) && <DropdownMenuSeparator />}
                                {envelope.can.cancel && envelope.status === 'in_progress' && (
                                    <DropdownMenuItem variant="destructive" onSelect={() => setCancelOpen(true)}>
                                        <XCircle className="size-3.5" />
                                        Cancelar documento
                                    </DropdownMenuItem>
                                )}
                                {envelope.can.delete && isDraft && (
                                    <DropdownMenuItem variant="destructive" onSelect={() => setDeleteOpen(true)}>
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
                <StatusBanner tone="info" title="Gerando o PDF assinado e o relatório de evidências…">
                    Isso costuma levar menos de um minuto. A página atualiza automaticamente.
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
                    PDF final com trilha de auditoria disponível. Verificação pública pelo código{' '}
                    <b className="tabular">{formatVerificationCode(envelope.verification_code)}</b>.
                </StatusBanner>
            )}
            {envelope.status === 'refused' && (
                <StatusBanner tone="danger" title="Documento recusado">
                    {recipients.find((r) => r.status === 'refused')?.refusal_reason
                        ? `Motivo: “${recipients.find((r) => r.status === 'refused')?.refusal_reason}”`
                        : 'Um signatário recusou a assinatura.'}
                </StatusBanner>
            )}
            {(envelope.status === 'expired' || envelope.status === 'canceled') && (
                <StatusBanner tone="neutral" title={envelope.status === 'expired' ? 'Prazo de assinatura encerrado' : 'Documento cancelado'}>
                    {envelope.status === 'canceled' && envelope.cancel_reason ? `Motivo: “${envelope.cancel_reason}”` : 'Duplique o documento para enviar novamente.'}
                </StatusBanner>
            )}

            <div className="flex flex-wrap items-start gap-4">
                <div className="min-w-0 flex-[1.4_1_420px] overflow-hidden rounded-xl border border-border bg-card shadow-card">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border px-3.5 py-2.5 text-[13px] text-text-secondary">
                        <span className="inline-flex items-center gap-2">
                            <FileText className="size-4 text-primary" />
                            {envelope.document?.original_name ?? 'Sem documento'}
                        </span>
                        {envelope.document && (
                            <span className="tabular">
                                {plural(envelope.document.pages, 'página')} · {formatBytes(envelope.document.size_bytes)}
                            </span>
                        )}
                    </div>
                    <div className="flex justify-center bg-accent p-6">
                        {envelope.document ? (
                            <div className="flex aspect-[1/1.3] w-full max-w-[560px] flex-col items-center justify-center gap-3 rounded bg-white p-[9%] text-center shadow-pdf">
                                <FileText className="size-10 text-primary-soft-border" />
                                <p className="text-[13px] text-muted-foreground">
                                    Pré-visualização do PDF com campos sobrepostos chega na Wave B (PDF.js).
                                </p>
                                {envelope.downloads.original && (
                                    <Button asChild variant="outline" size="xs">
                                        <a href={envelope.downloads.original} target="_blank" rel="noopener noreferrer">
                                            Abrir PDF
                                        </a>
                                    </Button>
                                )}
                            </div>
                        ) : (
                            <p className="py-16 text-[13px] text-muted-foreground">Nenhum arquivo enviado ainda.</p>
                        )}
                    </div>
                </div>

                <div className="min-w-0 flex-[1_1_340px] rounded-xl border border-border bg-card shadow-card">
                    <div className="border-b border-border px-4 py-3">
                        <SegmentedControl
                            value={currentTab}
                            onChange={setCurrentTab}
                            options={[
                                { value: 'signers', label: 'Signatários', count: recipients.length },
                                { value: 'audit', label: 'Trilha', count: events.length },
                                { value: 'details', label: 'Detalhes' },
                            ]}
                        />
                    </div>

                    {currentTab === 'signers' && (
                        <div className="flex flex-col gap-3 p-4">
                            {recipients.length === 0 && <p className="py-6 text-center text-[13px] text-muted-foreground">Nenhum signatário adicionado.</p>}
                            {recipients.map((recipient) => (
                                <div key={recipient.id} className="rounded-[10px] border border-border p-4">
                                    <div className="flex items-start gap-3">
                                        <AvatarInitials initials={recipient.initials} tone={recipientTone(recipient.status)} size="xl" />
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="truncate text-[13.5px] font-semibold">{recipient.name}</span>
                                                {recipient.role && <span className="text-[12px] text-muted-foreground">· {recipient.role}</span>}
                                                {envelope.signing_order === 'sequential' && (
                                                    <Badge variant="draft" className="text-[11px]">
                                                        {recipient.order}º
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="truncate text-[12.5px] text-muted-foreground">{recipient.email}</div>
                                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                                <RecipientStatusBadge status={recipient.status} label={recipient.status_label} />
                                                {recipient.auth_methods.map((m) => (
                                                    <span key={m} className="inline-flex items-center gap-[5px] rounded-md bg-muted px-[7px] py-0.5 text-[11.5px] font-semibold text-text-secondary">
                                                        <Mail className="size-3" />
                                                        {authMethodLabels[m]}
                                                    </span>
                                                ))}
                                            </div>
                                            <p className="mt-2 text-[12px] text-muted-foreground tabular">
                                                {recipient.status === 'signed' && recipient.signed_at && `Assinou em ${formatDateTime(recipient.signed_at)}`}
                                                {recipient.status === 'signed' && recipient.evidence && ` · IP ${recipient.evidence.ip} · ${recipient.evidence.user_agent_label}`}
                                                {recipient.status === 'refused' && `Recusou em ${formatDateTime(recipient.refused_at)}${recipient.refusal_reason ? ` · Motivo: “${recipient.refusal_reason}”` : ''}`}
                                                {recipient.status === 'viewed' && recipient.viewed_at && `Visualizou em ${formatDateTime(recipient.viewed_at)}`}
                                                {recipient.status === 'notified' && recipient.sent_at && `Enviado em ${formatDateTime(recipient.sent_at)} · não visualizou`}
                                                {recipient.status === 'pending' && envelope.status === 'in_progress' && 'Aguarda a vez'}
                                                {recipient.status === 'pending' && envelope.status !== 'in_progress' && 'Ainda não notificado'}
                                                {recipient.status === 'expired' && 'Prazo encerrado'}
                                                {recipient.status === 'canceled' && 'Documento cancelado'}
                                            </p>
                                        </div>
                                    </div>
                                    {(recipient.can_resend || recipient.can_edit) && (
                                        <div className="mt-3 flex gap-2">
                                            {recipient.can_resend && (
                                                <Button variant="outline-sm" size="xxs" onClick={() => resendOne(recipient)}>
                                                    <RefreshCw className="size-3" />
                                                    Reenviar
                                                </Button>
                                            )}
                                            {recipient.can_edit && (
                                                <Button variant="outline-sm" size="xxs" disabled title="Edição de signatário chega na Wave B">
                                                    <Pencil className="size-3" />
                                                    Editar
                                                </Button>
                                            )}
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}

                    {currentTab === 'audit' && (
                        <div className="p-4">
                            <Timeline
                                events={events}
                                footer={
                                    envelope.document && (
                                        <div className="mt-4 flex flex-col gap-2">
                                            <HashBox label="SHA-256 do documento enviado" value={envelope.document.sha256_original} action={<CopyButton value={envelope.document.sha256_original} className="size-6" />} />
                                            {envelope.document.sha256_signed && (
                                                <HashBox label="SHA-256 do PDF final" value={envelope.document.sha256_signed} action={<CopyButton value={envelope.document.sha256_signed} className="size-6" />} />
                                            )}
                                            <Button asChild variant="link" size="sm" className="self-start">
                                                <Link href={envelopeEvidence(envelope.id)}>
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
                                ['Código de verificação', envelope.verification_code ? formatVerificationCode(envelope.verification_code) : 'Gerado no envio'],
                                ['Status', envelope.status_label],
                                ['Criado por', envelope.creator.name],
                                ['Criado em', formatDateTime(envelope.created_at)],
                                ['Enviado em', envelope.sent_at ? formatDateTime(envelope.sent_at) : '—'],
                                ['Expira em', envelope.expires_at ? formatDateTime(envelope.expires_at) : '—'],
                                ['Pasta', envelope.folder?.name ?? '—'],
                                ['Arquivo', envelope.document ? `${envelope.document.original_name} · ${formatBytes(envelope.document.size_bytes)} · ${plural(envelope.document.pages, 'página')}` : '—'],
                                ['Ordem', signingOrderLabels[envelope.signing_order]],
                                ['Mensagem', envelope.message || '—'],
                                ['Cópia final para todos', envelope.send_copy_to_all ? 'Sim' : 'Não'],
                            ].map(([label, value]) => (
                                <div key={label} className="grid grid-cols-[140px_1fr] gap-3 border-b border-muted px-5 py-[11px] last:border-b-0">
                                    <dt className="text-muted-foreground">{label}</dt>
                                    <dd className="min-w-0 break-words tabular">{value}</dd>
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
                    <label htmlFor="cancel-reason" className="text-[13px] font-semibold">
                        Motivo <span className="font-normal text-muted-foreground">(opcional)</span>
                    </label>
                    <Textarea id="cancel-reason" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Ex.: valores desatualizados" maxLength={500} />
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
        </>
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
        <div className={cn('flex flex-wrap items-center justify-between gap-3 rounded-[10px] border p-3.5 text-[13px]', classes)}>
            <div className="flex items-start gap-2.5">
                {tone === 'success' ? <Check className="mt-0.5 size-4 shrink-0" /> : <ShieldCheck className="mt-0.5 size-4 shrink-0" />}
                <div>
                    <div className="font-semibold">{title}</div>
                    {children && <div className="mt-0.5 leading-[1.5] opacity-90">{children}</div>}
                </div>
            </div>
            {action}
        </div>
    );
}

EnvelopeShow.layout = (props: EnvelopeShowProps) => ({
    breadcrumbs: [
        { title: 'Documentos', href: envelopesIndex() },
        ...(props.envelope.folder ? [{ title: props.envelope.folder.name, href: envelopesIndex({ query: { folder: props.envelope.folder.id } }) }] : []),
        { title: props.envelope.title, href: envelopeShow(props.envelope.id) },
    ],
});
