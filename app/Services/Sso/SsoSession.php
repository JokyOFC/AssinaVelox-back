<?php

namespace App\Services\Sso;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Chaves de sessão do login corporativo (docs/fase-3/sso.md §8). Nada aqui é segredo de
 * terceiro: `state`, `nonce` e `code_verifier` são nossos, de uso único e com validade curta.
 *
 *  - `sso.oidc.flows`: fluxos OIDC pendentes, por `state`;
 *  - `sso.authenticated`: organização => conexão pela qual ESTA sessão entrou (a exigência de
 *    SSO confere aqui);
 *  - `sso.pending_2fa`: login por SSO aguardando o código do 2FA do usuário (política `keep`);
 *  - `sso.break_glass`: organizações em que o acesso de emergência já foi registrado nesta sessão.
 */
final class SsoSession
{
    public const FLOWS = 'sso.oidc.flows';

    public const AUTHENTICATED = 'sso.authenticated';

    public const PENDING_TWO_FACTOR = 'sso.pending_2fa';

    public const BREAK_GLASS = 'sso.break_glass';

    /** Organização cujo `trust_idp` dispensou o 2FA da conta NESTA sessão (revisão G). */
    public const TWO_FACTOR_TRUSTED = 'sso.two_factor_trusted_for';

    /**
     * @param  array<string, mixed>  $flow
     */
    public static function putFlow(Request $request, string $state, array $flow): void
    {
        $flows = (array) $request->session()->get(self::FLOWS, []);
        $now = Carbon::now()->getTimestamp();

        // Sai o que venceu e, acima do teto, o mais antigo.
        $flows = array_filter($flows, static fn ($entry): bool => is_array($entry) && (int) ($entry['expires_at'] ?? 0) > $now);
        $flows[$state] = $flow;
        $max = max(1, (int) config('assinavelox.sso.max_pending_flows', 5));

        while (count($flows) > $max) {
            array_shift($flows);
        }

        $request->session()->put(self::FLOWS, $flows);
    }

    /**
     * Retira o fluxo (uso único): um `state` repetido nunca encontra nada.
     *
     * @return array<string, mixed>|null
     */
    public static function pullFlow(Request $request, string $state): ?array
    {
        $flows = (array) $request->session()->get(self::FLOWS, []);
        $flow = $flows[$state] ?? null;
        unset($flows[$state]);
        $request->session()->put(self::FLOWS, $flows);

        return is_array($flow) ? $flow : null;
    }

    public static function markAuthenticated(Request $request, int $organizationId, int $connectionId): void
    {
        $map = (array) $request->session()->get(self::AUTHENTICATED, []);
        $map[(string) $organizationId] = $connectionId;
        $request->session()->put(self::AUTHENTICATED, $map);
    }

    public static function authenticatedVia(Request $request, int $organizationId): ?int
    {
        if (! $request->hasSession()) {
            return null;
        }

        $map = (array) $request->session()->get(self::AUTHENTICATED, []);
        $value = $map[(string) $organizationId] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Login por SSO com `trust_idp` de quem TEM 2FA na conta: o código do Fortify não foi
     * digitado nesta sessão — a confiança no MFA do IdP vale só para a organização da conexão.
     */
    public static function markTwoFactorTrusted(Request $request, int $organizationId): void
    {
        $request->session()->put(self::TWO_FACTOR_TRUSTED, $organizationId);
    }

    public static function clearTwoFactorTrust(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::TWO_FACTOR_TRUSTED);
        }
    }

    /** Organização cuja confiança no IdP dispensou o 2FA nesta sessão (null: 2FA não dispensado). */
    public static function twoFactorTrustedFor(Request $request): ?int
    {
        if (! $request->hasSession()) {
            return null;
        }

        $value = $request->session()->get(self::TWO_FACTOR_TRUSTED);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * A conta tem 2FA, ele foi dispensado pelo IdP de OUTRA organização e ainda não foi digitado
     * nesta sessão: antes de liberar esta organização, é preciso o código do Fortify.
     */
    public static function needsTwoFactorStepUp(Request $request, User $user, int $organizationId): bool
    {
        $trusted = self::twoFactorTrustedFor($request);

        return $trusted !== null && $trusted !== $organizationId && $user->hasTwoFactorEnabled();
    }

    /** O 2FA da conta foi DIGITADO nesta sessão (e não só dispensado pela confiança no IdP)? */
    public static function twoFactorTyped(Request $request, User $user): bool
    {
        return $user->hasTwoFactorEnabled() && self::twoFactorTrustedFor($request) === null;
    }

    public static function breakGlassRecorded(Request $request, int $organizationId): bool
    {
        return in_array($organizationId, (array) $request->session()->get(self::BREAK_GLASS, []), true);
    }

    public static function recordBreakGlass(Request $request, int $organizationId): void
    {
        $list = (array) $request->session()->get(self::BREAK_GLASS, []);
        $list[] = $organizationId;
        $request->session()->put(self::BREAK_GLASS, array_values(array_unique($list)));
    }
}
