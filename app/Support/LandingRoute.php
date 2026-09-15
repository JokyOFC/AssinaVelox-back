<?php

namespace App\Support;

use App\Enums\MembershipStatus;
use App\Models\User;
use Laravel\Fortify\Fortify;

/**
 * Para onde um usuário autenticado "vai para casa": o dashboard da organização corrente ou,
 * para o administrador da plataforma que não participa de organização nenhuma, o painel
 * interno. Sem isto o login e o middleware `org` mandavam o administrador para "Criar nova
 * organização" — o app de cliente não faz sentido para uma conta que só opera o painel.
 *
 * Um administrador que TAMBÉM é membro de alguma organização continua caindo no dashboard:
 * para ele o app de cliente funciona, e o painel fica a um clique no menu da conta.
 */
final class LandingRoute
{
    public static function for(User $user): string
    {
        return self::isPlatformAdminWithoutOrganization($user)
            ? route('admin.organizations.index')
            : Fortify::redirects('login');
    }

    public static function isPlatformAdminWithoutOrganization(User $user): bool
    {
        if (! $user->is_platform_admin) {
            return false;
        }

        return ! $user->memberships()
            ->where('status', MembershipStatus::Active->value)
            ->whereHas('organization')
            ->exists();
    }
}
