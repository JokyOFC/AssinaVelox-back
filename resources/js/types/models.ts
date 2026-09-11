/**
 * Contratos de dados enviados pelo backend (Resources/Data → props Inertia).
 * Fonte: docs/design/ROUTES_AND_PAGES.md §2 com os renomes de RECONCILIACAO.md
 * (`public_code` → `display_code`, `routing_mode` → `signing_order`,
 * `EnvelopeEvent` → `AuditEvent`, `SignatureMethod` → `SignatureKind`).
 *
 * Datas em ISO-8601 UTC (string); valores monetários em centavos (number, BRL).
 */
import type {
    AcceptanceAction,
    AuditEventKind,
    AuditEventType,
    AuthMethod,
    CaptureKind,
    DeliveryChannel,
    DocumentProcessingStatus,
    DocumentSourceType,
    EnvelopeStatus,
    InvitationStatus,
    MembershipRole,
    MembershipStatus,
    NotificationChannel,
    NotificationEvent,
    PaymentDisplayStatus,
    ParticipantRole,
    PaymentStatus,
    PlanCode,
    RecipientStatus,
    SignatureKind,
    SignatureStatus,
    SignerAuthMethod,
    SigningFieldType,
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
    /** Rótulo PT-BR já pronto (espelho de `DocumentProcessingStatus::label()`). */
    label: string;
    pages: number | null;
    error: string | null;
    failure_code: string | null;
    /** `ready` **e** com versão exibível — é este o gate do editor. */
    ready: boolean;
    terminal: boolean;
    /** Sempre `null`: o pipeline não reporta percentual (docs/preparacao-documental.md §9). */
    progress_pct: number | null;
}

export interface EnvelopeDocument {
    id: string;
    original_name: string;
    size_bytes: number;
    mime: string;
    processing: DocumentProcessing;
    source_type: DocumentSourceType;
    /**
     * `envelopes.document.preview` — transmite a versão **exibível** em
     * `application/pdf`. `null` enquanto não há versão exibível. O visualizador
     * faz `fetch` com as credenciais da sessão.
     */
    pdf_url: string | null;
    /** Sempre `null`: as miniaturas são renderizadas no navegador pelo PDF.js. */
    page_thumb_url_template: string | null;
    /**
     * Dimensões **exibidas** (rotação já aplicada) de cada página, em pontos.
     * Usadas só para converter os tamanhos mínimos de campo; a geometria em si
     * sai do canvas do PDF.js e é revalidada pelo backend.
     */
    page_sizes: {
        page: number;
        width_pt: number;
        height_pt: number;
        rotation: number;
    }[];
    sha256: string | null;
    /** Fase 2 §2.3: posição na lista de arquivos do envelope (1..N). */
    position?: number;
    /** Fase 2 §2.3: nome de exibição do arquivo. */
    name?: string | null;
}

/** Flags de domínio da organização no wizard (`DomainFeatures::forOrganization`). */
export interface DomainFeatures {
    multi_document: boolean;
    participant_roles: boolean;
}

/** Opção do seletor de papel (`WizardProps.participant_roles`). */
export interface ParticipantRoleOption {
    value: ParticipantRole;
    label: string;
}

/**
 * Arquivo do envelope no detalhe (`EnvelopeDetailResource::documentsList`,
 * docs/fase-2/multi-documento-e-papeis.md §8.2). URLs já trazem `?document=`.
 */
export interface EnvelopeFile {
    id: string;
    position: number;
    name: string | null;
    original_name: string | null;
    processing_status: DocumentProcessingStatus;
    pages: number;
    size_bytes: number;
    sha256_original: string | null;
    sha256_sent: string | null;
    sha256_final: string | null;
    pdf_url: string | null;
    downloads: {
        original: string | null;
        signed: string | null;
        evidence: string | null;
    };
}

/**
 * Lembretes automáticos e envio agendado (`ReminderProps::forEnvelope`,
 * docs/fase-2/lembretes-e-agendamento.md §7.2). `available = false` mantém a
 * interface da Fase 1.
 */
export interface ReminderSettings {
    enabled: boolean;
    first_after_days: number;
    interval_days: number;
    max_count: number;
}

export interface EnvelopeReminders {
    available: boolean;
    settings: ReminderSettings;
    /** `true` = sem cadência própria (no rascunho, é o padrão da organização). */
    is_default: boolean;
    /** "A cada 2 dias · até 3 lembretes" | "Desativados". */
    summary: string;
    limits: Record<
        'first_after_days' | 'interval_days' | 'max_count',
        { min: number; max: number }
    >;
    window: { window_start_hour: number; window_end_hour: number };
    /** Identificador IANA (cálculos de data); nunca exibido cru. */
    timezone: string;
    /** "horário de Brasília (GMT-3)" — rótulo PT-BR do fuso, para as frases. */
    timezone_label: string;
    recipients: Record<string, { sent: number; last_sent_at: string | null }>;
    scheduled_send: {
        at: string;
        at_local: string;
        input_value: string;
        timezone: string;
        timezone_label: string;
    } | null;
    scheduled_send_limits: { min_lead_minutes: number; max_days: number };
}

