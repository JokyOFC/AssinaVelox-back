import type { MembershipRole, PlanCode, SubscriptionStatus } from './enums';

export type * from './enums';
export type * from './models';
export type * from './navigation';
export type * from './signatures';
export type * from './external-signing';

// ---------------------------------------------------------------------------
// Props compartilhadas (HandleInertiaRequests::share) — ROUTES §0.3
// ---------------------------------------------------------------------------

export interface AuthUser {
    id: string; // ULID
    name: string;
    email: string;
    initials: string; // "AR" — calculado no backend
    avatar_url: string | null; // null na Fase 1
    email_verified_at: string | null;
    two_factor_enabled: boolean;
    is_platform_admin: boolean;
    locale: 'pt_BR';
    timezone: string; // ex.: "America/Sao_Paulo"
}

export interface OrgPermissions {
    manage_members: boolean; // owner, admin
    manage_settings: boolean; // owner, admin
    manage_billing: boolean; // owner, admin
    delete_organization: boolean; // owner
    cancel_any_envelope: boolean; // owner, admin
    view_all_envelopes: boolean; // owner, admin
    manage_folders: boolean; // owner, admin
    /*
     * Fase 2 §2.14 — o catálogo completo (App\Enums\Permission) só chega com a flag
     * `custom_roles` ligada (Permissions::sharedMap); desligada, só as 7 chaves acima.
     */
    create_envelopes?: boolean;
    send_envelopes?: boolean;
    manage_any_envelope?: boolean;
    manage_templates?: boolean;
    manage_tags?: boolean;
    view_reports?: boolean;
    export_data?: boolean;
    view_audit_log?: boolean;
    manage_roles?: boolean;
    manage_teams?: boolean;
    manage_integrations?: boolean;
    transfer_ownership?: boolean;
}

export interface CurrentOrganization {
    id: string;
    name: string;
    legal_name: string | null;
    initials: string;
    logo_url: string | null;
    timezone?: string;
    role: MembershipRole;
    plan: {
        key: PlanCode;
        name: string;
        status: SubscriptionStatus;
    };
    permissions: OrgPermissions;
}

export interface OrganizationSummary {
    id: string;
    name: string;
    initials: string;
    plan_name: string;
    role: MembershipRole;
    is_current: boolean;
}

export interface Flash {
    success?: string;
    error?: string;
    warning?: string;
    info?: string;
    status?: string;
}

