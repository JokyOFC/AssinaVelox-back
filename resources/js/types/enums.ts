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
// ROUTES §6.2: o badge dos três primeiros é sempre "Pendente" (âmbar); o texto abaixo é a NOTA.
//   pending  = criado; ainda não notificado (envelope não enviado OU aguarda a vez no sequencial) → nota "Aguarda a vez" quando envelope in_progress
//   notified = convite despachado (não implica entrega) → nota "Enviado · não visualizou"
//   viewed   = link aberto (abertura detectada, não prova leitura) → nota "Visualizou em {dt}"

export type SigningOrder = 'sequential' | 'parallel';

/**
 * Papel de domínio do participante (`App\Enums\RecipientRole`, Fase 2 §2.4).
 * Não confundir com `role` do destinatário, que é o rótulo livre ("Locatária").
 *
 *   signer   = aceite eletrônico com representação visual
 *   witness  = aceite eletrônico como testemunha (declaração própria)
 *   approver = aprovação eletrônica, SEM representação visual de assinatura
 *   viewer   = só recebe o documento e a cópia final; não registra aceite
 */
export type ParticipantRole = 'signer' | 'witness' | 'approver' | 'viewer';

/** O que um aceite registrou (`App\Enums\AcceptanceAction`, Fase 2 §2.4). */
export type AcceptanceAction = 'sign' | 'witness' | 'approve';

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
    | 'checkbox'
    /** Fase 2 §2.8 (C-BRAND): carimbo visual da organização — representação visual, não prova. */
    | 'stamp';

/**
 * Fase 2 §2.11 (C-ID): campo CPF digitado pelo participante, conferido pelos dígitos.
 *
 * Fica fora de `FieldType` só porque `components/envelopes/field-types.ts` (área C-BRAND)
 * indexa tabelas por `FieldType` e ainda não tem a entrada `cpf`. `SigningFieldType` é o
 * espelho completo de `App\Enums\FieldType`; quando `field-types.ts` ganhar `cpf`, os dois
 * podem voltar a ser um tipo só.
 */
export type IdentityFieldType = 'cpf';

/** Espelho completo de `App\Enums\FieldType` (Fase 1 + `stamp` + `cpf`). */
export type SigningFieldType = FieldType | IdentityFieldType;

/** Representação visual da assinatura. */
export type SignatureKind = 'drawn' | 'typed' | 'uploaded';

/**
 * Canal do código (`App\Enums\AuthMethod`). SMS e WhatsApp: Fase 2 §2.9, flag
 * `sms_whatsapp`. Cada método prova a posse do canal, não a identidade.
 */
export type AuthMethod = 'email_otp' | 'sms_otp' | 'whatsapp_otp';

/**
 * Valores de `auth_methods` da página pública e das evidências: o método do código e,
 * quando o remetente definiu um PIN, `sender_pin` (segredo compartilhado, pedido depois do
 * código e nunca no lugar dele).
 */
export type SignerAuthMethod = AuthMethod | 'sender_pin';

/** Canal do convite. `email` sempre sai; `sms`/`whatsapp` mandam também um aviso com o link. */
export type DeliveryChannel = 'email' | 'sms' | 'whatsapp';

/** Fase 2 §2.10 (C-ID): o que a captura simples fotografa. Nenhum tipo verifica nada. */
export type CaptureKind = 'selfie' | 'document_front' | 'document_back';

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

/**
 * verification_records.signature_status
 *
 * - `company_a1`: só a operadora (certificado de titularidade da operadora);
 * - `participants_a1` (Fase 2 §2.12): participantes com o PRÓPRIO certificado A1, sem a
 *   operadora;
 * - `mixed` (Fase 2 §2.12): participantes com o próprio certificado e, por último, a
 *   operadora.
 *
 * Nenhum desses valores substitui o aceite eletrônico, que existe para todos.
 */
export type SignatureStatus =
    | 'none'
    | 'company_a1'
    | 'participants_a1'
    | 'mixed'
    /**
     * Fase 3 §3.4 (espelho de `App\Enums\SignatureStatus`): participante com certificado A3
     * por componente local REAL — nunca produzido pelo simulador; hoje não é produzido.
     */
    | 'participant_a3'
    /** Fase 3 §3.4: participante por componente externo, origem em token não comprovada. */
    | 'participant_external'
    /**
     * Fase 3 §3.5: documento devolvido pelo portal gov.br com a cadeia validada até a âncora
     * gov.br fixada — "Assinatura gov.br (avançada)", nunca "qualificada" nem ICP-Brasil.
     */
    | 'participant_govbr'
    /** Fase 3 §3.5: documento devolvido com assinatura íntegra, cadeia NÃO verificada (nunca "gov.br"). */
    | 'participant_external_unverified';

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
    | 'document.presented'
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
    | 'plan.consumption_released'
    // Fase 2 §2.3/§2.4 (docs/fase-2/multi-documento-e-papeis.md §10)
    | 'documents.reordered'
    | 'approval.recorded'
    | 'document.finalized'
    // Fase 2 §2.5 (docs/fase-2/lembretes-e-agendamento.md §6)
    | 'envelope.scheduled'
    | 'envelope.schedule_canceled'
    | 'reminder.sent'
    | 'reminder.skipped'
    // Fase 2 §2.14 (docs/fase-2/permissoes-e-times.md)
    | 'role.created'
    | 'role.updated'
    | 'role.deleted'
    | 'membership.role_changed'
    | 'team.created'
    | 'team.updated'
    | 'team.deleted'
    | 'folder_access.updated'
    // Fase 2 §2.9 (docs/fase-2/canais-e-pin.md §11)
    | 'challenge.pin_verified'
    | 'challenge.pin_failed'
    | 'recipient.pin_updated'
    | 'sender_domain.created'
    | 'sender_domain.verified'
    | 'sender_domain.failed'
    | 'sender_domain.deleted'
    // Fase 2 §2.10/§2.11 (docs/fase-2/identidade.md)
    | 'cpf_lookup.performed'
    | 'identity_capture.requirement_updated'
    | 'identity_capture.recorded'
    | 'identity_capture.purged'
    // Fase 2 §2.12 (docs/fase-2/a1-do-participante.md §8)
    | 'participant_certificate.requested'
    | 'participant_certificate.withdrawn'
    | 'participant_certificate.submitted'
    | 'participant_certificate.rejected'
    | 'participant_signature.applied'
    | 'participant_signature.failed'
    | 'participant_signature.expired'
    | 'envelope.awaiting_participant_signatures';

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
