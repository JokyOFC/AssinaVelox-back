/**
 * Tipos das telas de API e integrações (Fase 2 §2.15–§2.17).
 * Contratos: docs/fase-2/api-v1.md §14 (chaves e logs) e docs/fase-2/webhooks.md §9.
 */

export type IntegrationsTab = 'docs' | 'keys' | 'webhooks' | 'logs';

export interface IntegrationsNavigation {
    docs: boolean;
    keys: boolean;
    webhooks: boolean;
    logs: boolean;
    rest_hooks: boolean;
}

export interface AbilityOption {
    value: string;
    label: string;
    description: string;
    grantable?: boolean;
}

export type ApiTokenState = 'active' | 'expired' | 'revoked';

export interface ApiTokenRow {
    id: string;
    name: string;
    prefix: string | null;
    abilities: { value: string; label: string }[];
    state: ApiTokenState;
    created_by: string | null;
    created_at: string | null;
    last_used_at: string | null;
    last_used_ip: string | null;
    expires_at: string | null;
    revoked_at: string | null;
    subscriptions: number;
}

export interface RevealedToken {
    id: string;
    name: string;
    token: string;
}

export interface ApiRequestLogRow {
    id: string;
    method: string;
    route: string | null;
    path: string | null;
    status: number;
    duration_ms: number;
    correlation_id: string | null;
    idempotent_replay: boolean;
    token: { id: string; name: string } | null;
    occurred_at: string | null;
}

export interface EventOption {
    value: string;
    label: string;
    description: string;
}

export interface WebhookLimits {
    max_endpoints: number;
    max_attempts: number;
    backoff_seconds: number[];
    pause_after_consecutive_failures: number;
    signature_tolerance_seconds: number;
    secret_rotation_overlap_hours: number;
    max_rotation_overlap_hours: number;
    timeout_seconds: number;
    retention_days: number;
}

export interface WebhookEndpointRow {
    id: string;
    url: string;
    host: string;
    description: string | null;
    events: string[];
    all_events: boolean;
    status: 'active' | 'paused';
    status_label: string;
    paused_at: string | null;
    paused_reason: string | null;
    paused_reason_label: string | null;
    consecutive_failures: number;
    last_success_at: string | null;
    last_failure_at: string | null;
    secret_hint: string;
    secret_rotated_at: string | null;
    previous_secret_expires_at: string | null;
    /** WebhookEndpoint::SOURCE_* (tela, API ou REST Hook). */
    source: 'web' | 'api' | 'rest_hook';
    created_by: string | null;
    created_at: string | null;
}

export interface RevealedSecret {
    endpoint: string;
    secret: string;
}

export interface WebhookDeliveryRow {
    id: string;
    event_id: string;
    event_type: string;
    event_label: string;
    status: string;
    status_label: string;
    is_test: boolean;
    attempts: number;
    max_attempts: number;
    last_response_code: number | null;
    last_duration_ms: number | null;
    last_error: string | null;
    last_error_label: string | null;
    next_retry_at: string | null;
    last_attempt_at: string | null;
    delivered_at: string | null;
    created_at: string | null;
    envelope: { id: string; code: string } | null;
    can_resend: boolean;
}

export interface WebhookAttempt {
    attempt?: number;
    trigger?: string;
    outcome?: string;
    outcome_label?: string | null;
    response_code?: number | null;
    duration_ms?: number | null;
    error?: string | null;
    error_label?: string | null;
    response_excerpt?: string | null;
    remote_ip?: string | null;
    attempted_at?: string | null;
}

export interface WebhookDeliveryDetail extends WebhookDeliveryRow {
    payload: unknown;
    payload_hidden: boolean;
    history: WebhookAttempt[];
    headers: { delivery_id: string; timestamp: string; signature: string };
}

export interface ApiEndpointRow {
    method: string;
    path: string;
    name: string;
    group: string;
    description: string;
    abilities: string[];
    idempotency: 'required' | 'optional' | null;
}

export interface ProblemRow {
    status: number;
    type: string;
    description: string;
}

export interface ApiLimits {
    per_token_per_minute: number;
    per_organization_per_minute: number;
    idempotency_ttl_hours: number;
    page_size_default: number;
    page_size_max: number;
    max_upload_mb: number;
    token_max_expiration_days: number;
    request_log_retention_days: number;
    rest_hooks_per_token: number;
}

export interface SignatureInfo {
    delivery_id: string;
    timestamp: string;
    signature: string;
    tolerance_seconds: number;
}
