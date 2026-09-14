/**
 * Tipos compartilhados pelas telas do programa de afiliados (Fase 3 §3.10).
 */

export type AffiliateStatus = 'pending' | 'approved' | 'rejected' | 'suspended';
export type ReferralStatus = 'active' | 'held' | 'rejected';
export type CommissionStatus = 'pending' | 'approved' | 'paid' | 'reversed';
export type BatchStatus = 'draft' | 'paid' | 'canceled';

export interface MaskedPayout {
    method: string;
    pix_key_type: string;
    pix_key_type_label: string;
    pix_key: string;
    holder_name: string;
    holder_tax_id: string;
}

export interface ProgramSettings {
    terms_version: string;
    default_rate_bp: number;
    max_rate_bp: number;
    attribution_window_days: number;
    attribution_model: 'first_touch' | 'last_touch';
    approval_hold_days: number;
    commission_months: number | null;
    min_payout_cents: number;
    pix_key_types: { value: string; label: string }[];
}

export interface AffiliateSummary {
    id: string;
    status: AffiliateStatus;
    status_label: string;
    status_reason: string | null;
    code: string | null;
    link: string | null;
    commission_rate_bp: number;
    payout: MaskedPayout | null;
    terms_version: string;
    applied_at: string;
    approved_at: string | null;
    suspended_at: string | null;
}

export interface CurrencyTotals {
    currency: string;
    pending_cents: number;
    approved_cents: number;
    paid_cents: number;
    reversed_cents: number;
}

export interface ReferralRow {
    id: string;
    organization_name: string;
    organization_id?: string | null;
    status: ReferralStatus;
    status_label: string;
    reasons: { code: string; label: string }[];
    attributed_at: string;
    expires_at: string | null;
    review_requested_at: string | null;
    reviewed_at: string | null;
    review_note: string | null;
    can_request_review: boolean;
    affiliate?: { id: string; code: string | null; name: string };
}

export interface CommissionRow {
    id: string;
    kind: 'commission' | 'reversal' | 'adjustment';
    kind_label: string;
    organization_name: string;
    base_amount_cents: number;
    rate_bp: number;
    amount_cents: number;
    currency: string;
    status: CommissionStatus;
    status_label: string;
    reason_label: string | null;
    created_at: string | null;
    available_at: string | null;
    approved_at: string | null;
    paid_at: string | null;
    payout_batch_id: string | null;
}

export interface TrailEntry {
    id: string;
    action: string;
    label: string;
    actor: string | null;
    payload: Record<string, unknown>;
    occurred_at: string | null;
}

export interface PayoutFormData {
    pix_key_type: string;
    pix_key: string;
    holder_name: string;
    holder_tax_id: string;
}
