/**
 * Mapas enum → rótulo PT-BR e variante de badge.
 * Fonte: ROUTES_AND_PAGES §6 e DESIGN_SYSTEM §5, com os renomes da RECONCILIACAO.
 */
import type {
    AuditEventKind,
    DocumentProcessingStatus,
    EnvelopeStatus,
    FieldType,
    InvitationStatus,
    MembershipRole,
    MembershipStatus,
    NotificationChannel,
    PaymentDisplayStatus,
    PaymentStatus,
    PlanCode,
    RecipientStatus,
    SignatureKind,
    SigningOrder,
    SubscriptionStatus,
} from '@/types/enums';

/** Variantes semânticas de badge (cores em DESIGN §1.1 "Semânticas"). */
export type BadgeTone =
    | 'success'
    | 'warning'
    | 'danger'
    | 'info'
    | 'neutral'
    | 'draft';

export interface StatusPresentation {
    label: string;
    tone: BadgeTone;
}

// ---------------------------------------------------------------------------
// Envelope
// ---------------------------------------------------------------------------

export const envelopeStatusLabels: Record<EnvelopeStatus, string> = {
    draft: 'Rascunho',
    preparing: 'Rascunho · processando',
    ready: 'Rascunho',
    in_progress: 'Aguardando',
    finalizing: 'Em andamento · finalizando',
    completed: 'Assinado',
    refused: 'Recusado',
    expired: 'Expirado',
    canceled: 'Cancelado',
};

export const envelopeStatusTones: Record<EnvelopeStatus, BadgeTone> = {
    draft: 'draft',
    preparing: 'draft',
    ready: 'draft',
    in_progress: 'warning',
    finalizing: 'info',
    completed: 'success',
    refused: 'danger',
    expired: 'neutral',
    canceled: 'neutral',
};

/**
 * Regra "Aguardando × Em andamento" (ROUTES §6.1): `in_progress` com ≥ 1
 * assinatura é "Em andamento" (azul); sem assinaturas é "Aguardando" (âmbar).
 */
export function envelopeStatusPresentation(
    status: EnvelopeStatus,
    signedCount = 0,
    labelOverride?: string | null,
): StatusPresentation {
    if (status === 'in_progress' && signedCount > 0) {
        return { label: labelOverride ?? 'Em andamento', tone: 'info' };
    }

    return {
        label: labelOverride ?? envelopeStatusLabels[status],
        tone: envelopeStatusTones[status],
    };
}

export const envelopeTabLabels = {
    all: 'Todos',
    awaiting: 'Aguardando',
    in_progress: 'Em andamento',
    completed: 'Concluídos',
    drafts: 'Rascunhos',
    refused_expired: 'Recusados / expirados',
} as const;

// ---------------------------------------------------------------------------
// Recipient
// ---------------------------------------------------------------------------

export const recipientStatusLabels: Record<RecipientStatus, string> = {
    pending: 'Pendente',
    notified: 'Pendente',
    viewed: 'Pendente',
    signed: 'Assinado',
    refused: 'Recusado',
    expired: 'Expirado',
    canceled: 'Cancelado',
};

export const recipientStatusTones: Record<RecipientStatus, BadgeTone> = {
    pending: 'warning',
    notified: 'warning',
    viewed: 'warning',
    signed: 'success',
    refused: 'danger',
    expired: 'neutral',
    canceled: 'neutral',
};

/** Nota curta padrão por status (o backend pode enviar `note` mais rica). */
export const recipientStatusNotes: Record<RecipientStatus, string> = {
    pending: 'Aguarda a vez',
    notified: 'Enviado · não visualizou',
    viewed: 'Visualizou',
    signed: 'Assinado',
    refused: 'Recusou',
    expired: 'Prazo encerrado',
    canceled: 'Documento cancelado',
};

export const recipientTabLabels = {
    all: 'Todas',
    pending: 'Pendentes',
    signed: 'Assinadas',
    refused: 'Recusadas',
    expired: 'Expiradas',
} as const;

// ---------------------------------------------------------------------------
// Documento
// ---------------------------------------------------------------------------

export const documentProcessingLabels: Record<
    DocumentProcessingStatus,
    string
> = {
    uploaded: 'Enviado agora',
    converting: 'Convertendo…',
    ready: 'Pronto',
    failed: 'Falha ao processar',
    blocked: 'Arquivo bloqueado',
};

export const documentProcessingTones: Record<
    DocumentProcessingStatus,
    BadgeTone