// ---------------------------------------------------------------------------
// Wizard "Nova solicitação" (ROUTES §2.6)
// ---------------------------------------------------------------------------

export interface WizardRecipient {
    /** ULID quando já persistido; `null` enquanto é só rascunho no cliente. */
    id: string | null;
    /** Chave estável no cliente — liga os campos ao signatário antes do save. */
    client_id: string;
    name: string;
    email: string;
    role: string;
    order: number; // 1..n (persistido também no modo paralelo)
    color_index: number; // paleta de `components/envelopes/recipient-colors`
    /** Canal do convite; `email` = só e-mail (Fase 1). Fase 2 §2.9: `sms`/`whatsapp` somam um aviso. */
    channel: DeliveryChannel;
    auth_methods: AuthMethod[]; // Fase 1: sempre ['email_otp']
    /** Fase 2 §2.4: papel de domínio. Ausente = `signer` (Fase 1). */
    participant_role?: ParticipantRole;
    participant_role_label?: string;
    /*
     * Fase 2 §2.9 (`RecipientChannels::wizardFields`, docs/fase-2/canais-e-pin.md §9.1).
     * Ausentes enquanto o backend não mesclar esses campos no `RecipientWizardResource`.
     */
    /** E.164 (`+5511912345678`) como o servidor gravou, ou o que o remetente digitou. */
    phone?: string | null;
    phone_masked?: string;
    /** Canal do código. Ausente = o primeiro de `auth_methods`. */
    auth_method?: AuthMethod;
    auth_method_label?: string;
    /** Há PIN do remetente gravado. O PIN em si nunca volta do servidor. */
    has_pin?: boolean;
    /**
     * SÓ NO CLIENTE: PIN digitado e ainda não salvo. Viaja uma única vez no próximo
     * `recipients.sync` e é descartado quando a gravação volta.
     */
    pin?: string;
    /** SÓ NO CLIENTE: pedir a remoção do PIN no próximo `recipients.sync`. */
    remove_pin?: boolean;
}

// ---------------------------------------------------------------------------
// Fase 2 §2.9 — canais, código por SMS/WhatsApp e PIN (docs/fase-2/canais-e-pin.md §9)
// ---------------------------------------------------------------------------

/** Disponibilidade de um canal agora (`ChannelAvailability::describe`). */
export interface ChannelInfo {
    channel: DeliveryChannel;
    label: string;
    available: boolean;
    /** Provedor simulado: a mensagem não chega a celular nenhum. */
    simulated: boolean;
    provider: string;
    reason_code: 'feature_disabled' | 'provider_disabled' | null;
    /** Motivo pronto para a tela quando indisponível. */
    reason: string | null;
    /** "Ambiente de testes: as mensagens por SMS são simuladas…" */
    notice: string | null;
}

export interface AuthMethodInfo {
    value: AuthMethod;
    label: string;
    channel: DeliveryChannel;
    requires_phone: boolean;
    available: boolean;
    simulated: boolean;
    reason_code: string | null;
    reason: string | null;
    notice: string | null;
}

/** Prop `channels` do wizard (`ChannelAvailability::wizardProps`). */
export interface WizardChannels {
    /** Flag `sms_whatsapp` da organização. */
    enabled: boolean;
    channels: Record<DeliveryChannel, ChannelInfo>;
    auth_methods: AuthMethodInfo[];
    pin: {
        /** Flag `pin_auth` da organização. */
        enabled: boolean;
        min_length: number;
        max_length: number;
        notice: string;
    };
    phone: { default_region: string; example: string };
}

/** Prop `auth` da página pública (`SignerAuthProps::for`). */
export interface SignerAuth {
    method: AuthMethod;
    method_label: string;
    channel: DeliveryChannel;
    channel_label: string;
    /** "m•••@exemplo.com" ou "+55 •••••••5678". */
    destination: string;
    simulated: boolean;
    available: boolean;
    unavailable_reason: string | null;
    notice: string | null;
    /** `pin` = o código já foi confirmado e falta o PIN do remetente. */
    step: 'code' | 'pin';
    pin: null | {
        required: true;
        step_active: boolean;
        min_length: number;
        max_length: number;
        attempts_left: number;
        locked_until: string | null;
        blocked: boolean;
    };
}

// ---------------------------------------------------------------------------
// Fase 2 §2.10/§2.11 — captura simples e CNPJ (docs/fase-2/identidade.md §7)
// ---------------------------------------------------------------------------

/** Uma foto exigida na etapa de captura (`CaptureStep::props`). */
export interface IdentityCaptureItem {
    kind: CaptureKind;
    label: string;
    instructions: string;
    facing_mode: 'user' | 'environment';
    required: true;
    captured: boolean;
    captured_at: string | null;
    width: number | null;
    height: number | null;
    /** POST multipart `image` (+ `source`). `null` antes do código. */
    upload_url: string | null;
}

