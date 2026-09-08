/**
 * Contratos de dados enviados pelo backend (Resources/Data → props Inertia).
 * Fonte: docs/design/ROUTES_AND_PAGES.md §2 com os renomes de RECONCILIACAO.md
 * (`public_code` → `display_code`, `routing_mode` → `signing_order`,
 * `EnvelopeEvent` → `AuditEvent`, `SignatureMethod` → `SignatureKind`).
 *
 * Datas em ISO-8601 UTC (string); valores monetários em centavos (number, BRL).
 */
import type {
    AuditEventKind,
    AuditEventType,
    AuthMethod,
    DocumentProcessingStatus,
    EnvelopeStatus,
    FieldType,
    InvitationStatus,
    MembershipRole,
    MembershipStatus,
    NotificationChannel,
    NotificationEvent,
    PaymentDisplayStatus,
    PaymentStatus,
    PlanCode,
    RecipientStatus,
    SignatureKind,
    SignatureStatus,
    SigningOrder,
    SubscriptionStatus,
} from './enums';

// ---------------------------------------------------------------------------
// Referências curtas
// ---------------------------------------------------------------------------

export interface UserRef {
    id: string;
    name: string;
    initials: string;
    email?: string;
}

export interface FolderRef {
    id: string;
    name: string;
}

export interface RecipientAvatar {
    id: string;
    name: string;
    initials: string;
    status: RecipientStatus;
}

// ---------------------------------------------------------------------------
// Organização, membros, convites
// ---------------------------------------------------------------------------

export interface Organization {
    id: string;
    name: string;
    legal_name: string | null;
    tax_id: string | null; // CNPJ/CPF já mascarado quando exibido
    contact_email: string | null;
    initials: string;
    logo_url: string | null;
    timezone: string;
    created_at: string;
}

export interface Membership {
    id: string;
    user: UserRef & { email: string };
    role: MembershipRole;
    role_label: string;
    status: MembershipStatus;
    status_label: string;
    two_factor_enabled: boolean;
    last_seen_at: string | null;
    is_me: boolean;
    can: {
        change_role: boolean;
        suspend: boolean;
        remove: boolean;
        transfer_ownership: boolean;
    };
}

export interface Invitation {
    id: string;
    email: string;
    role: MembershipRole;
    role_label: string;
    status?: InvitationStatus;
    sent_at: string;
    expires_at: string;
    invited_by: UserRef;
}

export interface RoleDefinition {
    key: MembershipRole;
    label: string;
    description: string;
}

export interface PermissionMatrixRow {
    key: string;
    label: string;
    description: string;
    grants: Record<MembershipRole, boolean>;
}

// ---------------------------------------------------------------------------
// Pastas
// ---------------------------------------------------------------------------

export interface Folder extends FolderRef {
    count?: number;
    created_at?: string;
}

export interface FolderFilterItem {
    id: string | null; // null = Todos
    name: string;
    count: number;
}

// ---------------------------------------------------------------------------
// Documentos / envelopes
// ---------------------------------------------------------------------------

export interface DocumentInfo {
    pages: number | null;
    mime: 'application/pdf' | null;
    original_name: string | null;
}

export interface DocumentProcessing {
    status: DocumentProcessingStatus;
    pages: number | null;
    error: string | null;
    progress_pct: number | null;
}

export interface EnvelopeDocument {
    id: string;
    original_name: string;
    size_bytes: number;
    mime: string;
    processing: DocumentProcessing;
    pdf_url: string;
    page_thumb_url_template: string; // ".../paginas/{page}.png"
    page_sizes: { page: number; width_pt: number; height_pt: number }[];
}

export interface EnvelopeCan {
    view: boolean;
    update: boolean;
    cancel: boolean;
    delete: boolean;
    download_signed: boolean;
}

export interface EnvelopeListItem {
    id: string;
    display_code: string; // "AV-00148"
    title: string;
    status: EnvelopeStatus;
    status_label: string; // ROUTES §6.1 (inclui regra Aguardando/Em andamento)
    folder: FolderRef | null;
    document: DocumentInfo | null;
    recipients: RecipientAvatar[]; // até 5
    recipients_count: number;
    signed_count: number; // "1 de 2"
    creator: UserRef;
    created_at: string;
    updated_at: string;
    expires_at: string | null;
    can: EnvelopeCan;
}

export interface Envelope {
    id: string;
    display_code: string;
    verification_code: string | null;
    title: string;
    status: EnvelopeStatus;
    status_label: string;
    signed_count: number;
    recipients_count: number;
    folder: FolderRef | null;
    creator: UserRef;
    created_at: string;
    expires_at: string | null;
    expires_label: string | null;
    expiring_soon: boolean;
    sent_at: string | null;
    completed_at: string | null;
    canceled_at: string | null;
    cancel_reason: string | null;
    signing_order: SigningOrder;
    message: string | null;
    send_copy_to_all: boolean;
    document: {
        original_name: string;
        size_bytes: number;
        pages: number;
        sha256_original: string;
        sha256_signed: string | null;
        pdf_url: string;
    } | null;
    downloads: {
        original: string | null;
        signed: string | null;
        evidence: string | null;
    };
    can: {
        update: boolean;
        cancel: boolean;
        delete: boolean;
        resend: boolean;
        duplicate: boolean;
        move: boolean;
    };
}

