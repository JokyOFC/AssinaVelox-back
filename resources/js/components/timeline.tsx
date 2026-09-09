import {
    Ban,
    CalendarX2,
    CheckCheck,
    CircleAlert,
    Copy,
    Eye,
    FilePlus2,
    FileSignature,
    FileText,
    FolderInput,
    Hourglass,
    KeyRound,
    Loader2,
    LogIn,
    Mail,
    MailCheck,
    PenLine,
    Send,
    ShieldCheck,
    Trash2,
    Upload,
    Users,
    Wallet,
    XCircle,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { AuditEvent, AuditEventKind, AuditEventType } from '@/types';

const KIND_CLASSES: Record<AuditEventKind, string> = {
    info: 'bg-primary-soft text-primary',
    ok: 'bg-success-bg text-success',
    warn: 'bg-warning-bg text-warning',
};

/**
 * Ícone por tipo de evento (RECONCILIACAO §3). O tom (`kind`) continua vindo
 * do backend; o ícone só torna a leitura mais rápida.
 */
export const AUDIT_EVENT_ICONS: Partial<Record<AuditEventType, LucideIcon>> = {
    'envelope.created': FilePlus2,
    'envelope.updated': PenLine,
    'document.uploaded': Upload,
    'document.conversion_started': Loader2,
    'document.converted': FileText,
    'document.processing_failed': CircleAlert,
    'document.blocked': Ban,
    'document.removed': Trash2,
    'fields.updated': PenLine,
    'recipients.updated': Users,
    'envelope.sent': Send,
    'invitation.sent': Mail,
    'invitation.resent': MailCheck,
    'invitation.opened': Eye,
    'challenge.sent': KeyRound,
    'challenge.verified': ShieldCheck,
    'challenge.failed': CircleAlert,
    'session.started': LogIn,
    'document.presented': FileText,
    'acceptance.recorded': FileSignature,
    'recipient.refused': XCircle,
    'envelope.refused': XCircle,
    'envelope.expired': CalendarX2,
    'envelope.canceled': Ban,
    'envelope.finalizing': Hourglass,
    'envelope.consolidated': FileText,
    'envelope.evidence_generated': ShieldCheck,
    'envelope.signed_company_a1': ShieldCheck,
    'envelope.completed': CheckCheck,
    'envelope.finalization_failed': CircleAlert,
    'envelope.downloaded': FileText,
    'envelope.moved': FolderInput,
    'envelope.duplicated': Copy,
    'plan.consumption_reserved': Wallet,
    'plan.consumption_committed': Wallet,
    'plan.consumption_released': Wallet,
};

/**
 * Notas que evitam leitura equivocada de um evento. "Abertura detectada" não é
 * "leitura": o registro é do acesso ao link, nada além disso (arquitetura §4.1).
 */
export const AUDIT_EVENT_NOTES: Partial<Record<AuditEventType, string>> = {
    'invitation.opened':
        'Abertura detectada — registra o acesso ao link, não comprova leitura.',
    'document.presented':
        'O arquivo foi entregue à sessão de assinatura — registra a apresentação, não comprova leitura.',
    'acceptance.recorded':
        'Aceite eletrônico com evidências (data, IP, navegador, código confirmado por e-mail).',
    'envelope.signed_company_a1':
        'Assinatura criptográfica da operadora — identifica a AssinaVelox, não é a assinatura pessoal do participante.',
};

export type TimelineEvent = Pick<
    AuditEvent,
    'id' | 'kind' | 'title' | 'meta' | 'occurred_at'
> &
    Partial<Pick<AuditEvent, 'type'>>;

/**
 * Trilha de auditoria (DESIGN §4.18): marcador 20px colorido por `kind`,
 * conector vertical, título 13.5px e meta 12px.
 *
 * `markers="icon"` troca o número sequencial pelo ícone do tipo de evento —
 * usado no detalhe do documento. A página de evidências mantém a numeração,
 * que é o que se cita num dossiê impresso.
 */
export function Timeline({
    events,
    footer,
    emptyText = 'Nenhum evento registrado ainda.',
    markers = 'number',
    showNotes = false,
    className,
}: {
    events: TimelineEvent[];
    footer?: ReactNode;
    emptyText?: string;
    markers?: 'number' | 'icon';
    /** Exibe a nota explicativa do tipo (ver `AUDIT_EVENT_NOTES`). */
    showNotes?: boolean;
    className?: string;
}) {
    if (events.length === 0) {
        return (
            <p className="text-muted-foreground px-1 py-6 text-center text-[13.5px]">
                {emptyText}
            </p>
        );
    }

    return (
        <div className={cn('flex flex-col', className)}>
            <ol className="flex flex-col">
                {events.map((event, index) => {
                    const last = index === events.length - 1;
                    const Icon = event.type
                        ? AUDIT_EVENT_ICONS[event.type]
                        : undefined;
                    const note =
                        showNotes && event.type
                            ? AUDIT_EVENT_NOTES[event.type]
                            : undefined;

                    return (
                        <li
                            key={event.id}
                            className="grid grid-cols-[20px_1fr] gap-3"
                        >
                            <div className="flex flex-col items-center">
                                <span
                                    className={cn(
                                        'flex size-5 shrink-0 items-center justify-center rounded-full text-[10px] font-bold',
                                        KIND_CLASSES[event.kind],
                                    )}
                                >
                                    {markers === 'icon' && Icon ? (
                                        <Icon className="size-3" />
                                    ) : (
                                        index + 1
                                    )}
                                </span>
                                {!last && (
                                    <span className="bg-accent my-1 w-0.5 flex-1" />
                                )}
                            </div>
                            <div className={cn(!last && 'pb-4')}>
                                <p className="text-[13.5px] leading-[1.3] font-semibold">
                                    {event.title}
                                </p>
                                <p className="text-muted-foreground tabular mt-[3px] text-[12px]">
                                    {formatDateTime(event.occurred_at)}
                                    {event.meta && ` · ${event.meta}`}
                                </p>
                                {note && (
                                    <p className="text-muted-foreground mt-1 text-[11.5px] leading-[1.4] italic">
                                        {note}
                                    </p>
                                )}
                            </div>
                        </li>
                    );
                })}
            </ol>
            {footer}
        </div>
    );
}

/** Caixa neutra para hash SHA-256 (DESIGN §4.11). */
export function HashBox({
    label,
    value,
    action,
    className,
}: {
    label: string;
    value: string | null | undefined;
    action?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'border-border bg-sidebar rounded-lg border p-3 text-[12px]',
                className,
            )}
        >
            <div className="flex items-center justify-between gap-2">
                <span className="text-text-secondary font-semibold">
                    {label}
                </span>
                {action}
            </div>
            <code className="text-foreground mt-1 block font-mono break-all">
                {value ?? '—'}
            </code>
        </div>
    );
}
