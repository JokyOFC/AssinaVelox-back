import type { StepperStep } from '@/components/stepper';
import type { AcceptanceAction, FieldType } from '@/types';
import type { IdentityCaptureStep, SignerAuth } from '@/types/models';

/**
 * Tipos compartilhados do presencial em tablet e da assinatura em lote
 * (docs/fase-2/presencial-e-lote.md §6). As props vêm de
 * `InPersonKioskProps`, `BatchPageProps` e `ParticipantSigningProps`.
 */

export interface PresenceSender {
    organization_name: string;
    organization_initials: string;
    logo_url: string | null;
    user_name: string;
}

export interface PresenceLegal {
    terms_url: string;
    privacy_url: string;
}

export interface PresenceLimits {
    otp_length?: number;
    otp_ttl_minutes?: number;
    otp_max_attempts?: number;
    max_text_length?: number;
    signature_image_max_kb?: number;
    typed_name?: { min: number; max: number };
}

export interface PresencePrivacy {
    version: string;
    summary: string;
    notice: string;
}

export interface PresenceOtp {
    sent_at: string | null;
    expires_at: string | null;
    resend_available_at: string | null;
    attempts_left: number;
}

export interface PresenceAction {
    type: AcceptanceAction | 'view';
    label: string;
    button_label: string | null;
    requires_signature: boolean;
    requires_consent: boolean;
}

export interface PresenceDocument {
    id: string;
    position: number;
    name: string | null;
    pages: number;
    /** Rota autorizada do PDF (dispositivo presencial ou item do lote). */
    pdf_url: string;
    page_sizes: {
        page: number;
        width_pt: number;
        height_pt: number;
        rotation: number;
    }[];
    sha256: string | null;
    presented: boolean;
}

export interface PresenceField {
    id: string;
    type: FieldType;
    page: number | 'all';
    x: number;
    y: number;
    w: number;
    h: number;
    required: boolean;
    label: string | null;
    placeholder: string | null;
    prefill: string | null;
    document_id?: string | null;
}

export interface PresenceOtherField {
    recipient_name: string;
    role: string | null;
    type: FieldType;
    page: number;
    x: number;
    y: number;
    w: number;
    h: number;
    signed: boolean;
    document_id?: string | null;
}

/** Etapa "revisar e registrar o aceite" (`ParticipantSigningProps::build`). */
export interface PresenceSigning {
    envelope: {
        display_code: string;
        title: string;
        pages: number;
        expires_at: string | null;
        message: string | null;
    };
    action: PresenceAction;
    documents: PresenceDocument[];
    my_fields: PresenceField[];
    other_fields: PresenceOtherField[];
    consent: {
        version: string;
        checkbox_label: string;
        statement: string;
        completion_notice: string;
    };
    authorization: { token: string; expires_at: string | null };
    signature_options: {
        draw: boolean;
        type: boolean;
        upload: boolean;
        fonts: string[];
        certificate?: boolean;
    };
}

// -- Presencial ------------------------------------------------------------------

export type KioskScreen = 'unavailable' | 'none' | 'queue' | 'participant';

export interface KioskQueueItem {
    id: string;
    name: string;
    first_name: string;
    role_label: string | null;
    participant_role: string;
    participant_role_label: string;
    order: number;
    state: 'available' | 'done' | 'waiting' | 'closed';
    state_label: string;
    action_label: string | null;
    accepted_here: boolean;
}

export interface KioskParticipant {
    id: string;
    name: string;
    first_name: string;
    role_label: string | null;
    participant_role: string;
    participant_role_label: string;
    step: 'identify' | 'pin' | 'sign';
    action: PresenceAction;
    auth: SignerAuth;
    otp: PresenceOtp | null;
    privacy: PresencePrivacy;
    signing: PresenceSigning | null;
    identity_capture: IdentityCaptureStep | null;
}

export interface KioskProps {
    screen: KioskScreen;
    sender: PresenceSender;
    kiosk: {
        id: string;
        device_label: string;
        host_name: string | null;
        started_at: string;
        last_activity_at: string;
        idle_minutes: number;
        expires_at: string;
        envelope: {
            title: string;
            display_code: string;
            signing_order: string;
            expires_at: string | null;
        };
    } | null;
    queue: KioskQueueItem[];
    participant: KioskParticipant | null;
    done: boolean;
    ended: { reason: string; message: string } | null;
    legal: PresenceLegal;
    limits: PresenceLimits;
}

// -- Lote ------------------------------------------------------------------------

export type BatchScreen =
    | 'unavailable'
    | 'none'
    | 'invalid'
    | 'identify'
    | 'list'
    | 'item';

export interface BatchItem {
    id: string;
    position: number;
    title: string | null;
    display_code: string | null;
    expires_at: string | null;
    action_label: string | null;
    action_type: AcceptanceAction | null;
    state:
        | 'available'
        | 'done'
        | 'individual'
        | 'waiting'
        | 'expired'
        | 'canceled'
        | 'refused'
        | 'closed';
    state_label: string;
    authorizable: boolean;
    reason: string | null;
    open: boolean;
    authorized_at: string | null;
    last_error_code: string | null;
}

export interface BatchProps {
    screen: BatchScreen;
    sender: PresenceSender;
    batch: {
        id: string;
        expires_at: string;
        items_count: number;
        session_expires_at: string | null;
    } | null;
    recipient: { first_name: string; email_masked: string } | null;
    otp: PresenceOtp | null;
    items: BatchItem[];
    current:
        | (PresenceSigning & { id: string; privacy: PresencePrivacy })
        | null;
    privacy: PresencePrivacy | null;
    legal: PresenceLegal;
    limits: PresenceLimits;
}

/** Props de layout aceitas pelas cascas (`SignerLayout`). */
export interface ShellLayoutProps {
    steps?: StepperStep[];
}
