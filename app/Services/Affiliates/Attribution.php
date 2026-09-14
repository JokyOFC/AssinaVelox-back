<?php

namespace App\Services\Affiliates;

use App\Models\Affiliate;
use App\Models\Organization;
use App\Models\Referral;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Atribuição de indicações (Fase 3 §3.10).
 *
 * 1. **Clique** em `/indicacao/{código}` grava um cookie de atribuição. O cookie é cifrado e
 *    autenticado pelo EncryptCookies do Laravel (logo, assinado) e guarda só o código e a hora
 *    do clique. A validade é conferida pela HORA DO CLIQUE, não pela expiração do cookie.
 * 2. **Modelo**: primeiro toque (padrão, `attribution_model=first_touch`) — um cookie válido
 *    não é substituído por outro link dentro da janela. `last_touch` é configurável.
 * 3. **Cadastro** (listener do evento Registered): se o cookie está dentro da janela e o código é
 *    de um afiliado aprovado, a organização criada no cadastro é atribuída — UMA única vez
 *    (`referrals.organization_id` UNIQUE).
 * 4. **Autoindicação** (mesmo usuário, mesmo e-mail, mesmo domínio corporativo, mesmo IP usado
 *    pelo afiliado dentro da janela) → indicação `rejected`, sem comissão, e sinal de risco.
 *    **Conta duplicada** (mesmo IP de outra indicação do afiliado dentro da janela) →
 *    indicação `held` (comissão segurada até revisão humana) e sinal de risco.
 */
final class Attribution
{
    public function __construct(
        private readonly AffiliateSettings $settings,
        private readonly AffiliateRiskSignals $risk,
    ) {}

    /**
     * Cookie a gravar para um clique no link, ou null quando não se deve gravar (código
     * inválido, ou primeiro toque com um cookie ainda válido de outro afiliado).
     */
    public function cookieForClick(Request $request, string $code): ?SymfonyCookie
    {
        $affiliate = $this->approvedAffiliateByCode($code);

        if ($affiliate === null) {
            return null;
        }

        $current = $this->readCookie($request);

        if ($current !== null && $this->settings->attributionModel() === AffiliateSettings::MODEL_FIRST_TOUCH
            && $this->withinWindow($current['clicked_at']) && $this->approvedAffiliateByCode($current['code']) !== null) {
            return null;
        }

        $value = json_encode(['c' => $affiliate->code, 't' => Carbon::now()->getTimestamp()], JSON_THROW_ON_ERROR);

        return Cookie::make(
            $this->settings->cookieName(),
            $value,
            $this->settings->attributionWindowDays() * 24 * 60,
            '/',
            null,
            (bool) config('session.secure'),
            true,
            false,
            'lax',
        );
    }

    /**
     * @return array{code: string, clicked_at: CarbonInterface}|null
     */
    public function readCookie(Request $request): ?array
    {
        $raw = $request->cookie($this->settings->cookieName());

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);

        if (! is_array($data) || ! is_string($data['c'] ?? null) || ! is_int($data['t'] ?? null)) {
            return null;
        }

        $clickedAt = Carbon::createFromTimestamp($data['t']);

        // Hora do clique no futuro: cookie inválido.
        if ($clickedAt->gt(Carbon::now()->addMinutes(5))) {
            return null;
        }

