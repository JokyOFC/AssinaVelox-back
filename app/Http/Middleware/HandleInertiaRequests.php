<?php

namespace App\Http\Middleware;

use App\Enums\EnvelopeStatus;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organizations\EnvelopeVisibility;
use App\Support\CurrentOrganization;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

/**
 * Props compartilhadas (ROUTES_AND_PAGES §0.3 + RECONCILIACAO). Tudo que depende da
 * organização corrente é closure: o middleware `org` roda depois deste, e o Inertia só
 * resolve closures ao renderizar a resposta.
 */
class HandleInertiaRequests extends Middleware
{
    /** @var string */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => fn () => $this->authUser($request->user()),
            ],
            'organization' => fn () => $this->currentOrganization(),
            'organizations' => fn () => $this->organizations($request->user()),
            'counts' => fn () => $this->counts($request->user()),
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
                'info' => $request->session()->get('info'),
                'status' => $request->session()->get('status'),
            ],
            'features' => self::features(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Flags de fase (todas false na Fase 1). Placeholders leem estas chaves.
     *
     * @return array<string, bool>
     */
    public static function features(): array
    {
        return [
            'templates' => false,
            'api_integrations' => false,
            'reminders' => false,
            'sms_whatsapp' => false,
            'branding' => false,
            'multi_document' => false,
            'certificate_login' => false,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function authUser(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $organization = CurrentOrganization::instance()->get();

        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'initials' => $user->initials,
            'avatar_url' => null,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'is_platform_admin' => (bool) $user->is_platform_admin,
            'locale' => $user->locale ?: 'pt_BR',
            'timezone' => $user->timezone ?: ($organization->timezone ?? Organization::DEFAULT_TIMEZONE),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function currentOrganization(): ?array
    {
        return self::currentOrganizationProps();
    }

    /**
     * Shape compartilhado `organization` (ROUTES §0.3 CurrentOrganization). Público para que
     * páginas cujo contrato também chama sua prop de `organization` (settings/general,
     * envelopes/evidence) possam MESCLAR estes campos em vez de sombrear a prop compartilhada —
     * o shell (sidebar, switcher, menu da conta) lê `organization.plan/role/permissions`.
     *
     * @return array<string, mixed>|null
     */
    public static function currentOrganizationProps(): ?array
    {
        $current = CurrentOrganization::instance();
        $organization = $current->get();
        $membership = $current->membership();

        if ($organization === null || $membership === null) {
            return null;
        }

        $subscription = $organization->currentSubscription()->with('plan')->first();
        $plan = $subscription?->plan;

        return [
            'id' => $organization->ulid,
            'name' => $organization->name,
            'legal_name' => $organization->legal_name,
            'initials' => $organization->initials,
            'logo_url' => null,
            'timezone' => $organization->timezone,
            'role' => $membership->role->value,
            'plan' => [
                'code' => $plan->code ?? 'free',
                'key' => $plan->code ?? 'free', // alias consumido pelo front (ROUTES §0.3 `key`)
                'name' => $plan->name ?? 'Grátis',
                'status' => $subscription?->status->value ?? 'active',
            ],
            'permissions' => Permissions::forRole($membership->role),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function organizations(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $currentId = CurrentOrganization::instance()->id();

        return Membership::query()
            ->with(['organization.currentSubscription.plan'])
            ->where('user_id', $user->getKey())
            ->where('status', MembershipStatus::Active->value)
            ->whereHas('organization')
            ->orderBy('id')
            ->get()
            ->map(fn (Membership $membership): array => [
                'id' => $membership->organization->ulid,
                'name' => $membership->organization->name,
                'initials' => $membership->organization->initials,
                'plan_name' => $membership->organization->currentSubscription?->plan->name ?? 'Grátis',
                'role' => $membership->role->value,
                'is_current' => $membership->organization_id === $currentId,
            ])
            ->values()
            ->all();
    }

    /**
     * Contadores da sidebar/topbar, cacheados por org+usuário (config `assinavelox.counts_cache`).
     *
     * @return array{pending_envelopes: int, unread_notifications: int}
     */
    protected function counts(?User $user): array
    {
        $current = CurrentOrganization::instance();
        $membership = $current->membership();

        if ($user === null || $membership === null) {
            return ['pending_envelopes' => 0, 'unread_notifications' => 0];
        }

        $store = config('assinavelox.counts_cache.store');
        $ttl = (int) config('assinavelox.counts_cache.ttl_seconds', 60);
        $cache = $store ? Cache::store($store) : Cache::store();

        return $cache->remember(
            self::countsCacheKey($membership->organization_id, $user->getKey()),
            $ttl,
            fn (): array => [
                'pending_envelopes' => EnvelopeVisibility::envelopes($membership)
                    ->where('status', EnvelopeStatus::InProgress->value)
                    ->count(),
                'unread_notifications' => $user->unreadNotifications()
                    ->where('data->organization_id', $membership->organization_id)
                    ->count(),
            ],
        );
    }

    public static function countsCacheKey(int $organizationId, int $userId): string
    {
        return "counts:org:{$organizationId}:user:{$userId}";
    }

    public static function forgetCounts(int $organizationId, int $userId): void
    {
        $store = config('assinavelox.counts_cache.store');
        $cache = $store ? Cache::store($store) : Cache::store();

        $cache->forget(self::countsCacheKey($organizationId, $userId));
    }
}
