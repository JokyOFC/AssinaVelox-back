<?php

namespace App\Http\Middleware;

use App\Enums\MembershipStatus;
use App\Models\Impersonation;
use App\Models\Membership;
use App\Models\User;
use App\Services\Impersonation\ImpersonationManager;
use App\Services\Impersonation\ReadOnlyRoutes;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarda da sessão de "acessar como" (Fase 2 — docs/fase-2/tags-relatorios-e-logs.md §5).
 * Registrado no grupo `web` (bootstrap/app.php). Sem impersonation na sessão, não faz nada.
 *
 * Com impersonation:
 *  1. registro inexistente, encerrado, admin rebaixado/bloqueado ou usuário trocado →
 *     encerra e desloga;
 *  2. expirado (30 min), ou alvo sem membership ATIVA na organização da sessão (suspenso ou
 *     removido no meio dela) → encerra, devolve o login ao admin e o leva ao cliente;
 *  3. `POST /logout` → encerra por completo;
 *  4. rota fora de ReadOnlyRoutes → 403 (nada executa);
 *  5. página permitida → registra a visita na trilha da organização alvo e compartilha a
 *     prop `impersonation` (banner persistente).
 */
class EnforceImpersonationReadOnly
{
    public function __construct(private readonly ImpersonationManager $manager) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! ImpersonationManager::active($request)) {
            return $next($request);
        }

        $impersonation = $this->manager->current($request);
        $user = $request->user();

        if (! $this->valid($impersonation, $user)) {
            if ($impersonation !== null) {
                $this->manager->end($impersonation, Impersonation::END_INVALID);
            }

            $this->manager->stop($request, Impersonation::END_LOGOUT);

            return $this->deny($request, 'A sessão de suporte foi encerrada.', redirectToLogin: true);
        }

        /** @var Impersonation $impersonation */
        $routeName = $request->route()?->getName();

        if (! $impersonation->isActive()) {
            $this->manager->end($impersonation, Impersonation::END_EXPIRED);
            $this->manager->stop($request, Impersonation::END_EXPIRED);

            return $this->toAdmin($request, $impersonation, 'O acesso de suporte expirou após '.ImpersonationManager::TTL_MINUTES.' minutos.');
        }

        // A sessão vale para UMA organização: sem membership ativa do alvo nela, acabou.
        if (! $this->targetStillActive($impersonation)) {
            $this->manager->end($impersonation, Impersonation::END_INVALID);
            $this->manager->stop($request, Impersonation::END_INVALID);

            return $this->toAdmin($request, $impersonation, 'A sessão de suporte foi encerrada: o usuário não tem mais acesso ativo a esta organização.');
        }

        if ($routeName === ReadOnlyRoutes::LOGOUT_ROUTE) {
            $this->manager->stop($request, Impersonation::END_LOGOUT);

            return redirect()->route('login');
        }

        if (! ReadOnlyRoutes::allows($request->method(), $routeName)) {
            return $this->deny($request, 'Ação indisponível durante o acesso de suporte (somente leitura).');
        }

        // O alvo não tem a própria "organização preferida" alterada pela navegação do suporte:
        // `org` compara com este valor e não grava nada no registro do usuário.
        /** @var User $user */
        $user->setAttribute('current_organization_id', $impersonation->organization_id);
        $user->syncOriginalAttribute('current_organization_id');

        // Visita = página aberta. Pré-carregamento do Inertia e chamadas JSON (ex.: o popover de
        // notificações) não contam, para a trilha não virar ruído.
        $isPage = $request->header('X-Inertia') !== null || ! $request->expectsJson();

        if ($routeName !== ReadOnlyRoutes::STOP_ROUTE && $isPage && ! $request->prefetch()) {
            $this->manager->recordVisit($impersonation, $request);
        }

        Inertia::share('impersonation', [
            'id' => $impersonation->ulid,
            'admin_name' => $impersonation->admin->name,
            'target_name' => $impersonation->targetUser->name,
            'organization_name' => $impersonation->organization->name,
            'expires_at' => $impersonation->expires_at->toIso8601String(),
        ]);

        return $next($request);
    }

    /**
     * O middleware está no grupo `web`? "Acessar como" só começa com a guarda instalada.
     */
    public static function installed(): bool
    {
        $groups = Route::getMiddlewareGroups();

        return in_array(self::class, $groups['web'] ?? [], true);
    }

    private function valid(?Impersonation $impersonation, mixed $user): bool
    {
        if ($impersonation === null || $impersonation->ended_at !== null || ! $user instanceof User) {
            return false;
        }

        $admin = $impersonation->admin;

        return $user->getKey() === $impersonation->target_user_id
            && $admin->is_platform_admin
            && $admin->getAttribute('blocked_at') === null;
    }

    private function targetStillActive(Impersonation $impersonation): bool
    {
        return Membership::query()
            ->where('organization_id', $impersonation->organization_id)
            ->where('user_id', $impersonation->target_user_id)
            ->where('status', MembershipStatus::Active->value)
            ->whereHas('organization')
            ->exists();
    }

    private function deny(Request $request, string $message, bool $redirectToLogin = false): Response
    {
        if ($redirectToLogin) {
            return $request->expectsJson()
                ? response()->json(['message' => $message], 401)
                : redirect()->route('login')->with('warning', $message);
        }

        abort(403, $message);
    }

    private function toAdmin(Request $request, Impersonation $impersonation, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 409);
        }

        return redirect()
            ->route('admin.organizations.show', ['organization' => $impersonation->organization->ulid])
            ->with('warning', $message);
    }
}
