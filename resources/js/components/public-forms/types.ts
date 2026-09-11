import type {
    VariableOptions,
    VariableType,
} from '@/components/templates/types';
import type { ParticipantRole } from '@/types/enums';

/**
 * Tipos das telas do formulário público (Fase 2 §2.2). Contrato de props em
 * docs/fase-2/formulario-publico.md §8 — espelha
 * `App\Services\PublicForms\PublicFormPresenter`.
 */

export type PublicFormStatus = 'draft' | 'active' | 'paused' | 'revoked';

export type PublicFormDestination = 'auto_send' | 'review';

export type SubmissionPeriod = 'day' | 'week' | 'month';

export type SubmissionStatus =
    | 'pending_confirmation'
    | 'processing'
    | 'pending_review'
    | 'sent'
    | 'rejected'
    | 'failed';

export interface FormVariable {
    key: string;
    label: string;
    type: VariableType;
    required: boolean;
    help_text: string | null;
    default_value: string | null;
    options: VariableOptions;
}

export interface FormRole {
    id: string;
    name: string;
    participant_role: ParticipantRole;
    participant_role_label: string;
}

export interface FormIssue {
    code: string;
    message: string;
}

export interface PublicFormRow {
    id: string;
    title: string;
    template: { id: string; name: string };
    status: PublicFormStatus;
    status_label: string;
    destination: PublicFormDestination;
    destination_label: string;
    public_url: string | null;
    expires_at: string | null;
    expired: boolean;
    updated_at: string | null;
    pending_review_count: number;
    sent_count: number;
    issues_count: number;
}

export interface SubmissionRow {
    id: string;
    form: { id: string; title: string };
    status: SubmissionStatus;
    status_label: string;
    failure_reason: string | null;
    failure_message: string | null;
    confirmed_at: string | null;
    reviewed_at: string | null;
    filler: { name: string; email: string } | null;
    envelope: {
        id: string;
        title: string;
        status: string;
        status_label: string;
        draft: boolean;
    } | null;
}

export interface TemplateOption {
    id: string;
    name: string;
    source_label: string;
    roles_count: number;
    variables_count: number;
}

export interface PrivacyNoticeContent {
    version: string;
    summary: string;
    sections: { title: string; body: string }[];
}

export interface FixedParticipant {
    name: string;
    email: string;
}

export interface PublicFormDetail {
    id: string;
    title: string;
    instructions: string | null;
    status: PublicFormStatus;
    status_label: string;
    destination: PublicFormDestination;
    public_url: string | null;
    expires_at: string | null;
    published_at: string | null;
    envelope_title: string | null;
    submissions_limit: number;
    submissions_period: SubmissionPeriod;
    public_variables: string[];
    fixed_values: Record<string, string>;
    filler_role: string | null;
    fixed_participants: Record<string, FixedParticipant>;
    responsible: string | null;
    pending_confirmation_count: number;
}

export interface DestinationOption {
    value: PublicFormDestination;
    label: string;
    description: string;
    available: boolean;
    reason: string | null;
}
