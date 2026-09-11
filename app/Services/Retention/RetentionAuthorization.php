<?php

namespace App\Services\Retention;

use App\Enums\Permission;
use App\Models\Membership;

/**
 * Quem configura a retenção e quem cria/libera preservações.
 *
 * - Configurar a política: `manage_settings` (é configuração da conta, como Marca e Padrões).
 * - Preservar e liberar (`manage_legal_holds`): capacidade PRÓPRIA, resolvida só aqui. O
 *   catálogo `App\Enums\Permission` está fora da área K-RET; até ganhar o caso
 *   `ManageLegalHolds` (contrato em docs/fase-2/retencao-e-preservacao.md §9), a capacidade
 *   vale para o proprietário e para quem tem, ao mesmo tempo, `manage_settings` E
 *   `view_all_envelopes` (Administrador por padrão; função personalizada só se receber as
 *   duas). Trocar pela permissão do catálogo é mudar {@see self::canManageHolds()} — as rotas e
 *   a tela não mudam.
 */
final class RetentionAuthorization
{
    public const MANAGE_LEGAL_HOLDS = 'manage_legal_holds';

    public static function canConfigure(?Membership $membership): bool
    {
        return $membership !== null
            && $membership->isActive()
            && $membership->hasPermission(Permission::ManageSettings);
    }

    public static function canManageHolds(?Membership $membership): bool
    {
        if ($membership === null || ! $membership->isActive()) {
            return false;
        }

        return $membership->isOwner()
            || ($membership->hasPermission(Permission::ManageSettings)
                && $membership->hasPermission(Permission::ViewAllEnvelopes));
    }
}
