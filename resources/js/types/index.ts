import type { MembershipRole, PlanCode, SubscriptionStatus } from './enums';

export type * from './enums';
export type * from './models';
export type * from './navigation';

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
