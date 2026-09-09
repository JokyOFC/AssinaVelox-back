<?php

namespace App\Enums;

/**
 * Tipos de evento da trilha de auditoria (docs/design/RECONCILIACAO.md §3).
 */
enum AuditEventType: string
{
    case EnvelopeCreated = 'envelope.created';
    case EnvelopeUpdated = 'envelope.updated';
    case DocumentUploaded = 'document.uploaded';
    case DocumentConversionStarted = 'document.conversion_started';
    case DocumentConverted = 'document.converted';
    case DocumentProcessingFailed = 'document.processing_failed';
    case DocumentBlocked = 'document.blocked';
    case DocumentRemoved = 'document.removed';
    case FieldsUpdated = 'fields.updated';
    case RecipientsUpdated = 'recipients.updated';
    case EnvelopeSent = 'envelope.sent';
    case InvitationSent = 'invitation.sent';
    case InvitationResent = 'invitation.resent';
    case InvitationOpened = 'invitation.opened';
    case ChallengeSent = 'challenge.sent';
    case ChallengeVerified = 'challenge.verified';
    case ChallengeFailed = 'challenge.failed';
    case SessionStarted = 'session.started';
    case DocumentPresented = 'document.presented';
    case AcceptanceRecorded = 'acceptance.recorded';
    case RecipientRefused = 'recipient.refused';
    case EnvelopeRefused = 'envelope.refused';
    case EnvelopeExpired = 'envelope.expired';
    case EnvelopeCanceled = 'envelope.canceled';
    case EnvelopeFinalizing = 'envelope.finalizing';
    case EnvelopeConsolidated = 'envelope.consolidated';
    case EnvelopeEvidenceGenerated = 'envelope.evidence_generated';
    case EnvelopeSignedCompanyA1 = 'envelope.signed_company_a1';
    case EnvelopeCompleted = 'envelope.completed';
    case EnvelopeFinalizationFailed = 'envelope.finalization_failed';
    case EnvelopeDownloaded = 'envelope.downloaded';
    case EnvelopeMoved = 'envelope.moved';
    case EnvelopeDuplicated = 'envelope.duplicated';
    case PlanConsumptionReserved = 'plan.consumption_reserved';
    case PlanConsumptionCommitted = 'plan.consumption_committed';
    case PlanConsumptionReleased = 'plan.consumption_released';

    // Cobrança (incremento 5). Eventos da ORGANIZAÇÃO: `envelope_id` fica nulo. Não
    // constam da lista de RECONCILIACAO §3, que só enumerou o ciclo do envelope; foram
    // acrescentados aqui porque a ativação de plano, o cancelamento e a inadimplência
    // precisam de trilha auditável (ver docs/cobranca.md).
    case PaymentCreated = 'payment.created';
    case PaymentApproved = 'payment.approved';
    case PaymentFailed = 'payment.failed';
    case SubscriptionActivated = 'subscription.activated';
    case SubscriptionCanceled = 'subscription.canceled';
    case SubscriptionResumed = 'subscription.resumed';
    case SubscriptionPastDue = 'subscription.past_due';
    case SubscriptionExpired = 'subscription.expired';
    case SubscriptionRenewed = 'subscription.renewed';

    public function label(): string
    {
        return match ($this) {
            self::EnvelopeCreated => 'Documento criado',
            self::EnvelopeUpdated => 'Documento atualizado',
            self::DocumentUploaded => 'Arquivo enviado',
            self::DocumentConversionStarted => 'Conversão iniciada',
            self::DocumentConverted => 'Arquivo convertido',
            self::DocumentProcessingFailed => 'Falha ao processar o arquivo',
            self::DocumentBlocked => 'Arquivo bloqueado',
            self::DocumentRemoved => 'Arquivo removido',
            self::FieldsUpdated => 'Campos atualizados',
            self::RecipientsUpdated => 'Signatários atualizados',
            self::EnvelopeSent => 'Documento enviado',
            self::InvitationSent => 'Convite enviado',
            self::InvitationResent => 'Convite reenviado',
            self::InvitationOpened => 'Convite aberto',
            self::ChallengeSent => 'Código de verificação enviado',
            self::ChallengeVerified => 'Identidade confirmada',
            self::ChallengeFailed => 'Código de verificação incorreto',
            self::SessionStarted => 'Sessão de assinatura iniciada',
            self::DocumentPresented => 'Documento apresentado ao signatário',
            self::AcceptanceRecorded => 'Aceite registrado',
            self::RecipientRefused => 'Signatário recusou',
            self::EnvelopeRefused => 'Documento recusado',
            self::EnvelopeExpired => 'Documento expirado',
            self::EnvelopeCanceled => 'Documento cancelado',
            self::EnvelopeFinalizing => 'Finalização iniciada',
            self::EnvelopeConsolidated => 'Documento consolidado',
            self::EnvelopeEvidenceGenerated => 'Página de evidências gerada',
            self::EnvelopeSignedCompanyA1 => 'Assinatura criptográfica da operadora aplicada',
            self::EnvelopeCompleted => 'Documento concluído',
            self::EnvelopeFinalizationFailed => 'Falha na finalização',
            self::EnvelopeDownloaded => 'Documento baixado',
            self::EnvelopeMoved => 'Documento movido',
            self::EnvelopeDuplicated => 'Documento duplicado',
            self::PlanConsumptionReserved => 'Consumo do plano reservado',
            self::PlanConsumptionCommitted => 'Consumo do plano confirmado',
            self::PlanConsumptionReleased => 'Consumo do plano liberado',
            self::PaymentCreated => 'Checkout iniciado',
            self::PaymentApproved => 'Pagamento aprovado',
            self::PaymentFailed => 'Pagamento não aprovado',
            self::SubscriptionActivated => 'Plano ativado',
            self::SubscriptionCanceled => 'Renovação cancelada',
            self::SubscriptionResumed => 'Renovação reativada',
            self::SubscriptionPastDue => 'Plano em atraso',
            self::SubscriptionExpired => 'Plano expirado',
            self::SubscriptionRenewed => 'Ciclo do plano renovado',
        };
    }

    /**
     * Tom do evento para a UI (ROUTES_AND_PAGES §6.6).
     *
     * @return 'ok'|'info'|'warn'
     */
    public function kind(): string
    {
        return match ($this) {
            self::AcceptanceRecorded,
            self::ChallengeVerified,
            self::EnvelopeCompleted,
            self::EnvelopeSignedCompanyA1,
            self::PaymentApproved,
            self::SubscriptionActivated,
            self::SubscriptionRenewed,
            self::SubscriptionResumed => 'ok',

            self::ChallengeFailed,
            self::DocumentProcessingFailed,
            self::DocumentBlocked,
            self::RecipientRefused,
            self::EnvelopeRefused,
            self::EnvelopeExpired,
            self::EnvelopeCanceled,
            self::EnvelopeFinalizationFailed,
            self::PaymentFailed,
            self::SubscriptionPastDue,
            self::SubscriptionExpired => 'warn',

            default => 'info',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