export interface RecipientEvidence {
    ip: string;
    user_agent_label: string;
    signature_kind: SignatureKind | null;
}

export interface Recipient {
    id: string;
    name: string;
    email: string;
    role: string | null;
    order: number;
    initials: string;
    color_index: number;
    status: RecipientStatus;
    status_label: string;
    channel: 'email';
    auth_methods: AuthMethod[];
    sent_at: string | null;
    viewed_at: string | null;
    signed_at: string | null;
    refused_at: string | null;
    refusal_reason: string | null;
    last_resent_at: string | null;
    can_resend: boolean;
    can_edit: boolean;
    evidence: RecipientEvidence | null; // só quando signed
}

export interface SigningField {
    id: string;
    recipient_id: string;
    type: FieldType;
    page: number | 'all';
    x: number;
    y: number;
    w: number;
    h: number;
    required?: boolean;
    label?: string | null;
    placeholder?: string | null;
    value: string | null;
    signed: boolean;
}

export interface AuditEvent {
    id: string;
    type: AuditEventType;
    kind: AuditEventKind;
    title: string;
    meta: string;
    occurred_at: string;
}

/** Linha da tela "Assinaturas" (recipient cross-envelope). */
export interface RecipientListItem {
    id: string;
    envelope_id: string;
    name: string;
    initials: string;
    email: string;
    role: string | null;
    envelope: { display_code: string; title: string; status: EnvelopeStatus };
    channel: 'email';
    auth_methods: AuthMethod[];
    status: RecipientStatus;
    status_label: string;
    when: string;
    note: string;
    can_resend: boolean;
}

// ---------------------------------------------------------------------------
// Planos, assinatura, pagamentos
// ---------------------------------------------------------------------------

export interface Plan {
    key: PlanCode;
    name: string;
    price_cents_monthly: number;
    price_cents_yearly: number | null;
    features: string[];
    limits?: {
        envelopes_per_month: number | null;
        members: number | null;
        storage_bytes: number | null;
    };
    highlighted?: boolean;
    cta?: 'current' | 'upgrade' | 'downgrade' | 'contact';
}

export interface Subscription {
    plan: Plan;
    status: SubscriptionStatus;
    status_label: string;
    interval: 'monthly' | 'yearly' | null;
    current_period_start: string | null;
    current_period_end: string | null;
    cancel_at_period_end: boolean;
    trial_ends_at: string | null;
}

export interface UsageMeter {
    used: number;
    limit: number | null; // null = ilimitado
}

export interface StorageMeter {
    used_bytes: number;
    limit_bytes: number | null;
}

export interface PlanUsage {
    envelopes: UsageMeter;
    members: UsageMeter;
    storage: StorageMeter;
}

export interface PaymentMethod {
    type:
        | 'credit_card'
        | 'debit_card'
        | 'pix'
        | 'boleto'
        | 'account_money'
        | 'other';
    label: string;
    last_four: string | null;
}

export interface BillingProfile {
    legal_name: string;
    document_number: string;
    address_line: string;
    city: string;
    state: string;
    postal_code: string;
    email: string;
}

export interface Payment {
    id: string;
    paid_at: string | null;
    created_at: string;
    description: string;
    amount_cents: number;
    status: PaymentStatus;
    display_status?: PaymentDisplayStatus;
    status_label: string;
    receipt_url: string | null;
    mp_payment_id: string | null;
}

// ---------------------------------------------------------------------------
// Verificação pública
// ---------------------------------------------------------------------------

export interface VerificationResult {
    verification_code: string;
    status: 'completed' | 'in_progress' | 'refused' | 'expired' | 'canceled';
    status_label: string;
    title: string;
    organization_name: string;
    created_at: string;
    sent_at: string | null;
    completed_at: string | null;
    pages: number;
    hashes: { original_sha256: string; signed_sha256: string | null };
    signature_status: SignatureStatus;
    recipients: {
        name_masked: string;
        role: string | null;
        status: RecipientStatus;
        status_label: string;
        signed_at: string | null;
    }[];
    certificate: {
        subject_cn: string;
        issuer_cn: string;
        valid_to: string;
        policy: string;
    } | null;
    events_summary: { label: string; occurred_at: string }[];
}

// ---------------------------------------------------------------------------
// Notificações
// ---------------------------------------------------------------------------

export interface AppNotification {
    id: string;
    title: string;
    body: string | null;
    url: string | null;
    read_at: string | null;
    created_at: string;
}

export interface NotificationPreferenceRow {
    key: NotificationEvent;
    label: string;
    description: string;
    channels: Record<NotificationChannel, boolean>;
    locked: Partial<Record<NotificationChannel, boolean>>;
}
