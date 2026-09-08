<?php

namespace App\Http\Middleware;

use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Política "Encerrar sessões após N horas inativas" (Configurações › Geral e segurança).
 *
 * ROUTES_AND_PAGES §7 Q27: "Implementar como middleware que compara `last_activity` com o
 * menor `session_idle_hours` entre as orgs do usuário que tenham a política ativa." A sessão
 * do Laravel é por usuário e a política é por organização, então vale a janela mais curta
 * entre as organizações ativas do usuário.
 *
 * O carimbo de atividade vive na própria sessão (`security.last_activity_at`) e é renovado a
 * cada requisição autenticada; ao estourar a janela a sessão é encerrada e invalidada.
 */
class EnforceSessionIdleTimeout
{
    public const SESSION_KEY = 'security.last_activity_at';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $request->hasSession()) {
            return $next($request);
        }

        $window = $this->idleWindowInSeconds($user);
        $lastActivity = $request->session()->get(self::SESSION_KEY);

        if ($window !== null && is_int($lastActivity) && (now()->getTimestamp() - $lastActivity) > $window) {
            return $this->endSession($request);
        }

        $request->session()->put(self::SESSION_KEY, now()->getTimestamp());

        return $next($request);
    }

    /**
     * Menor `session_idle_hours` entre as organizações ativas do usuário que têm a política
     * ligada; null quando nenhuma delas exige expiração por inatividade.
     */
    private function idleWindowInSeconds(User $user): ?int
    {
        $hours = Organization::query()
            ->whereHas('memberships', fn ($membership) => $membership
                ->where('user_id', $user->getKey())
                ->where('status', MembershipStatus::Active->value))
            ->get()
            ->map(fn (Organization $organization): ?int => OrganizationSettings::of($organization)->sessionIdleHours())
            ->filter(fn (?int $value): bool => $value !== null && $value > 0)
            ->min();

        return $hours === null ? null : (int) $hours * 3600;
    }

    private function endSession(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $message = 'Sua sessão foi encerrada por inatividade. Entre novamente para continuar.';

        if ($request->expectsJson()) {
            abort(401, $message);
        }

        return redirect()->route('login')->with('warning', $message);
    }
}