export interface Features {
    templates: boolean;
    api_integrations: boolean;
    reminders: boolean;
    sms_whatsapp: boolean;
    branding: boolean;
    multi_document: boolean;
    certificate_login: boolean;
    /** Fase 2 §2.4 — testemunha, aprovador e visualizador. */
    participant_roles: boolean;
    /** Fase 2 §2.14 — funções personalizadas, times e acesso por pasta. */
    custom_roles: boolean;
    /** Fase 2 §2.14 — etiquetas, relatórios e registro de atividades da organização. */
    tags: boolean;
    reports: boolean;
    audit_log: boolean;
    /** Fase 2 §2.14 — painel interno (só o interruptor global). */
    admin_users: boolean;
    admin_audit: boolean;
    impersonation: boolean;
    /*
     * Fase 2, onda B — opcionais até `HandleInertiaRequests::features()` compartilhá-las
     * (`ChannelFeatures::forOrganization` e `IdentityFeatures::forOrganization`). Ausente =
     * desligada: a interface continua a da Fase 1.
     */
    /** §2.9 — PIN do remetente por participante. */
    pin_auth?: boolean;
    /** §2.8 parte B — domínios de envio próprios. */
    sender_domains?: boolean;
    /** §2.11 — tipo de campo CPF no editor. */
    cpf_field?: boolean;
    /** §2.11 — consulta cadastral do CPF no aceite (produção desabilitada). */
    cpf_lookup?: boolean;
    /** §2.11 — autopreenchimento por CNPJ (cadastro: só o interruptor global). */
    cnpj_lookup?: boolean;
    /** §2.10 — captura simples de foto do rosto e do documento. */
    identity_capture?: boolean;
    /** §2.6 — sessão presencial em tablet. */
    in_person?: boolean;
    /** §2.7 — link de assinatura em lote (autorização item a item). */
    batch_signing?: boolean;
    /** §2.2 — formulário público que gera envelope a partir de um modelo. */
    public_forms?: boolean;
    /*
     * Fase 2, onda C — opcionais até `HandleInertiaRequests::features()` compartilhá-las
     * (`DossierFeature::enabled`, `TimestampFeatures`, `RetentionFeature::enabled`).
     * Ausente = desligada. A assinatura com o certificado do participante NÃO tem chave aqui:
     * a página pública descobre o recurso por `GET sign.certificate.show` (404 = desligado).
     */
    /** §2.13 — "Baixar dossiê (ZIP)" no detalhe e "Baixar dossiês" em lote. */
    dossier_export?: boolean;
    /** §2.13 — TSA da operadora (só informativo na interface). */
    operator_tsa?: boolean;
    /** §2.13 — B-T técnico; a interface continua anunciando PAdES-B-B. */
    pades_bt?: boolean;
    /** §2.19 — retenção e preservação. */
    retention_policies?: boolean;
    /*
     * Fase 2, onda D — `api_integrations` (acima) deixou de ser sempre `false`. Opcionais pelo
     * mesmo motivo das ondas anteriores: ausente = desligada.
     */
    /** §2.16 — webhooks de saída (global E plano). */
    outbound_webhooks?: boolean;
    /** §2.17 — REST Hooks (exige também a API e os webhooks). */
    rest_hooks?: boolean;
    /** §2.20 — pagamentos ampliados (só a chave da plataforma). */
    extended_payments?: boolean;
    /** §2.21 — status de NFS-e por pagamento (só a chave da plataforma; emissão real bloqueada). */
    fiscal_invoices?: boolean;
    /*
     * Fase 3, parte 1 — só chaves da plataforma. A3 e devolução gov.br NÃO têm chave aqui: a
     * página pública descobre os recursos pelos próprios `GET` (404 = desligado).
     */
    /** §3.7 — antifraude com revisão humana (painel interno e pedido de revisão). */
    antifraud?: boolean;
    /** §3.10 — programa de afiliados (portal e painel). O sistema calcula, não paga. */
    affiliates?: boolean;
    /** §3.6 — material de longo prazo (estado técnico); nunca muda o perfil anunciado. */
    pades_ltv?: boolean;
    /** §3.6 — anúncio de perfil acima de B-B; só depois do checklist (hoje sempre `false`). */
    pades_ltv_advertise?: boolean;
    /** Fase 3 §3.3 — vídeo curto no aceite (captura, não verificação; global E plano). */
    identity_video?: boolean;
    /** Fase 4 §4.1 — verificação facial com documento por provedor externo (global E plano; exige `identity_capture`). */
    identity_verification?: boolean;
    /** Fase 3 §3.1 — geração documental em lote a partir de modelo + planilha (global E plano). */
    bulk_generation?: boolean;
    /** Fase 3 §3.2 — "Detectar campos" por âncoras (sugestões revisadas no editor; global E plano). */
    field_anchors?: boolean;
    /** Fase 3 §3.2 — OCR de páginas escaneadas (exige `field_anchors` e o Tesseract no servidor). */
    ocr?: boolean;
    /** Fase 3 §3.3 — página pública e e-mails ao participante em pt_BR, en e es (global E plano). */
    multilingual?: boolean;
    /** Fase 3 §3.3 — etapas condicionais (motor declarativo fechado; global E plano). */
    conditional_steps?: boolean;
    /** Fase 3 §3.3 — delegação auditada pelo participante (política do remetente; global E plano). */
    delegation?: boolean;
    /** Fase 3 §3.9 — importar arquivo do Google Drive/Dropbox no wizard (global E plano; classe B). */
    cloud_import?: boolean;
    /** Fase 3 §3.9 — app HubSpot: conexão por organização e ação de workflow (global E plano; classe B). */
    hubspot?: boolean;
    /** Fase 3 §3.9 — login corporativo por OIDC (global E plano; sem organização, só o global; classe B). */
    sso_oidc?: boolean;
    /** Fase 3 §3.9 — login corporativo por SAML 2.0 (global E plano; sem organização, só o global; classe B). */
    sso_saml?: boolean;
    /** Fase 3 §3.9 — assinatura embutida por iframe + embed.js (global E plano; G-EMBED). */
    embedded_signing?: boolean;
}

export interface SharedProps {
    name: string;
    auth: { user: AuthUser | null };
    organization: CurrentOrganization | null;
    organizations: OrganizationSummary[];
    counts: {
        pending_envelopes: number;
        unread_notifications: number;
    };
    flash: Flash;
    features: Features;
    errors: Record<string, string>;
    sidebarOpen: boolean;
    /** Fase 3 §3.7 — só com a conta em observação ou com envio suspenso (sem pontuação). */
    risk?: {
        status: 'watch' | 'restricted';
        status_label: string;
        appeal_url: string;
    } | null;
}

// ---------------------------------------------------------------------------
// Paginação — ROUTES §0.4
// ---------------------------------------------------------------------------

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface PaginatedMeta {
    current_page: number;
    from: number | null;
    to: number | null;
    last_page: number;
    per_page: number;
    total: number;
    path: string;
    links: PaginationLink[];
}

export interface Paginated<T> {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: PaginatedMeta;
}

// ---------------------------------------------------------------------------
// Layout / UI
// ---------------------------------------------------------------------------

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};
