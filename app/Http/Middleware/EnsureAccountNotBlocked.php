<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AdminLog\ToolFlags;
use App\Services\Impersonation\ImpersonationManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Conta bloqueada pela equipe da plataforma (Painel interno › Usuários — Fase 2): nenhuma
 * requisição autenticada passa. Roda no grupo `web` (bootstrap/app.php) ANTES e DEPOIS da
 * rota: antes derruba sessões antigas e "lembrar de mim"; depois pega o login que acabou de
 * acontecer (POST /login, desafio 2FA, passkey) e o desfaz.
 *
 * Também mantém `users.last_seen_at` (no máximo uma escrita a cada 5 minutos, sem tocar em
 * `updated_at`) quando a flag `admin_users` está ligada — nunca durante "acessar como".
 *
 * Sem conta bloqueada e com a flag desligada, não faz nada (Fase 1 intacta).
 */
class EnsureAccountNotBlocked
{
    public const MESSAGE = 'Esta conta está bloqueada. Fale com o suporte da AssinaVelox.';

    private const SEEN_THROTTLE_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $impersonating = ImpersonationManager::active($request);

        if (! $impersonating && $this->blocked($request->user())) {
            return $this->logout($request);
        }

        $response = $next($request);

        if (! ImpersonationManager::active($request)) {
            $user = Auth::guard('web')->user();

            if ($this->blocked($user)) {
                return $this->logout($request);
            }

            if ($user instanceof User) {
                $this->touch($user);
            }
        }

        return $response;
    }

    private function blocked(mixed $user): bool
    {
        return $user instanceof User && $user->getAttribute('blocked_at') !== null;
    }

    private function touch(User $user): void
    {
        if (! ToolFlags::adminUsers()) {
            return;
        }

        $last = $user->getAttribute('last_seen_at');

        if ($last !== null && Carbon::parse($last)->diffInSeconds(Carbon::now()) < self::SEEN_THROTTLE_SECONDS) {
            return;
        }

        DB::table('users')->where('id', $user->getKey())->update(['last_seen_at' => Carbon::now()]);
    }

    private function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => self::MESSAGE], 403);
        }

        return redirect()->route('login')->withErrors(['email' => self::MESSAGE]);
    }
}
