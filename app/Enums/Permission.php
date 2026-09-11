<?php

namespace App\Enums;

/**
 * Catálogo de permissões da organização (Fase 2, roadmap §2.14 — docs/fase-2/permissoes-e-times.md).
 *
 * O catálogo vive em código: funções personalizadas guardam só o subconjunto concedido
 * (`role_permissions`), e os papéis de sistema (Proprietário, Administrador, Operador)
 * derivam de `systemGrants()`, que reproduz EXATAMENTE o que owner/admin/member podiam
 * na Fase 1. Uma permissão nova acrescentada aqui chega aos papéis de sistema sem
 * migration; às funções personalizadas, só quando alguém a concede.
 *
 * Os valores são estáveis (gravados em banco e enviados ao front): nunca renomeie um caso.
 */
enum Permission: string
{
    // Documentos
    case CreateEnvelopes = 'create_envelopes';
    case SendEnvelopes = 'send_envelopes';
    case ViewAllEnvelopes = 'view_all_envelopes';
    case ManageAnyEnvelope = 'manage_any_envelope';
    case CancelAnyEnvelope = 'cancel_any_envelope';

    // Pastas, modelos e etiquetas
    case ManageFolders = 'manage_folders';
    case ManageTemplates = 'manage_templates';
    case ManageTags = 'manage_tags';

    // Relatórios e auditoria
    case ViewReports = 'view_reports';
    case ExportData = 'export_data';
    case ViewAuditLog = 'view_audit_log';

    // Usuários e acesso
    case ManageMembers = 'manage_members';
    case ManageRoles = 'manage_roles';
    case ManageTeams = 'manage_teams';
    case TransferOwnership = 'transfer_ownership';

    // Conta
    case ManageSettings = 'manage_settings';
    case ManageBilling = 'manage_billing';
    case ManageIntegrations = 'manage_integrations';
    case DeleteOrganization = 'delete_organization';

    /**
     * Chaves que já existiam em `organization.permissions` (OrgPermissions) na Fase 1. O
     * mapa compartilhado continua trazendo exatamente estas chaves com as funções
     * personalizadas desligadas.
     *
     * @var list<string>
     */
    public const LEGACY_SHARED_KEYS = [
        'manage_members',
        'manage_settings',
        'manage_billing',
        'delete_organization',
        'cancel_any_envelope',
        'view_all_envelopes',
        'manage_folders',
    ];

    public function label(): string
    {
        return match ($this) {
            self::CreateEnvelopes => 'Criar documentos',
            self::SendEnvelopes => 'Enviar para assinatura',
            self::ViewAllEnvelopes => 'Ver todos os documentos da conta',
            self::ManageAnyEnvelope => 'Editar documentos de outros usuários',
            self::CancelAnyEnvelope => 'Cancelar documentos de outros usuários',
            self::ManageFolders => 'Gerenciar pastas',
            self::ManageTemplates => 'Gerenciar modelos',
            self::ManageTags => 'Gerenciar etiquetas',
            self::ViewReports => 'Ver relatórios',
            self::ExportData => 'Exportar dados',
            self::ViewAuditLog => 'Ver registro de atividades da conta',
            self::ManageMembers => 'Convidar e remover usuários',
            self::ManageRoles => 'Gerenciar funções',
            self::ManageTeams => 'Gerenciar times',
            self::TransferOwnership => 'Transferir propriedade',
            self::ManageSettings => 'Alterar configurações da conta',
            self::ManageBilling => 'Ver plano e faturamento',
            self::ManageIntegrations => 'Acessar API e webhooks',
            self::DeleteOrganization => 'Excluir a conta',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CreateEnvelopes => 'Enviar arquivos, preparar e duplicar documentos',
            self::SendEnvelopes => 'Enviar, reenviar convites e cancelar os próprios documentos',
            self::ViewAllEnvelopes => 'Sem restrição por criador ou pasta',
            self::ManageAnyEnvelope => 'Editar, mover e excluir rascunhos criados por outras pessoas',
            self::CancelAnyEnvelope => 'Inclusive documentos enviados por outras pessoas',
            self::ManageFolders => 'Criar, renomear, excluir e definir quem acessa cada pasta',
            self::ManageTemplates => 'Criar e editar modelos de documento',
            self::ManageTags => 'Criar, renomear e excluir etiquetas',
            self::ViewReports => 'Relatórios dos documentos que o usuário pode ver',
            self::ExportData => 'Planilhas CSV dos documentos e assinaturas visíveis',
            self::ViewAuditLog => 'Quem alterou usuários, funções e configurações',
            self::ManageMembers => 'E alterar a função de outros usuários',
            self::ManageRoles => 'Criar e editar funções personalizadas',
            self::ManageTeams => 'Criar times e definir quem participa',
            self::TransferOwnership => 'Definir outro proprietário da conta',
            self::ManageSettings => 'Empresa, segurança e padrões de assinatura',
            self::ManageBilling => 'Uso, pagamentos e mudança de plano',
            self::ManageIntegrations => 'Gerar chaves e ver registros de integração',
            self::DeleteOrganization => 'Solicitar a exclusão da organização',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::CreateEnvelopes,
            self::SendEnvelopes,
            self::ViewAllEnvelopes,
            self::ManageAnyEnvelope,
            self::CancelAnyEnvelope => 'documents',

            self::ManageFolders,
            self::ManageTemplates,
            self::ManageTags => 'organization',

            self::ViewReports,
            self::ExportData,
            self::ViewAuditLog => 'reports',

            self::ManageMembers,
            self::ManageRoles,
            self::ManageTeams,
            self::TransferOwnership => 'people',

            self::ManageSettings,
            self::ManageBilling,
            self::ManageIntegrations,
            self::DeleteOrganization => 'account',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function groupLabels(): array
    {
        return [
            'documents' => 'Documentos',
            'organization' => 'Pastas, modelos e etiquetas',
            'reports' => 'Relatórios e auditoria',
            'people' => 'Usuários e acesso',
            'account' => 'Conta e cobrança',
        ];
    }

    /**
     * Pode ser concedida a uma função personalizada? As permissões exclusivas do
     * proprietário nunca são delegáveis: a conta sempre tem um dono identificável.
     */
    public function isGrantable(): bool
    {
        return ! in_array($this, [self::TransferOwnership, self::DeleteOrganization], true);
    }

    /**
     * Permissões dos papéis de sistema — o comportamento da Fase 1, sem tirar nem pôr:
     * owner tudo; admin tudo exceto o que é exclusivo do proprietário; member cria,
     * envia e gere os PRÓPRIOS documentos (vê só os próprios — RECONCILIACAO Q7) e
     * exporta/relata o que vê.
     *
     * @return list<self>
     */
    public static function systemGrants(MembershipRole $role): array
    {
        return match ($role) {
            MembershipRole::Owner => self::cases(),
            MembershipRole::Admin => array_values(array_filter(self::cases(), fn (self $p): bool => $p->isGrantable())),
            MembershipRole::Member => [
                self::CreateEnvelopes,
                self::SendEnvelopes,
                self::ViewReports,
                self::ExportData,
            ],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return list<string>
     */
    public static function grantableValues(): array
    {
        return array_values(array_map(
            fn (self $p): string => $p->value,
            array_filter(self::cases(), fn (self $p): bool => $p->isGrantable()),
        ));
    }
}