        return ['code' => strtoupper($data['c']), 'clicked_at' => $clickedAt];
    }

    public function withinWindow(CarbonInterface $clickedAt): bool
    {
        return $clickedAt->copy()->addDays($this->settings->attributionWindowDays())->gte(Carbon::now());
    }

    /**
     * Chamado depois do cadastro. Atribui a organização criada pelo novo usuário, se houver
     * cookie válido. Devolve a indicação criada (ativa, segurada ou barrada) ou null.
     */
    public function attributeRegistration(User $user, Request $request): ?Referral
    {
        $cookie = $this->readCookie($request);

        if ($cookie === null) {
            return null;
        }

        // O cookie já cumpriu seu papel (ou venceu): sai da resposta do cadastro.
        Cookie::queue(Cookie::forget($this->settings->cookieName()));

        if (! $this->withinWindow($cookie['clicked_at'])) {
            Log::info('affiliates.attribution.outside_window', ['user' => $user->getKey()]);

            return null;
        }

        $organization = $this->organizationCreatedAtSignup($user);
        $affiliate = $this->approvedAffiliateByCode($cookie['code']);

        if ($organization === null || $affiliate === null) {
            return null;
        }

        if (Referral::query()->where('organization_id', $organization->getKey())->exists()) {
            return null;
        }

        $now = Carbon::now();
        $ipHash = IpFingerprint::of($request->ip());
        $selfReasons = $this->selfReferralReasons($affiliate, $user, $ipHash, $now);
        $duplicateReasons = $selfReasons === [] ? $this->duplicateReasons($affiliate, $ipHash, $now) : [];

        $status = match (true) {
            $selfReasons !== [] => Referral::STATUS_REJECTED,
            $duplicateReasons !== [] => Referral::STATUS_HELD,
            default => Referral::STATUS_ACTIVE,
        };

        $reasons = [...$selfReasons, ...$duplicateReasons];
        $months = $this->settings->commissionMonths();

        try {
            $referral = DB::transaction(fn (): Referral => Referral::query()->create([
                'affiliate_id' => $affiliate->getKey(),
                'organization_id' => $organization->getKey(),
                'user_id' => $user->getKey(),
                'source' => Referral::SOURCE_LINK,
                'status' => $status,
                'block_reasons' => $reasons === [] ? null : $reasons,
                'signup_ip_hash' => $ipHash,
                'clicked_at' => $cookie['clicked_at'],
                'attributed_at' => $now,
                'expires_at' => $months !== null ? $now->copy()->addMonthsNoOverflow($months) : null,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Outra requisição atribuiu a mesma organização primeiro: vale a primeira.
            return null;
        }

        AffiliateTrail::record(AffiliateTrail::REFERRAL_ATTRIBUTED, null, $affiliate, $referral, payload: [
            'status' => $status,
            'reasons' => $reasons,
            'organization' => $organization->ulid,
        ]);

        if ($reasons !== []) {
            // Autoindicação (barrada) ou possível conta duplicada (segurada): sinal ao antifraude.
            $this->risk->report($organization, $affiliate, $referral, $reasons);
        }

        return $referral;
    }

    /**
     * Regras de autoindicação. Cada código é uma regra documentada; a combinação é "ou".
     *
     * @return list<string>
     */
    public function selfReferralReasons(Affiliate $affiliate, User $user, ?string $ipHash, CarbonInterface $now): array
    {
        $reasons = [];
        $affiliateUser = $affiliate->user;

        if ($affiliate->user_id !== null && (int) $affiliate->user_id === (int) $user->getKey()) {
            $reasons[] = Referral::REASON_SAME_USER;
        }

        if ($affiliateUser !== null) {
            if (self::normalizeEmail($affiliateUser->email) === self::normalizeEmail($user->email)) {
                $reasons[] = Referral::REASON_SAME_EMAIL;
            }

            $domain = self::domainOf($user->email);

            if ($domain !== '' && $domain === self::domainOf($affiliateUser->email)
                && ! in_array($domain, $this->settings->publicEmailDomains(), true)) {
                $reasons[] = Referral::REASON_SAME_DOMAIN;
            }
        }

        if ($ipHash !== null) {
            $windowStart = $now->copy()->subDays($this->settings->attributionWindowDays());
            $applicationSeen = $affiliate->terms_accepted_at;

            $matchesApplication = $affiliate->application_ip_hash !== null
                && hash_equals($affiliate->application_ip_hash, $ipHash)
                && $applicationSeen->gte($windowStart);

            $matchesLastSeen = $affiliate->last_ip_hash !== null
                && hash_equals($affiliate->last_ip_hash, $ipHash)
                && $affiliate->last_ip_at !== null
                && $affiliate->last_ip_at->gte($windowStart);

            if ($matchesApplication || $matchesLastSeen) {
                $reasons[] = Referral::REASON_SAME_IP;
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @return list<string>
     */
    public function duplicateReasons(Affiliate $affiliate, ?string $ipHash, CarbonInterface $now): array
    {
        if ($ipHash === null) {
            return [];
        }

        $duplicate = Referral::query()
            ->where('affiliate_id', $affiliate->getKey())
            ->where('signup_ip_hash', $ipHash)
            ->where('attributed_at', '>=', $now->copy()->subDays($this->settings->attributionWindowDays()))
            ->exists();

        return $duplicate ? [Referral::REASON_DUPLICATE_IP] : [];
    }

    public function approvedAffiliateByCode(string $code): ?Affiliate
    {
        $code = strtoupper(trim($code));

        if ($code === '' || ! preg_match('/^[A-Z0-9]{6,16}$/', $code)) {
            return null;
        }

        return Affiliate::query()->with('user')->where('code', $code)->where('status', Affiliate::STATUS_APPROVED)->first();
    }

    /**
     * Minúsculas; sem o sufixo `+tag`; sem pontos no Gmail (endereços equivalentes).
     */
    public static function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $local = explode('+', $local, 2)[0];

        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local.'@'.$domain;
    }

    public static function domainOf(string $email): string
    {
        $parts = explode('@', strtolower(trim($email)), 2);

        return $parts[1] ?? '';
    }

    private function organizationCreatedAtSignup(User $user): ?Organization
    {
        if ($user->current_organization_id === null) {
            return null;
        }

        $organization = Organization::query()->find($user->current_organization_id);

        // Cadastro por convite aponta para a organização de OUTRA pessoa: não é indicação.
        if ($organization === null || (int) $organization->created_by_user_id !== (int) $user->getKey()) {
            return null;
        }

        return $organization;
    }
}
