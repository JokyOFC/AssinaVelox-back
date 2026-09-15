<?php

namespace App\Services\Sso\Middleware;

use App\Models\User;
use App\Services\Impersonation\ImpersonationManager;
use App\Services\Sso\SsoEnforcement;
use App\Services\Sso\SsoSession;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exigência de login corporativo por organização (docs/fase-3/sso.md §5). Registrado no grupo
 * `web` e ordenado DEPOIS do middleware `org` pela lista de prioridade (bootstrap/app.php):
 * só age em rota com organização corrente; sem organização, sem flag, sem conexão ativa com
 * `enforce` ou durante o "acessar como" do suporte, não faz nada (Fase 1 intacta).
 *
 * Também confina o `trust_idp` (docs/fase-3/sso.md §8.1): a sessão aberta por SSO sem o 2FA da
 * conta — porque a organização A confia no MFA do próprio IdP — não abre OUTRA organização da
 * mesma pessoa. Ali o login volta ao desafio do Fortify (a mesma tela do login por senha) e só
 * segue com o código digitado. Sem `trust_idp` em uso, nada disto acontece.
 */
class EnforceOrganizationSso
{
    /** Continuam acessíveis para a pessoa sair, trocar de organização ou criar outra. */
    private const ALLOWED_ROUTES = [
        'logout',
        'organizations.switch',
        'organizations.create',
        'organizations.store',
    ];

    public function __construct(private readonly SsoEnforcement $enforcement) {}

    public function handle(Request $request, Closure $next): Response
    {
        $current = CurrentOrganization::instance();
        $organization = $current->get();
        $user = $request->user();

        if ($organization === null || ! $user instanceof User || ImpersonationManager::active($request) || $request->routeIs(...self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        if (SsoSession::needsTwoFactorStepUp($request, $user, (int) $organization->getKey())) {
            return $this->stepUp($request, $user, $organization->name);
        }

        $decision = $this->enforcement->evaluate($request, $user, $organization, $current->membership());

        if ($decision === SsoEnforcement::ALLOW) {
            return $next($request);
        }

        if ($decision === SsoEnforcement::TWO_FACTOR_STEP_UP) {
            return $this->stepUp($request, $user, $organization->name);
        }

        if ($decision === SsoEnforcement::OWNER_NEEDS_TWO_FACTOR) {
            if ($request->expectsJson()) {
                abort(403, 'Com o login corporativo obrigatório, o acesso por senha exige autenticação em duas etapas.');
            }

            return redirect()
                ->route('security.edit')
                ->with('warning', 'A organização '.$organization->name.' exige o login corporativo. Como proprietário, você só entra por senha com a autenticação em duas etapas ativa.');
        }

        if ($request->expectsJson()) {
            abort(403, 'Esta organização exige o login corporativo.');
        }

        return redirect()->route('sso.required');
    }

    /**
     * Devolve o login ao desafio 2FA do Fortify: a sessão sai do painel, guarda só o usuário
     * desafiado (`login.id`, o mesmo contrato do login por senha) e o destino, e a dispensa do
     * IdP é esquecida. Depois do código, a sessão vale como qualquer login com 2FA.
     */
    private function stepUp(Request $request, User $user, string $organizationName): Response
    {
        if ($request->expectsJson()) {
            abort(403, 'Confirme a autenticação em duas etapas da sua conta para acessar esta organização.');
        }

        if ($request->isMethod('GET')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        Auth::guard('web')->logout();
        SsoSession::clearTwoFactorTrust($request);
        $request->session()->put('login.id', $user->getKey());
        $request->session()->put('login.remember', false);

        return redirect()
            ->route('two-factor.login')
            ->with('status', 'Para abrir '.$organizationName.', digite o código da autenticação em duas etapas da sua conta. O login corporativo de outra organização não vale aqui.');
    }
}
