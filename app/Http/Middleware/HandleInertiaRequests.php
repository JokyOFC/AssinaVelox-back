<?php

namespace App\Http\Middleware;

use App\Enums\EnvelopeStatus;
use App\Enums\MembershipStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Organizations\EnvelopeVisibility;
use App\Support\CurrentOrganization;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
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

    protected ?Membership $shellMembership = null;

    protected bool $shellMembershipResolved = false;

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
            'organization' => fn () => $this->currentOrganization($request),
            'organizations' => fn () => $this->organizations($request->user()),
            'counts' => fn () => $this->counts($request, $request->user()),
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
    protected function currentOrganization(Request $request): ?array
    {
        $props = self::currentOrganizationProps();

        if ($props !== null) {
            return $props;
        }

        $membership = $this->shellMembership($request);

        if ($membership === null) {
            return null;
        }

        return CurrentOrganization::instance()->runAs(
            $membership->organization,
            fn (): ?array => self::currentOrganizationProps(),
            $membership,
        );
    }

    /**
     * Membership usada APENAS para montar a casca (switcher, rail de Configurações,
     * badges) nas rotas de conta — `/perfil` e `/perfil/seguranca` não passam pelo
     * middleware `org` de propósito (o usuário sem organização precisa alcançá-las),
     * mas ROUTES_AND_PAGES §0.3 diz que `organization` só é null em rotas
     * platform-admin e guest. Sem isto a sidebar perdia a organização ao abrir o perfil.
     *
     * Resolve só para exibição: NÃO define CurrentOrganization fora do `runAs` de quem
     * chama, portanto não liga o escopo global nem afeta autorização.
     */
    protected function shellMembership(Request $request): ?Membership
    {
        if ($this->shellMembershipResolved) {
            return $this->shellMembership;
        }

        $this->shellMembershipResolved = true;

        $user = $request->user();

        // O painel interno é deliberadamente sem organização (ROUTES §0.3).
        if (! $user instanceof User || $request->is('admin', 'admin/*')) {
            return null;
        }

        $candidates = array_values(array_unique(array_filter([
            $request->hasSession() ? $request->session()->get(EnsureCurrentOrganization::SESSION_KEY) : null,
            $user->current_organization_id,
        ])));

        foreach ($candidates as $organizationId) {
            $membership = $this->activeMemberships($user)
                ->where('organization_id', (int) $organizationId)
                ->first();

            if ($membership !== null) {
                return $this->shellMembership = $membership;
            }
        }

        return $this->shellMembership = $this->activeMemberships($user)->orderBy('id')->first();
    }

    /**
     * @return Builder<Membership>
     */
    protected function activeMemberships(User $user): Builder
    {
        return Membership::query()
            ->with('organization')
            ->where('user_id', $user->getKey())
            ->where('status', MembershipStatus::Active->value)
            ->whereHas('organization');
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

        $memberships = Membership::query()
            ->with('organization')
            ->where('user_id', $user->getKey())
            ->where('status', MembershipStatus::Active->value)
            ->whereHas('organization')
            ->orderBy('id')
            ->get();

        $planNames = $this->planNamesFor(
            array_values(array_map(intval(...), $memberships->pluck('organization_id')->all()))
        );

        return $memberships
            ->map(fn (Membership $membership): array => [
                'id' => $membership->organization->ulid,
                'name' => $membership->organization->name,
                'initials' => $membership->organization->initials,
                'plan_name' => $planNames[$membership->organization_id] ?? 'Grátis',
                'role' => $membership->role->value,
                'is_current' => $membership->organization_id === $currentId,
            ])
            ->values()
            ->all();
    }

    /**
     * Nome do plano vigente de cada organização, em UMA consulta e SEM o escopo global de
     * organização (`Subscription` usa BelongsToOrganization: com a organização corrente
     * definida, o escopo esconderia as assinaturas das outras organizações do switcher).
     *
     * @param  list<int>  $organizationIds
     * @return array<int, string>
     */
    protected function planNamesFor(array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }

        return Subscription::withoutOrganizationScope()
            ->with('plan:id,name')
            ->whereIn('organization_id', $organizationIds)
            ->whereIn('status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::Trialing->value,
                SubscriptionStatus::PastDue->value,
            ])
            ->orderBy('id')
            ->get(['id', 'organization_id', 'plan_id'])
            // A mais recente por organização vence (mesma regra de Organization::currentSubscription).
            ->reduce(function (array $carry, Subscription $subscription): array {
                $carry[$subscription->organization_id] = $subscription->plan->name;

                return $carry;
            }, []);
    }

    /**
     * Contadores da sidebar/topbar, cacheados por org+usuário (config `assinavelox.counts_cache`).
     *
     * @return array{pending_envelopes: int, unread_notifications: int}
     */
    protected function counts(Request $request, ?User $user): array
    {
        $current = CurrentOrganization::instance();
        $membership = $current->membership();

        if ($user === null) {
            return ['pending_envelopes' => 0, 'unread_notifications' => 0];
        }

        // Rotas de conta: sem o middleware `org` a contagem precisa da membership da casca
        // e do escopo global ligado (EnvelopeVisibility depende dele para filtrar a org).
        if ($membership === null) {
            $membership = $this->shellMembership($request);

            if ($membership === null) {
                return ['pending_envelopes' => 0, 'unread_notifications' => 0];
            }

            return $current->runAs(
                $membership->organization,
                fn (): array => $this->counts($request, $user),
                $membership,
            );
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
