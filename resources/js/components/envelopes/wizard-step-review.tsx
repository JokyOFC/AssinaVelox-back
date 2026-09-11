import { Eye, Info, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { reminderSummary } from '@/components/envelopes/phase2-routes';
import { recipientColor } from '@/components/envelopes/recipient-colors';
import {
    documentName,
    fileBadge,
} from '@/components/envelopes/wizard-document-list';
import { roleOf } from '@/components/envelopes/wizard-step-recipients';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatBytes, formatDateMedium, plural } from '@/lib/format';
import { participantRoleLabels, signingOrderLabels } from '@/lib/labels';
import type { SigningOrder } from '@/types/enums';
import type {
    EnvelopeDocument,
    FolderRef,
    ReminderSettings,
    WizardField,
    WizardRecipient,
} from '@/types/models';

function expiresOn(days: number): string {
    const target = new Date();
    target.setDate(target.getDate() + days);

    return formatDateMedium(target.toISOString());
}

/**
 * Passo 4 — Revisar e enviar (DESIGN §6.5): resumo do documento, dos
 * signatários e dos campos, mensagem ao signatário e pendências antes do envio.
 *
 * Fase 2: com vários arquivos, lista cada um; com papéis, mostra o tipo de cada
 * participante; com lembretes, a cadência e o cartão de envio agendado (`scheduleSlot`).
 * As pendências vêm do backend (`EnvelopeReadiness::issues()`), inclusive as novas por
 * arquivo e por papel. Sem as flags o passo é o da Fase 1.
 */