> = {
    uploaded: 'info',
    converting: 'info',
    ready: 'success',
    failed: 'danger',
    blocked: 'danger',
};

export const fieldTypeLabels: Record<FieldType, string> = {
    signature: 'Assinatura',
    initials: 'Rubrica',
    name: 'Nome completo',
    date: 'Data',
    text: 'Texto livre',
    checkbox: 'Caixa de seleção',
};

export const signingOrderLabels: Record<SigningOrder, string> = {
    sequential: 'Sequencial',
    parallel: 'Todos ao mesmo tempo',
};

export const signatureKindLabels: Record<SignatureKind, string> = {
    drawn: 'Desenhada',
    typed: 'Digitada',
    uploaded: 'Imagem enviada',
};

export const authMethodLabels = {
    email_otp: 'Código por e-mail',
} as const;

export const deliveryChannelLabels = {
    email: 'E-mail',
    sms: 'SMS',
    whatsapp: 'WhatsApp',
} as const;

export const auditKindTones: Record<AuditEventKind, BadgeTone> = {
    ok: 'success',
    info: 'info',
    warn: 'warning',
};

// ---------------------------------------------------------------------------
// Membros, convites
// ---------------------------------------------------------------------------

export const membershipRoleLabels: Record<MembershipRole, string> = {
    owner: 'Proprietário',
    admin: 'Administrador',
    member: 'Operador',
};

export const membershipRoleDescriptions: Record<MembershipRole, string> = {
    owner: 'Acesso total, inclusive cobrança e exclusão da conta',
    admin: 'Gerencia usuários, pastas, documentos e configurações',
    member: 'Cria e envia documentos; vê apenas os próprios',
};

export const membershipStatusLabels: Record<MembershipStatus, string> = {
    active: 'Ativo',
    suspended: 'Inativo',
};

export const membershipStatusTones: Record<MembershipStatus, BadgeTone> = {
    active: 'success',
    suspended: 'neutral',
};

export const invitationStatusLabels: Record<InvitationStatus, string> = {
    pending: 'Convite pendente',
    accepted: 'Aceito',
    expired: 'Expirado',
    revoked: 'Revogado',
};

export const invitationStatusTones: Record<InvitationStatus, BadgeTone> = {
    pending: 'warning',
    accepted: 'success',
    expired: 'neutral',
    revoked: 'neutral',
};

// ---------------------------------------------------------------------------
// Plano, assinatura, pagamento
// ---------------------------------------------------------------------------

export const planLabels: Record<PlanCode, string> = {
    free: 'Grátis',
    professional: 'Profissional',
    enterprise: 'Empresarial',
};

export const subscriptionStatusLabels: Record<SubscriptionStatus, string> = {
    pending: 'Pendente',
    trialing: 'Trial',
    active: 'Ativo',
    past_due: 'Inadimplente',
    canceled: 'Cancelado',
    expired: 'Expirado',
};

export const subscriptionStatusTones: Record<SubscriptionStatus, BadgeTone> = {
    pending: 'warning',
    trialing: 'info',
    active: 'success',
    past_due: 'danger',
    canceled: 'neutral',
    expired: 'neutral',
};

export const adminOrgTabLabels = {
    all: 'Todas',
    active: 'Ativas',
    trialing: 'Em trial',
    past_due: 'Inadimplentes',
    canceled: 'Canceladas',
} as const;

/** Agrupa o status bruto do Mercado Pago no status de exibição. */
export function paymentDisplayStatus(
    status: PaymentStatus,
): PaymentDisplayStatus {
    switch (status) {
        case 'approved':
        case 'authorized':
            return 'paid';
        case 'pending':
        case 'in_process':
        case 'in_mediation':
            return 'pending';
        case 'rejected':
            return 'failed';
        case 'refunded':
        case 'charged_back':
            return 'refunded';
        case 'cancelled':
            return 'cancelled';
    }
}

export const paymentDisplayLabels: Record<PaymentDisplayStatus, string> = {
    paid: 'Paga',
    pending: 'Pendente',
    failed: 'Falhou',
    refunded: 'Reembolsada',
    cancelled: 'Cancelada',
};

export const paymentDisplayTones: Record<PaymentDisplayStatus, BadgeTone> = {
    paid: 'success',
    pending: 'warning',
    failed: 'danger',
    refunded: 'neutral',
    cancelled: 'neutral',
};

// ---------------------------------------------------------------------------
// Notificações
// ---------------------------------------------------------------------------

export const notificationChannelLabels: Record<NotificationChannel, string> = {
    mail: 'E-mail',
    database: 'No app',
};
