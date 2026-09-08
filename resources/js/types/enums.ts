/**
 * Enums canônicos — espelho fiel de `App\Enums\*`.
 * Fonte: docs/design/RECONCILIACAO.md §2 (prevalece sobre os demais documentos).
 */

export type EnvelopeStatus =
    | 'draft'
    | 'preparing'
    | 'ready'
    | 'in_progress'
    | 'finalizing'
    | 'completed'
    | 'refused'
    | 'expired'
    | 'canceled';

export type RecipientStatus =
    | 'pending'
    | 'notified'
    | 'viewed'
    | 'signed'
    | 'refused'
    | 'expired'
    | 'canceled';
//   pending  = criado; ainda não notificado (envelope não enviado OU aguarda a vez no sequencial) → rótulo "Aguarda a vez" quando envelope in_progress
//   notified = convite despachado (não implica entrega) → rótulo "Enviado · não visualizou"
//   viewed   = link aberto (abertura detectada, não prova leitura) → "Visualizou em {dt}"

export type SigningOrder = 'sequential' | 'parallel';

export type DocumentSourceType = 'pdf' | 'docx' | 'image';

export type DocumentProcessingStatus =
    | 'uploaded'
    | 'converting'
    | 'ready'
    | 'failed'
    | 'blocked';
//   blocked = PDF protegido por senha, já assinado digitalmente ou inválido para preparação; original preservado

export type DocumentVersionKind =
    | 'original'
    | 'converted'
    | 'consolidated'
    | 'evidence'
    | 'final';

export type FieldType =
    | 'signature'
    | 'initials'
    | 'name'
    | 'date'
    | 'text'
    | 'checkbox';

/** Representação visual da assinatura. */
export type SignatureKind = 'drawn' | 'typed' | 'uploaded';

/** Fase 2: sms_otp, whatsapp_otp */
export type AuthMethod = 'email_otp';

/** Fase 1 usa só email. */
export type DeliveryChannel = 'email' | 'sms' | 'whatsapp';

export type DeliveryStatus =
    | 'queued'
    | 'sent'
    | 'delivered'
    | 'failed'
    | 'bounced'
    | 'unknown';

export type SigningSessionStatus =
    | 'pending_auth'
    | 'authenticated'
    | 'consumed'
    | 'expired'
    | 'revoked';

export type MembershipRole = 'owner' | 'admin' | 'member';

export type MembershipStatus = 'active' | 'suspended';

/** Derivado de accepted_at/expires_at/revoked_at. */
export type InvitationStatus = 'pending' | 'accepted' | 'expired' | 'revoked';

/** Seeders; preços/limites são configuração, não oferta. */
export type PlanCode = 'free' | 'professional' | 'enterprise';

export type SubscriptionStatus =
    | 'pending'
    | 'trialing'
    | 'active'
    | 'past_due'
    | 'canceled'
    | 'expired';

/** Espelho fiel do Mercado Pago. */
export type PaymentStatus =
    | 'pending'
    | 'approved'
    | 'authorized'
    | 'in_process'
    | 'in_mediation'
    | 'rejected'
    | 'cancelled'
    | 'refunded'
    | 'charged_back';

/** Agrupamento só para UI. */
export type PaymentDisplayStatus =
    | 'paid'
    | 'pending'
    | 'failed'
    | 'refunded'
    | 'cancelled';

/** verification_records.signature_status */
export type SignatureStatus = 'none' | 'company_a1';

export type ActorType = 'user' | 'recipient' | 'system';

/** Tipos de evento da trilha de auditoria (RECONCILIACAO §3). */
export type AuditEventType =
    | 'envelope.created'
    | 'envelope.updated'
    | 'document.uploaded'
    | 'document.conversion_started'
    | 'document.converted'
    | 'document.processing_failed'
    | 'document.blocked'
    | 'document.removed'
    | 'fields.updated'
    | 'recipients.updated'
    | 'envelope.sent'
    | 'invitation.sent'
    | 'invitation.resent'
    | 'invitation.opened'
    | 'challenge.sent'
    | 'challenge.verified'
    | 'challenge.failed'
    | 'session.started'
    | 'acceptance.recorded'
    | 'recipient.refused'
    | 'envelope.refused'
    | 'envelope.expired'
    | 'envelope.canceled'
    | 'envelope.finalizing'
    | 'envelope.consolidated'
    | 'envelope.evidence_generated'
    | 'envelope.signed_company_a1'
    | 'envelope.completed'
    | 'envelope.finalization_failed'
    | 'envelope.downloaded'
    | 'envelope.moved'
    | 'envelope.duplicated'
    | 'plan.consumption_reserved'
    | 'plan.consumption_committed'
    | 'plan.consumption_released';

/** Cor/semântica de um evento na timeline (derivado no backend). */
export type AuditEventKind = 'ok' | 'info' | 'warn';

export type NotificationEvent =
    | 'recipient_signed'
    | 'envelope_completed'
    | 'recipient_refused'
    | 'envelope_expiring'
    | 'daily_digest'
    | 'invitation_accepted'
    | 'product_news';

/** "E-mail" | "No app" */
export type NotificationChannel = 'mail' | 'database';