export function WizardStepReview({
    document,
    documents = [],
    multiDocument = false,
    participantRoles = false,
    folder,
    title,
    expiresInDays,
    signingOrder,
    recipients,
    fields,
    initialsOnAllPages,
    message,
    onMessageChange,
    sendCopyToAll,
    onSendCopyChange,
    plan,
    issues,
    onEditStep,
    disabled,
    reminderSettings,
    scheduleSlot,
}: {
    document: EnvelopeDocument | null;
    documents?: EnvelopeDocument[];
    multiDocument?: boolean;
    participantRoles?: boolean;
    folder: FolderRef | null;
    title: string;
    expiresInDays: number;
    signingOrder: SigningOrder;
    recipients: WizardRecipient[];
    fields: WizardField[];
    initialsOnAllPages: boolean;
    message: string;
    onMessageChange: (message: string) => void;
    sendCopyToAll: boolean;
    onSendCopyChange: (value: boolean) => void;
    plan: {
        envelopes_used: number;
        envelopes_limit: number | null;
        can_send: boolean;
        reason: string | null;
    };
    /** Pendências em PT-BR (backend `issues` + as que só o cliente conhece). */
    issues: string[];
    onEditStep: (step: 1 | 2 | 3) => void;
    disabled?: boolean;
    /** Cadência de lembretes (só com a flag `reminders`). */
    reminderSettings?: ReminderSettings | null;
    /** Cartão de envio agendado (só com a flag `reminders`). */
    scheduleSlot?: ReactNode;
}) {
    const multi = multiDocument && documents.length > 1;
    const firstDocumentId = documents[0]?.id ?? document?.id ?? null;

    const pagesWithFields = new Set(
        fields
            .filter((field) => field.page !== 'all')
            .map((field) => Number(field.page)),
    );
    const documentsWithFields = new Set(
        fields.map((field) => field.document_id ?? firstDocumentId),
    );

    const showRoles =
        participantRoles ||
        recipients.some((recipient) => roleOf(recipient) !== 'signer');
    let turn = 0;

    return (
        <div className="flex flex-wrap items-start gap-5">
            <div className="flex min-w-0 flex-[1.3_1_380px] flex-col gap-4">
                <div className="border-border bg-card shadow-card flex flex-col gap-3 rounded-xl border p-5">
                    <Heading
                        variant="small"
                        title={
                            multi
                                ? `Documento · ${plural(documents.length, 'arquivo')}`
                                : 'Documento'
                        }
                        action={
                            <Button
                                variant="link"
                                size="sm"
                                onClick={() => onEditStep(1)}
                            >
                                Editar
                            </Button>
                        }
                    />
                    {multi ? (
                        <>
                            <p className="text-[14px] font-semibold">
                                {title}
                                <span className="text-muted-foreground block text-[12.5px] font-normal">
                                    {folder && `Pasta ${folder.name} · `}
                                    expira em {expiresOn(expiresInDays)}
                                </span>
                            </p>
                            <ol className="flex flex-col">
                                {documents.map((item, index) => (
                                    <li
                                        key={item.id}
                                        className="border-muted flex items-center gap-3 border-t py-2 first:border-t-0"
                                    >
                                        <span className="bg-danger-bg text-danger flex size-8 shrink-0 items-center justify-center rounded-lg text-[9.5px] font-extrabold">
                                            {fileBadge(item)}
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-[13px] font-semibold">
                                                {index + 1}.{' '}
                                                {documentName(item)}
                                            </span>
                                            <span className="text-muted-foreground tabular block truncate text-[12px]">
                                                {formatBytes(item.size_bytes)}
                                                {item.processing.pages !=
                                                    null &&
                                                    ` · ${plural(item.processing.pages, 'página')}`}
                                                {' · '}
                                                {plural(
                                                    fields.filter(
                                                        (field) =>
                                                            (field.document_id ??
                                                                firstDocumentId) ===
                                                            item.id,
                                                    ).length,
                                                    'campo',
                                                )}
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        </>
                    ) : document ? (
                        <div className="flex items-center gap-3">
                            <span className="bg-danger-bg text-danger flex size-10 shrink-0 items-center justify-center rounded-lg text-[10px] font-extrabold">
                                PDF
                            </span>
                            <span className="min-w-0">
                                <span className="block truncate text-[14px] font-semibold">
                                    {title}
                                </span>
                                <span className="text-muted-foreground block text-[12.5px]">
                                    {document.original_name} ·{' '}
                                    {formatBytes(document.size_bytes)}
                                    {document.processing.pages != null &&
                                        ` · ${plural(document.processing.pages, 'página')}`}
                                    {folder && ` · Pasta ${folder.name}`} ·
                                    expira em {expiresOn(expiresInDays)}
                                </span>
                            </span>
                        </div>
                    ) : (
                        <p className="text-muted-foreground text-[13px]">
                            Nenhum arquivo enviado.
                        </p>
                    )}
                </div>

                <div className="border-border bg-card shadow-card flex flex-col gap-2 rounded-xl border p-5">
                    <Heading
                        variant="small"
                        title={
                            <>
                                {showRoles ? 'Participantes' : 'Signatários'}{' '}
                                <span className="text-muted-foreground font-medium">
                                    ·{' '}
                                    {signingOrderLabels[
                                        signingOrder
                                    ].toLowerCase()}
                                </span>
                            </>
                        }
                        action={
                            <Button
                                variant="link"
                                size="sm"
                                onClick={() => onEditStep(2)}
                            >
                                Editar
                            </Button>
                        }
                    />
                    {recipients.map((recipient, index) => {
                        const color = recipientColor(index);
                        const participantRole = roleOf(recipient);
                        const viewer = participantRole === 'viewer';
                        const count = fields.filter(
                            (field) =>
                                field.recipient_client_id ===
                                recipient.client_id,
                        ).length;
                        const position = viewer ? null : ++turn;

                        return (
                            <div
                                key={recipient.client_id}
                                className="border-muted flex items-center gap-3 border-t py-2.5 first:border-t-0"
                            >
                                <span
                                    style={{
                                        backgroundColor: color.soft,
                                        color: color.text,
                                    }}
                                    className="tabular flex size-8 shrink-0 items-center justify-center rounded-lg text-[12px] font-bold"
                                >
                                    {showRoles ? (
                                        viewer ? (
                                            <Eye className="size-3.5" />
                                        ) : (
                                            position
                                        )
                                    ) : (
                                        index + 1
                                    )}
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[13.5px] font-semibold">
                                        {recipient.name || '(sem nome)'}
                                        {showRoles &&
                                            participantRole !== 'signer' && (
                                                <span className="text-primary font-semibold">
                                                    {' '}
                                                    ·{' '}
                                                    {recipient.participant_role_label ??
                                                        participantRoleLabels[
                                                            participantRole
                                                        ]}
                                                </span>
                                            )}
                                        {recipient.role && (
                                            <span className="text-muted-foreground font-medium">
                                                {' '}
                                                · {recipient.role}
                                            </span>
                                        )}
                                    </span>
                                    <span className="text-text-secondary block truncate text-[12.5px]">
                                        {recipient.email || '(sem e-mail)'} ·
                                        e-mail com código de verificação ·{' '}
                                        {viewer
                                            ? 'recebe cópia para acompanhamento'
                                            : participantRole === 'approver'
                                              ? `aprova sem assinatura visual · ${plural(count, 'campo')}`
                                              : plural(count, 'campo')}
                                    </span>
                                </span>
                            </div>
                        );
                    })}
                </div>

                <div className="border-border bg-card shadow-card flex flex-col gap-2.5 rounded-xl border p-5">
                    <Heading
                        variant="small"
                        title="Mensagem aos signatários"
                        action={
                            <Button
                                variant="link"
                                size="sm"
                                onClick={() => onEditStep(3)}
                            >
                                Revisar campos
                            </Button>
                        }
                    />
                    <Label htmlFor="envelope-message" className="sr-only">
                        Mensagem aos signatários
                    </Label>
                    <Textarea
                        id="envelope-message"
                        rows={4}
                        maxLength={1000}
                        value={message}
                        disabled={disabled}
                        placeholder="Olá! Segue o documento para assinatura. Qualquer dúvida, estou à disposição."
                        onChange={(event) =>
                            onMessageChange(event.target.value)
                        }
                    />
                    <label className="text-text-secondary flex cursor-pointer items-center gap-2 text-[12.5px]">
                        <Checkbox
                            checked={sendCopyToAll}
                            disabled={disabled}
                            onCheckedChange={(value) =>
                                onSendCopyChange(value === true)
                            }
                        />
                        Enviar cópia do documento assinado para todos ao
                        concluir
                    </label>
                </div>
            </div>

            <div className="flex min-w-0 flex-[1_1_280px] flex-col gap-4">
                <div className="border-border bg-card shadow-card flex flex-col gap-1 rounded-xl border p-5">
                    <Heading variant="small" title="Resumo" />
                    {multi && (
                        <SummaryRow
                            label="Arquivos"
                            value={plural(documents.length, 'arquivo')}
                        />
                    )}
                    <SummaryRow
                        label="Campos"
                        value={
                            multi
                                ? `${plural(fields.length, 'campo')} em ${plural(documentsWithFields.size, 'arquivo')}${initialsOnAllPages ? ' + rubricas' : ''}`
                                : `${plural(fields.length, 'campo')} em ${plural(pagesWithFields.size, 'página')}${initialsOnAllPages ? ' + rubricas' : ''}`
                        }
                    />
                    <SummaryRow
                        label="Ordem"
                        value={signingOrderLabels[signingOrder]}
                    />
                    <SummaryRow
                        label="Validade"
                        value={`${expiresInDays} dias (até ${expiresOn(expiresInDays)})`}
                    />
                    {reminderSettings && (
                        <SummaryRow
                            label="Lembretes"
                            value={reminderSummary(reminderSettings)}
                        />
                    )}
                    <SummaryRow
                        label="Consumo do plano"
                        value={
                            plan.envelopes_limit === null
                                ? '1 documento'
                                : `1 documento (${plan.envelopes_used} / ${plan.envelopes_limit})`
                        }
                    />
                </div>

                {issues.length > 0 && (
                    <div className="border-warning-border bg-warning-bg text-warning flex gap-2 rounded-[10px] border p-3.5 text-[12.5px] leading-[1.55]">
                        <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                        <div>
                            <p className="font-semibold">
                                Resolva antes de enviar
                            </p>
                            <ul className="mt-1 list-inside list-disc">
                                {issues.map((issue) => (
                                    <li key={issue}>{issue}</li>
                                ))}
                            </ul>
                        </div>
                    </div>
                )}

                {scheduleSlot}

                <div className="border-primary-soft-border bg-primary-soft text-primary flex gap-2 rounded-xl border p-3.5 text-[12.5px] leading-[1.55]">
                    <Info className="mt-0.5 size-4 shrink-0" />
                    <span>
                        {multi
                            ? 'Ao enviar, cada participante recebe um link exclusivo por e-mail. Os arquivos ficam bloqueados para edição e cada evento passa a ser registrado na trilha de auditoria. O aceite de cada participante é vinculado à versão exata de cada arquivo, com o resumo SHA-256 de cada um.'
                            : showRoles
                              ? 'Ao enviar, cada participante recebe um link exclusivo por e-mail. O documento fica bloqueado para edição e cada evento passa a ser registrado na trilha de auditoria. Aceites e aprovações ficam vinculados à versão exata do arquivo que você está enviando; quem só acompanha não registra aceite.'
                              : 'Ao enviar, cada signatário recebe um link exclusivo por e-mail. O documento fica bloqueado para edição e cada evento passa a ser registrado na trilha de auditoria. O aceite eletrônico é vinculado à versão exata do arquivo que você está enviando.'}
                    </span>
                </div>
            </div>
        </div>
    );
}

function SummaryRow({ label, value }: { label: string; value: string }) {
    return (
        <div className="border-muted flex justify-between gap-3 border-t py-2 text-[13px]">
            <span className="text-text-secondary">{label}</span>
            <span className="text-right font-semibold">{value}</span>
        </div>
    );
}
