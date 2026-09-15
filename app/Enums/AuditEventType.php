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

    // Fase 2, onda B — identidade (C-ID, docs/fase-2/identidade.md). Payload minimizado:
    // nunca imagem, caminho de arquivo nem CPF completo (só mascarado ***.456.789-**).
    case CpfLookupPerformed = 'cpf_lookup.performed';
    case IdentityCaptureRequirementUpdated = 'identity_capture.requirement_updated';
    case IdentityCaptureRecorded = 'identity_capture.recorded';
    case IdentityCapturePurged = 'identity_capture.purged';

    // Fase 2, onda B — canais e PIN do remetente (C-CAN, docs/fase-2/canais-e-pin.md). Nenhum
    // payload carrega o PIN, o código, o telefone completo ou o token: só ULIDs, motivo e contagens.
    case ChallengePinVerified = 'challenge.pin_verified';
    case ChallengePinFailed = 'challenge.pin_failed';
    case RecipientPinUpdated = 'recipient.pin_updated';
    // Domínios de envio (C-CAN): eventos da ORGANIZAÇÃO (`envelope_id` nulo).
    case SenderDomainCreated = 'sender_domain.created';
    case SenderDomainVerified = 'sender_domain.verified';
    case SenderDomainFailed = 'sender_domain.failed';
    case SenderDomainDeleted = 'sender_domain.deleted';

    // Fase 2, onda B — presencial em tablet (C-PRES, docs/fase-2/presencial-e-lote.md §2).
    // Gravados no envelope. O anfitrião aparece como quem ABRIU a sessão e atestou a presença,
    // nunca como autor do aceite (o ator do aceite continua sendo o participante). Payload sem
    // token, segredo do dispositivo, código ou PIN.
    case InPersonSessionStarted = 'in_person.session_started';
    case InPersonParticipantStarted = 'in_person.participant_started';
    case InPersonAcceptanceRecorded = 'in_person.acceptance_recorded';
    case InPersonParticipantClosed = 'in_person.participant_closed';
    case InPersonSessionEnded = 'in_person.session_ended';

    // Fase 2, onda B — assinatura em lote (C-PRES, docs/fase-2/presencial-e-lote.md §3).
    // `batch.link_issued`/`batch.challenge_*` são eventos do LOTE (envelope nulo, exceto o
    // envelope de origem do link); `batch.item_*` ficam no envelope de cada item.
    case BatchLinkIssued = 'batch.link_issued';
    case BatchChallengeSent = 'batch.challenge_sent';
    case BatchChallengeVerified = 'batch.challenge_verified';
    case BatchChallengeFailed = 'batch.challenge_failed';
    case BatchItemOpened = 'batch.item_opened';
    case BatchItemAuthorized = 'batch.item_authorized';
    case BatchItemFailed = 'batch.item_failed';

    // Fase 2, onda B — formulário público (C-FORM, docs/fase-2/formulario-publico.md §9).
    // Gestão: eventos da ORGANIZAÇÃO (`envelope_id` nulo). Envios: gravados no envelope gerado.
    // Payload só com ULIDs, destino e contagens — nunca e-mail, nome, respostas ou tokens.
    case PublicFormCreated = 'public_form.created';
    case PublicFormUpdated = 'public_form.updated';
    case PublicFormActivated = 'public_form.activated';
    case PublicFormPaused = 'public_form.paused';
    case PublicFormRevoked = 'public_form.revoked';
    case PublicFormSubmissionConfirmed = 'public_form.submission_confirmed';
    case PublicFormSubmissionApproved = 'public_form.submission_approved';
    case PublicFormSubmissionRejected = 'public_form.submission_rejected';

    // Fase 2, onda C — assinatura com o certificado A1 do PRÓPRIO participante (K-A1,
    // docs/fase-2/a1-do-participante.md). Gravados no envelope. Payload só com ULIDs, códigos,
    // impressão digital e emissor do certificado — nunca PFX, senha, CPF completo ou caminho.
    case ParticipantCertificateRequested = 'participant_certificate.requested';
    case ParticipantCertificateWithdrawn = 'participant_certificate.withdrawn';
    case ParticipantCertificateSubmitted = 'participant_certificate.submitted';
    case ParticipantCertificateRejected = 'participant_certificate.rejected';
    case ParticipantSignatureApplied = 'participant_signature.applied';
    case ParticipantSignatureFailed = 'participant_signature.failed';
    case ParticipantSignatureExpired = 'participant_signature.expired';
    case EnvelopeAwaitingParticipantSignatures = 'envelope.awaiting_participant_signatures';

    // Fase 2, onda D — API REST v1 (D-API, docs/fase-2/api-v1.md §2). Eventos da ORGANIZAÇÃO
    // (`envelope_id` nulo). Payload só com o ULID, o nome, as abilities e a validade — nunca o
    // texto do token, o hash ou o prefixo.
    case ApiTokenCreated = 'api_token.created';
    case ApiTokenRevoked = 'api_token.revoked';

    // Fase 3 §3.3 (F-FLOW, docs/fase-3/etapas-e-delegacao.md §6) — etapas condicionais e
    // delegação auditada. Gravados no envelope. Payload só com ULIDs, a regra avaliada, os
    // valores comparados (dado do documento, nunca instrução), contagens e o e-mail MASCARADO
    // do delegado — nunca o texto do motivo (que fica em `delegations.reason`).
    case SigningStepsUpdated = 'signing_steps.updated';
    case EnvelopeStepStarted = 'envelope.step_started';
    case EnvelopeStepSkipped = 'envelope.step_skipped';
    case DelegationPolicyUpdated = 'delegation.policy_updated';
    case DelegationRequested = 'delegation.requested';
    case RecipientDelegated = 'recipient.delegated';
    case DelegationRejected = 'delegation.rejected';

    // Fase 3 §3.3 (F-VIDEO, docs/fase-3/captura-de-video.md §7) — vídeo curto no aceite.
    // Gravados no envelope. Payload só com ULIDs, SHA-256, contêiner, duração, tamanho, origem
    // DECLARADA pelo navegador e versão do consentimento — nunca o vídeo nem o caminho no disco.
    case IdentityVideoRequirementUpdated = 'identity_video.requirement_updated';
    case IdentityVideoRecorded = 'identity_video.recorded';
    case IdentityVideoAccessed = 'identity_video.accessed';

    // Fase 3 §3.3 (F-I18N, docs/fase-3/multilingue.md §8) — idioma do participante. Gravados no
    // envelope. Payload só com o ULID do participante e os códigos de idioma (lista fechada).
    // `recipient.locale_updated`: o remetente definiu o idioma dos e-mails e da página.
    // `recipient.display_locale_changed`: o participante trocou o idioma de EXIBIÇÃO da página
    // (vale para a sessão; o que o remetente registrou não muda).
    case RecipientLocaleUpdated = 'recipient.locale_updated';
    case RecipientDisplayLocaleChanged = 'recipient.display_locale_changed';

    // Fase 3 §3.3 (F-FLOW), revisão adversarial da onda F — pedido de delegação que perdeu o
    // objeto (coleta encerrada, participante respondeu, etapa não se aplicou) fica "sem efeito"
    // já na transição, não só quando alguém tenta confirmá-lo. Payload só com ULIDs e o código.
    case DelegationVoided = 'delegation.voided';

    // Fase 3 §3.9 (G-SSO, docs/fase-3/sso.md §9) — login corporativo por OIDC/SAML. Da
    // ORGANIZAÇÃO (`envelope_id` nulo). Payload só com ULIDs, protocolo, domínio (nunca e-mail
    // completo), códigos de motivo e booleanos — nunca token, assertion, claim bruta ou segredo.
    // O login por SSO autentica o USUÁRIO do painel, nunca o signatário de um envelope (T1).
    case SsoConnectionCreated = 'sso.connection_created';
    case SsoConnectionUpdated = 'sso.connection_updated';
    case SsoConnectionDeleted = 'sso.connection_deleted';
    case SsoConnectionTested = 'sso.connection_tested';
    case SsoDomainAdded = 'sso.domain_added';
    case SsoDomainVerified = 'sso.domain_verified';
    case SsoDomainRemoved = 'sso.domain_removed';
    case SsoLoginSucceeded = 'sso.login_succeeded';
    case SsoLoginFailed = 'sso.login_failed';
    case SsoUserProvisioned = 'sso.user_provisioned';
    case SsoIdentityLinked = 'sso.identity_linked';
    case SsoBreakGlassUsed = 'sso.break_glass_used';

    // Fase 3 §3.9 (G-EMBED, docs/fase-3/widget-embutido.md §7) — sessão de assinatura embutida.
    // Gravados no envelope, ligados ao participante. Payload só com o ULID da sessão, a origem
    // (site do cliente, não é dado pessoal), a validade e o motivo — nunca token ou URL.
    // `created`: a API v1 emitiu a URL de uso único (ator: quem criou o token).
    // `opened`: o widget trocou a URL pela sessão, dentro do site da origem (ator: participante).
    // `revoked`: a sessão foi encerrada antes do fim (API ou convite revogado).
    case EmbeddedSessionCreated = 'embedded_session.created';
    case EmbeddedSessionOpened = 'embedded_session.opened';
    case EmbeddedSessionRevoked = 'embedded_session.revoked';
    // Da ORGANIZAÇÃO (`envelope_id` nulo): quem mudou a lista de origens que podem hospedar o
    // widget. Payload: origens acrescentadas e removidas e o total.
    case EmbedOriginsUpdated = 'embed_origins.updated';

    // Fase 3 §3.9 (G-CONN, docs/fase-3/conectores.md §7) — importação da nuvem e app HubSpot.
    // `cloud_import.*`: no envelope; payload com ULIDs, provedor, id externo do arquivo, SHA-256,
    // tamanho, código de recusa e `simulated` — nunca token, link de download ou conteúdo.
    // `hubspot.connected|disconnected`: da ORGANIZAÇÃO (`envelope_id` nulo); portal e escopos.
    // `hubspot.action_received`: no envelope criado pela ação de workflow; portal, objeto e o
    // ULID da execução — nunca token nem assinatura.
    case CloudImportCompleted = 'cloud_import.completed';
    case CloudImportRejected = 'cloud_import.rejected';
    case HubSpotConnected = 'hubspot.connected';
    case HubSpotDisconnected = 'hubspot.disconnected';
    case HubSpotActionReceived = 'hubspot.action_received';

    public function label(): string
    {
        return match ($this) {
            self::CloudImportCompleted => 'Arquivo importado da nuvem',
            self::CloudImportRejected => 'Importação de arquivo da nuvem recusada',
            self::HubSpotConnected => 'Conta do HubSpot conectada',
            self::HubSpotDisconnected => 'Conta do HubSpot desconectada',
            self::HubSpotActionReceived => 'Documento criado por um workflow do HubSpot',
            self::EmbeddedSessionCreated => 'Acesso embutido ao documento criado pela API',
            self::EmbeddedSessionOpened => 'Participante abriu o documento no site integrado',
            self::EmbeddedSessionRevoked => 'Acesso embutido ao documento encerrado',
            self::EmbedOriginsUpdated => 'Origens permitidas do widget de assinatura alteradas',
            self::SsoConnectionCreated => 'Login corporativo (SSO) configurado',
            self::SsoConnectionUpdated => 'Login corporativo (SSO) alterado',
            self::SsoConnectionDeleted => 'Login corporativo (SSO) removido',
            self::SsoConnectionTested => 'Conexão do login corporativo testada',
            self::SsoDomainAdded => 'Domínio adicionado ao login corporativo',
            self::SsoDomainVerified => 'Domínio do login corporativo verificado',
            self::SsoDomainRemoved => 'Domínio removido do login corporativo',
            self::SsoLoginSucceeded => 'Entrada pelo login corporativo',
            self::SsoLoginFailed => 'Entrada pelo login corporativo recusada',
            self::SsoUserProvisioned => 'Usuário criado pelo login corporativo',
            self::SsoIdentityLinked => 'Conta ligada ao provedor de identidade',
            self::SsoBreakGlassUsed => 'Acesso de emergência por senha com SSO obrigatório',
            self::DelegationVoided => 'Pedido de delegação ficou sem efeito',
            self::RecipientLocaleUpdated => 'Idioma do participante definido por quem enviou',
            self::RecipientDisplayLocaleChanged => 'Participante trocou o idioma de exibição da página',
            self::ApiTokenCreated => 'Chave de API criada',
            self::ApiTokenRevoked => 'Chave de API revogada',
            self::IdentityVideoRequirementUpdated => 'Exigência de vídeo curto do participante alterada',
            self::IdentityVideoRecorded => 'Vídeo curto enviado pelo participante',
            self::IdentityVideoAccessed => 'Vídeo curto do participante reproduzido ou baixado',
            self::ParticipantCertificateRequested => 'Participante optou por assinar com o próprio certificado',
            self::ParticipantCertificateWithdrawn => 'Participante desistiu de assinar com o próprio certificado',
            self::ParticipantCertificateSubmitted => 'Certificado do participante conferido e autorizado para uso',
            self::ParticipantCertificateRejected => 'Certificado do participante recusado',
            self::ParticipantSignatureApplied => 'Assinatura com o certificado do participante aplicada',
            self::ParticipantSignatureFailed => 'Falha ao aplicar a assinatura com o certificado do participante',
            self::ParticipantSignatureExpired => 'Prazo para assinar com o próprio certificado encerrado',
            self::EnvelopeAwaitingParticipantSignatures => 'Aguardando assinaturas com certificado dos participantes',
            self::PublicFormCreated => 'Formulário público criado',
            self::PublicFormUpdated => 'Formulário público alterado',
            self::PublicFormActivated => 'Formulário público publicado',
            self::PublicFormPaused => 'Formulário público pausado',
            self::PublicFormRevoked => 'Formulário público revogado',
            self::PublicFormSubmissionConfirmed => 'Documento gerado por formulário público (e-mail confirmado)',
            self::PublicFormSubmissionApproved => 'Resposta de formulário público aprovada e enviada',
            self::PublicFormSubmissionRejected => 'Resposta de formulário público recusada',
            self::InPersonSessionStarted => 'Sessão presencial iniciada',
            self::InPersonParticipantStarted => 'Participante chamado na sessão presencial',
            self::InPersonAcceptanceRecorded => 'Aceite registrado presencialmente',
            self::InPersonParticipantClosed => 'Tela presencial bloqueada',
            self::InPersonSessionEnded => 'Sessão presencial encerrada',
            self::BatchLinkIssued => 'Link de assinatura em lote enviado',
            self::BatchChallengeSent => 'Código do lote enviado',
            self::BatchChallengeVerified => 'Código do lote confirmado',
            self::BatchChallengeFailed => 'Código do lote incorreto',
            self::BatchItemOpened => 'Documento aberto na assinatura em lote',
            self::BatchItemAuthorized => 'Aceite autorizado na assinatura em lote',
            self::BatchItemFailed => 'Autorização em lote não registrada',
            self::ChallengePinVerified => 'PIN do remetente confirmado',
            self::ChallengePinFailed => 'PIN do remetente incorreto',
            self::RecipientPinUpdated => 'PIN do participante alterado pelo remetente',
            self::SenderDomainCreated => 'Domínio de envio cadastrado',
            self::SenderDomainVerified => 'Domínio de envio verificado',
            self::SenderDomainFailed => 'Falha na verificação do domínio de envio',
            self::SenderDomainDeleted => 'Domínio de envio removido',
            self::CpfLookupPerformed => 'Consulta cadastral de CPF realizada',
            self::IdentityCaptureRequirementUpdated => 'Exigência de foto do participante alterada',
            self::IdentityCaptureRecorded => 'Imagem capturada pelo participante',
            self::IdentityCapturePurged => 'Imagem capturada excluída pela política de retenção',
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
            self::ChallengeVerified => 'Código de confirmação validado',
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
            self::SigningStepsUpdated => 'Etapas do fluxo alteradas',
            self::EnvelopeStepStarted => 'Etapa do fluxo iniciada',
            self::EnvelopeStepSkipped => 'Etapa do fluxo não aplicável (pulada)',
            self::DelegationPolicyUpdated => 'Regras de delegação alteradas',
            self::DelegationRequested => 'Pedido de delegação aguardando quem enviou',
            self::RecipientDelegated => 'Participante delegou a outra pessoa',
            self::DelegationRejected => 'Pedido de delegação recusado por quem enviou',
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
            self::ChallengePinVerified,
            self::SenderDomainVerified,
            self::InPersonAcceptanceRecorded,
            self::BatchChallengeVerified,
            self::BatchItemAuthorized,
            self::PublicFormSubmissionConfirmed,
            self::PublicFormSubmissionApproved,
            self::EnvelopeCompleted,
            self::EnvelopeSignedCompanyA1,
            self::ParticipantSignatureApplied,
            self::PaymentApproved,
            self::SubscriptionActivated,
            self::SubscriptionRenewed,
            self::SubscriptionResumed,
            self::SsoDomainVerified,
            self::SsoLoginSucceeded => 'ok',

            self::ChallengeFailed,
            self::ChallengePinFailed,
            self::SenderDomainFailed,
            self::BatchChallengeFailed,
            self::BatchItemFailed,
            self::PublicFormRevoked,
            self::PublicFormSubmissionRejected,
            self::DocumentProcessingFailed,
            self::DocumentBlocked,
            self::RecipientRefused,
            self::EnvelopeRefused,
            self::EnvelopeExpired,
            self::EnvelopeCanceled,
            self::EnvelopeFinalizationFailed,
            self::ParticipantCertificateRejected,
            self::ParticipantSignatureFailed,
            self::ParticipantSignatureExpired,
            self::PaymentFailed,
            self::SubscriptionPastDue,
            self::SubscriptionExpired,
            self::ImpersonationStarted,
            self::SsoLoginFailed,
            self::SsoBreakGlassUsed => 'warn',

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