/** Prop `identity_capture` da página pública; `null` quando não se aplica. */
export interface IdentityCaptureStep {
    required: true;
    complete: boolean;
    title: string;
    items: IdentityCaptureItem[];
    accept: string[];
    max_upload_kb: number;
    retention_days: number;
    notice: string;
}

/** Foto na página de evidências do remetente (`CaptureEvidence::forEnvelope`). */
export interface IdentityCaptureEvidence {
    id: string;
    kind: CaptureKind;
    kind_label: string;
    /** "Imagem enviada pelo participante — origem informada pelo navegador: …" */
    label: string;
    /** Origem DECLARADA pelo navegador no envio; não verificada. */
    source?: 'camera' | 'upload' | null;
    /** "Origem informada pelo navegador: câmera (não verificada)" … */
    source_label?: string;
    captured_at: string | null;
    width: number | null;
    height: number | null;
    sha256: string | null;
    available: boolean;
    purged_at: string | null;
    /** data URI da miniatura (≤ 320 px) ou `null` depois da retenção. */
    thumbnail: string | null;
}

/** Resposta de `settings.organization.cnpj` e `cnpj.lookup`. */
export interface CnpjLookupResult {
    status: 'found' | 'not_found' | 'invalid' | 'unavailable' | 'rate_limited';
    cnpj: string | null;
    data: null | {
        legal_name: string | null;
        trade_name: string | null;
        registration_status: string | null;
        address: {
            street: string | null;
            number: string | null;
            complement?: string | null;
            district: string | null;
            city: string | null;
            state: string | null;
            postal_code: string | null;
        } | null;
        cnae: { code: string | null; description: string | null } | null;
    };
    suggestions: null | { legal_name: string | null; name: string | null };
    source: null | {
        provider: string;
        simulated: boolean;
        attribution?: string | null;
        fetched_at?: string | null;
        cached?: boolean;
        reason?: string | null;
    };
    message: string;
    manual_fill: true;
    retry_after: number | null;
}

/**
 * Opções por tipo de campo (espelho de `signing_fields.options`). O índice
 * aberto preserva chaves que o backend acrescente (`auto`, `default`,
 * `placeholder`) num vaivém sem perdas.
 */
export interface WizardFieldOptions {
    font_size?: number | null;
    date_format?: string | null;
    [key: string]: string | number | boolean | null | undefined;
}

export interface WizardField {
    id: string | null;
    /** ULID quando persistido; id temporário do cliente enquanto é novo. */
    client_id: string;
    /** ULID do destinatário (o backend resolve `recipient_client_id` como ULID). */
    recipient_client_id: string;
    type: SigningFieldType;
    /** 1-based. `'all'` só para `initials` (rubrica em todas as páginas). */
    page: number | 'all';
    /** Frações [0,1] do CropBox exibido, origem no canto superior esquerdo. */
    x: number;
    y: number;
    w: number;
    h: number;
    required: boolean;
    label: string | null;
    placeholder: string | null;
    options?: WizardFieldOptions | null;
    /** Rubrica gerada pelo servidor ("Rubrica em todas as páginas"): não editável. */
    auto?: boolean;
    /** Fase 2 §2.3: ULID do arquivo onde o campo fica (ausente/null = o primeiro). */
    document_id?: string | null;
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
    /** Só quem participa da coleta (visualizadores ficam fora — Fase 2 §2.4). */
    recipients_count: number;
    /** Fase 2 §2.4: visualizadores (recebem cópia, não assinam). */
    viewers_count?: number;
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
    /** Fase 2 §2.3: todos os arquivos, na ordem (o primeiro é `document`). */
    documents?: EnvelopeFile[];
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
    channel: DeliveryChannel;
    /** Pode trazer `sender_pin` quando o remetente definiu um PIN (Fase 2 §2.9). */
    auth_methods: SignerAuthMethod[];
    /** Revisão da onda B: método do código, celular mascarado e estado do PIN (edição pós-envio). */
    auth_method?: Exclude<SignerAuthMethod, 'sender_pin'>;
    phone_masked?: string | null;
    pin_state?: 'active' | 'locked' | 'blocked' | null;
    sent_at: string | null;
    viewed_at: string | null;
    signed_at: string | null;
    refused_at: string | null;
    refusal_reason: string | null;
    last_resent_at: string | null;
    can_resend: boolean;
    can_edit: boolean;
    evidence: RecipientEvidence | null; // só quando signed
    /** Fase 2 §2.4 (aditivos). */
    participant_role?: ParticipantRole;
    participant_role_label?: string;
    acceptance_action?: AcceptanceAction | null;
}

export interface SigningField {
    id: string;
    recipient_id: string;
    /** Fase 2 §2.3: arquivo onde o campo está. */
    document_id?: string | null;
    type: SigningFieldType;
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
    channel: DeliveryChannel;
    auth_methods: SignerAuthMethod[];
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
