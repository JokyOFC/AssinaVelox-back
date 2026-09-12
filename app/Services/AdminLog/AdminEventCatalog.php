<?php

namespace App\Services\AdminLog;

use App\Enums\AuditEventType;

/**
 * Quais eventos de `audit_events` formam o LOG ADMINISTRATIVO da organização e em que
 * categoria cada um aparece.
 *
 * Só entram eventos da organização (quem mudou usuários, funções, times, acesso por pasta,
 * etiquetas, cobrança, exportações e acessos de suporte). Eventos do ciclo do envelope
 * (convite, código, aceite, recusa, download...) ficam FORA de propósito: eles carregam
 * dados de signatários, e o lugar deles é a trilha do próprio documento, com a mesma
 * autorização do documento.
 *
 * Categorias sem evento emitido hoje ("Configurações", "Exclusões") ficam fora da lista até
 * existirem eventos — as telas da Fase 1 não gravam trilha para essas ações.
 */
final class AdminEventCatalog
{
    /**
     * @return array<string, array{label: string, events: list<AuditEventType>}>
     */
    public static function categories(): array
    {
        return [
            'members' => [
                'label' => 'Usuários e funções',
                'events' => [
                    AuditEventType::MembershipRoleChanged,
                    AuditEventType::RoleCreated,
                    AuditEventType::RoleUpdated,
                    AuditEventType::RoleDeleted,
                ],
            ],
            'teams' => [
                'label' => 'Times e pastas',
                'events' => [
                    AuditEventType::TeamCreated,
                    AuditEventType::TeamUpdated,
                    AuditEventType::TeamDeleted,
                    AuditEventType::FolderAccessUpdated,
                ],
            ],
            'billing' => [
                'label' => 'Plano e cobrança',
                'events' => [
                    AuditEventType::PaymentCreated,
                    AuditEventType::PaymentApproved,
                    AuditEventType::PaymentFailed,
                    AuditEventType::SubscriptionActivated,
                    AuditEventType::SubscriptionCanceled,
                    AuditEventType::SubscriptionResumed,
                    AuditEventType::SubscriptionPastDue,
                    AuditEventType::SubscriptionExpired,
                    AuditEventType::SubscriptionRenewed,
                ],
            ],
            'templates' => [
                'label' => 'Modelos',
                // `template.used` fica fora: é gravado no envelope gerado (trilha do documento).
                'events' => [
                    AuditEventType::TemplateCreated,
                    AuditEventType::TemplateVersionCreated,
                    AuditEventType::TemplateUpdated,
                    AuditEventType::TemplateDuplicated,
                    AuditEventType::TemplateArchived,
                    AuditEventType::TemplateRestored,
                ],
            ],
            'tags' => [
                'label' => 'Etiquetas',
                'events' => [
                    AuditEventType::TagCreated,
                    AuditEventType::TagUpdated,
                    AuditEventType::TagDeleted,
                    AuditEventType::TagsApplied,
                    AuditEventType::TagsRemoved,
                ],
            ],
            'exports' => [
                'label' => 'Relatórios e exportações',
                'events' => [
                    AuditEventType::ReportExported,
                ],
            ],
            // Fase 2, onda B: ações da organização (sem dado de signatário no payload).
            'public_forms' => [
                'label' => 'Formulários públicos',
                'events' => [
                    AuditEventType::PublicFormCreated,
                    AuditEventType::PublicFormUpdated,
                    AuditEventType::PublicFormActivated,
                    AuditEventType::PublicFormPaused,
                    AuditEventType::PublicFormRevoked,
                    AuditEventType::PublicFormSubmissionApproved,
                    AuditEventType::PublicFormSubmissionRejected,
                ],
            ],
            'sender_domains' => [
                'label' => 'Domínios de envio',
                'events' => [
                    AuditEventType::SenderDomainCreated,
                    AuditEventType::SenderDomainVerified,
                    AuditEventType::SenderDomainFailed,
                    AuditEventType::SenderDomainDeleted,
                ],
            ],
            // Fase 2, onda D (D-API): criação e revogação de chaves de API. Payload só com o
            // ULID, o nome, as abilities e a validade — nunca o texto, o hash ou o prefixo.
            'integrations' => [
                'label' => 'API e integrações',
                'events' => [
                    AuditEventType::ApiTokenCreated,
                    AuditEventType::ApiTokenRevoked,
                ],
            ],
            'support' => [
                'label' => 'Acessos do suporte',
                'events' => [
                    AuditEventType::ImpersonationStarted,
                    AuditEventType::ImpersonationEnded,
                    AuditEventType::ImpersonationPageViewed,
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function eventValues(?string $category = null): array
    {
        $values = [];

        foreach (self::categories() as $key => $definition) {
            if ($category !== null && $key !== $category) {
                continue;
            }

            foreach ($definition['events'] as $event) {
                $values[] = $event->value;
            }
        }

        return $values;
    }

    public static function categoryOf(AuditEventType $type): ?string
    {
        foreach (self::categories() as $key => $definition) {
            if (in_array($type, $definition['events'], true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Chaves do payload que podem aparecer na tela, com rótulo. Tudo o que não está aqui é
     * descartado na apresentação — inclusive se um evento futuro gravar algo a mais.
     *
     * @return array<string, string>
     */
    public static function visiblePayloadKeys(): array
    {
        return [
            'name' => 'Nome',
            'previous_name' => 'Nome anterior',
            'plan' => 'Plano',
            'from' => 'De',
            'to' => 'Para',
            'members_count' => 'Membros',
            'folders_count' => 'Pastas',
            'permissions' => 'Permissões',
            'envelopes' => 'Documentos',
            'amount_cents' => 'Valor',
            'period_end' => 'Fim do ciclo',
            'report' => 'Relatório',
            'reason' => 'Motivo',
            'target_name' => 'Usuário acessado',
            'route' => 'Página',
            'end_reason' => 'Encerramento',
            'pages_viewed' => 'Páginas visitadas',
        ];
    }
}
