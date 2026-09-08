<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\CurrentOrganization;
use App\Support\OrganizationSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Política "Exigir autenticação em duas etapas" da organização (alias `org.2fa`).
 * Membros sem TOTP confirmado são redirecionados para a tela de segurança da conta
 * (`security.edit`) com aviso; deve vir depois de `org`.
 */
class EnforceTwoFactorForOrganization
{
    /** Rotas que continuam acessíveis para o usuário conseguir ativar o 2FA ou sair. */
    private const ALLOWED_ROUTES = [
        'security.edit',
        'user-password.update',
        'profile.edit',
        'profile.update',
        'password.confirm',
        'password.confirmation',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        'logout',
        'organizations.switch',
        'organizations.create',
        'organizations.store',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $organization = CurrentOrganization::instance()->get();

        if (! $user instanceof User || $organization === null) {
            return $next($request);
        }

        if (! OrganizationSettings::of($organization)->requireTwoFactor() || $user->hasTwoFactorEnabled()) {
            return $next($request);
        }

        if ($request->routeIs(...self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'Esta organização exige autenticação em duas etapas.');
        }

        return redirect()
            ->route('security.edit')
            ->with('warning', 'A organização '.$organization->name.' exige autenticação em duas etapas. Ative o 2FA para continuar.');
    }
}
