<?php

namespace App\Support;

use App\Enums\MembershipRole;

/**
 * Permissões derivadas do papel na organização (ROUTES_AND_PAGES §0.3 OrgPermissions e
 * docs/arquitetura.md §7). O front nunca decide por role: consome este mapa.
 */
final class Permissions
{
    /** @var list<string> */
    public const KEYS = [
        'manage_members',
        'manage_settings',
        'manage_billing',
        'delete_organization',
        'cancel_any_envelope',
        'view_all_envelopes',
        'manage_folders',
    ];

    /**
     * @return array<string, bool>
     */
    public static function forRole(?MembershipRole $role): array
    {
        $isAdmin = $role !== null && $role->isAtLeast(MembershipRole::Admin);
        $isOwner = $role === MembershipRole::Owner;

        return [
            'manage_members' => $isAdmin,
            'manage_settings' => $isAdmin,
            'manage_billing' => $isAdmin,
            'delete_organization' => $isOwner,
            'cancel_any_envelope' => $isAdmin,
            'view_all_envelopes' => $isAdmin,
            'manage_folders' => $isAdmin,
        ];
    }

    /**
     * Matriz exibida na aba "Funções e permissões" (ROUTES §2.10).
     *
     * @return array<int, array{key: string, label: string, description: string, grants: array<string, bool>}>
     */
    public static function matrix(): array
    {
        $all = ['owner' => true, 'admin' => true, 'member' => true];
        $admins = ['owner' => true, 'admin' => true, 'member' => false];
        $owner = ['owner' => true, 'admin' => false, 'member' => false];

        $rows = [
            ['create_envelopes', 'Criar e enviar documentos', 'Upload, preparação e envio de solicitações', $all],
            ['view_own_envelopes', 'Ver os próprios documentos', 'Documentos criados pelo usuário', $all],
            ['view_all_envelopes', 'Ver todos os documentos da conta', 'Sem restrição por criador', $admins],
            ['cancel_any_envelope', 'Cancelar documentos', 'Inclusive de outros usuários', $admins],
            ['manage_folders', 'Gerenciar pastas', 'Criar, renomear e excluir', $admins],
            ['manage_members', 'Convidar e remover usuários', 'E alterar funções (exceto proprietários)', $admins],
            ['manage_settings', 'Alterar configurações da conta', 'Empresa, segurança e padrões de assinatura', $admins],
            ['manage_billing', 'Ver plano e faturamento', 'Uso, pagamentos e mudança de plano', $admins],
            ['transfer_ownership', 'Transferir propriedade', 'Definir outro proprietário da conta', $owner],
            ['delete_organization', 'Excluir a conta', 'Solicitar exclusão da organização', $owner],
            ['export_audit', 'Exportar trilha de auditoria', 'Relatórios em PDF/CSV dos documentos visíveis', $all],
        ];

        return array_map(fn (array $row): array => [
            'key' => $row[0],
            'label' => $row[1],
            'description' => $row[2],
            'grants' => $row[3],
        ], $rows);
    }

    /**
     * @return array<int, array{key: string, label: string, description: string}>
     */
    public static function roles(): array
    {
        return [
            ['key' => MembershipRole::Owner->value, 'label' => MembershipRole::Owner->label(), 'description' => 'Acesso total, inclusive cobrança, exclusão da conta e gestão de proprietários.'],
            ['key' => MembershipRole::Admin->value, 'label' => MembershipRole::Admin->label(), 'description' => 'Gerencia usuários (exceto proprietários), pastas, todos os documentos e configurações.'],
            ['key' => MembershipRole::Member->value, 'label' => MembershipRole::Member->label(), 'description' => 'Cria e gerencia os próprios documentos; vê apenas os próprios.'],
        ];
    }
}
