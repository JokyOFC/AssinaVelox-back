import type {
    EnvelopeStatus,
    RecipientStatus,
    SigningOrder,
} from '@/types/enums';

/*
 * Contrato de `GET envelopes.flow.show` (Fase 3 §3.3, F-FLOW —
 * docs/fase-3/etapas-e-delegacao.md §5). Espelho de App\Services\Envelopes\Steps\FlowState.
 * O esquema da condição é FECHADO: só estes dois tipos de regra existem.
 */

export type FlowStepStatus = 'pending' | 'active' | 'skipped';

export type FlowDecision = 'approved' | 'refused';

export type FlowOperator = 'equals' | 'not_equals' | 'contains';

export type FlowRule =
    | {
          type: 'approver_decision';
          recipient: string;
          equals: FlowDecision;
      }
    | {
          type: 'field_value';
          field: string;
          operator: FlowOperator;
          value: string;
      };

export interface FlowCondition {
    match: 'all' | 'any';
    rules: FlowRule[];
}

export interface FlowParticipant {
    id: string;
    name: string;
    email: string;
    role: 'signer' | 'witness' | 'approver' | 'viewer';
    role_label: string;
    step: number | null;
    order: number;
    status: RecipientStatus;
    status_label: string;
    status_reason: string | null;
    delegated_from: string | null;
}

export interface FlowStepItem {
    id: string;
    index: number;
    name: string | null;
    condition: FlowCondition | null;
    status: FlowStepStatus;
    status_label: string;
    evaluated_at: string | null;
    summary: string[];
}

export interface FlowField {
    id: string;
    label: string;
    type: 'checkbox' | 'text';
    recipient_id: string | null;
}

export interface FlowSteps {
    enabled: boolean;
    can_edit: boolean;
    items: FlowStepItem[];
    fields: FlowField[];
    limits: {
        max_steps: number;
        max_rules: number;
        max_literal_length: number;
    };
}

export type FlowDelegationStatus =
    | 'pending'
    | 'effective'
    | 'rejected'
    | 'void';

export interface FlowDelegationRequest {
    id: string;
    status: FlowDelegationStatus;
    status_label: string;
    from: { id: string | null; name: string };
    to: { id: string | null; name: string; email: string };
    reason: string;
    chain_depth: number;
    requested_at: string;
    delegated_at: string | null;
    approved_by_sender_at: string | null;
    rejected_at: string | null;
    decision_note: string | null;
    can_decide: boolean;
}

export interface FlowDelegation {
    can_edit: boolean;
    can_decide: boolean;
    policy: {
        allow: boolean;
        requires_confirmation: boolean;
        personal: string[];
    };
    limits: {
        max_chain_depth: number;
        max_requests_per_recipient: number;
        max_per_organization_per_day: number;
    };
    requests: FlowDelegationRequest[];
}

export interface EnvelopeFlowState {
    envelope: {
        id: string;
        status: EnvelopeStatus;
        editable: boolean;
        signing_order: SigningOrder;
        current_order: number;
    };
    features: { conditional_steps: boolean; delegation: boolean };
    participants: FlowParticipant[];
    steps: FlowSteps | null;
    delegation: FlowDelegation | null;
}

export const decisionLabels: Record<FlowDecision, string> = {
    approved: 'aprovou',
    refused: 'recusou',
};

export const operatorLabels: Record<FlowOperator, string> = {
    equals: 'é igual a',
    not_equals: 'é diferente de',
    contains: 'contém',
};

export const stepStatusTones: Record<
    FlowStepStatus,
    'neutral' | 'success' | 'draft'
> = {
    pending: 'draft',
    active: 'success',
    skipped: 'neutral',
};

export const delegationStatusTones: Record<
    FlowDelegationStatus,
    'warning' | 'success' | 'danger' | 'neutral'
> = {
    pending: 'warning',
    effective: 'success',
    rejected: 'danger',
    void: 'neutral',
};
