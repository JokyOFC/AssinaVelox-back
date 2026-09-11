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

    // Fase 2, onda A — múltiplos documentos e papéis (docs/fase-2/multi-documento-e-papeis.md).
    case DocumentsReordered = 'documents.reordered';
    case ApprovalRecorded = 'approval.recorded';
    case DocumentFinalized = 'document.finalized';

    // Fase 2, onda A — envio agendado e lembretes automáticos (docs/fase-2/lembretes-e-agendamento.md).
    case EnvelopeScheduled = 'envelope.scheduled';
    case EnvelopeScheduleCanceled = 'envelope.schedule_canceled';
    case ReminderSent = 'reminder.sent';
    case ReminderSkipped = 'reminder.skipped';

    // Fase 2, onda A — funções, times e acesso por pasta (docs/fase-2/permissoes-e-times.md).
    case RoleCreated = 'role.created';
    case RoleUpdated = 'role.updated';
    case RoleDeleted = 'role.deleted';
    case MembershipRoleChanged = 'membership.role_changed';
    case TeamCreated = 'team.created';
    case TeamUpdated = 'team.updated';
    case TeamDeleted = 'team.deleted';
    case FolderAccessUpdated = 'folder_access.updated';

    // Fase 2, onda A — etiquetas, relatórios e "acessar como" (docs/fase-2/tags-relatorios-e-logs.md).
    // Todos são eventos da ORGANIZAÇÃO (`envelope_id` nulo): a trilha do envelope e a página
    // de evidências não mudam.
    case TagCreated = 'tag.created';
    case TagUpdated = 'tag.updated';
    case TagDeleted = 'tag.deleted';
    case TagsApplied = 'tags.applied';
    case TagsRemoved = 'tags.removed';
    case ReportExported = 'report.exported';
    case ImpersonationStarted = 'impersonation.started';
    case ImpersonationEnded = 'impersonation.ended';
    case ImpersonationPageViewed = 'impersonation.page_viewed';

    // Fase 2, onda A — modelos com variáveis tipadas (docs/fase-2/modelos.md). Eventos da
    // ORGANIZAÇÃO (`envelope_id` nulo), exceto `template.used`, gravado no envelope gerado.
    case TemplateCreated = 'template.created';
    case TemplateVersionCreated = 'template.version_created';
    case TemplateUpdated = 'template.updated';
    case TemplateDuplicated = 'template.duplicated';
    case TemplateArchived = 'template.archived';
    case TemplateRestored = 'template.restored';
    case TemplateUsed = 'template.used';

    public function label(): string
    {
        return match ($this) {
            self::TemplateCreated => 'Modelo criado',
            self::TemplateVersionCreated => 'Nova versão do modelo',
            self::TemplateUpdated => 'Dados do modelo alterados',
            self::TemplateDuplicated => 'Modelo duplicado',
            self::TemplateArchived => 'Modelo arquivado',
            self::TemplateRestored => 'Modelo restaurado',
            self::TemplateUsed => 'Documento gerado a partir de modelo',
            self::TagCreated => 'Etiqueta criada',
            self::TagUpdated => 'Etiqueta alterada',
            self::TagDeleted => 'Etiqueta excluída',
            self::TagsApplied => 'Etiqueta aplicada a documentos',
            self::TagsRemoved => 'Etiqueta removida de documentos',
            self::ReportExported => 'Relatório exportado',
            self::ImpersonationStarted => 'Acesso de suporte iniciado',
            self::ImpersonationEnded => 'Acesso de suporte encerrado',
            self::ImpersonationPageViewed => 'Página visitada pelo suporte',
            self::RoleCreated => 'Função criada',
            self::RoleUpdated => 'Função alterada',
            self::RoleDeleted => 'Função excluída',
            self::MembershipRoleChanged => 'Função de usuário alterada',
            self::TeamCreated => 'Time criado',
            self::TeamUpdated => 'Time alterado',
            self::TeamDeleted => 'Time excluído',
            self::FolderAccessUpdated => 'Acesso a pastas alterado',
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
            self::DocumentsReordered => 'Ordem dos arquivos alterada',
            self::ApprovalRecorded => 'Aprovação registrada',
            self::DocumentFinalized => 'Arquivo final gerado',
            self::EnvelopeScheduled => 'Envio agendado',
            self::EnvelopeScheduleCanceled => 'Agendamento de envio cancelado',
            self::ReminderSent => 'Lembrete automático enviado',
            self::ReminderSkipped => 'Lembrete automático não enviado',
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
            self::ApprovalRecorded,
            self::DocumentFinalized,
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
            self::SubscriptionExpired,
            self::ImpersonationStarted => 'warn',

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
