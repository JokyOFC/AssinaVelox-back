<?php

namespace App\Http\Middleware;

use App\Enums\EnvelopeStatus;
use App\Enums\MembershipStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AdminLog\ToolFlags;
use App\Services\Affiliates\AffiliatesFeature;
use App\Services\Anchors\AnchorFeatures;
use App\Services\Api\ApiFeature;
use App\Services\Billing\BillingSettings;
use App\Services\Branding\BrandingFeature;
use App\Services\Branding\BrandingPresenter;
use App\Services\BulkGeneration\BulkGenerationFeature;
use App\Services\Dossier\DossierFeature;
use App\Services\Envelopes\DomainFeatures;
use App\Services\Envelopes\Reminders\RemindersFeature;
use App\Services\Envelopes\Steps\FlowFeatures;
use App\Services\Fiscal\FiscalFeature;
use App\Services\Identity\IdentityFeatures;
use App\Services\InPerson\PresenceFeatures;
use App\Services\Ltv\LtvFeatures;
use App\Services\Organizations\EnvelopeVisibility;
use App\Services\PublicForms\PublicFormsFeature;
use App\Services\RestHooks\RestHooksFeature;
use App\Services\Retention\RetentionFeature;
use App\Services\Risk\RiskFeature;
use App\Services\Risk\RiskStatus;
use App\Services\Signing\Channels\ChannelFeatures;
use App\Services\Templates\TemplatesFeature;
use App\Services\Timestamp\TimestampFeatures;
use App\Services\Webhooks\WebhooksFeature;
use App\Support\CurrentOrganization;
use App\Support\Locale\MultilingualFeature;
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
            // Closure: depende da organização corrente, definida pelo middleware `org`.
            'features' => fn (): array => self::features(
                CurrentOrganization::instance()->get() ?? $this->shellMembership($request)?->organization,
            ),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Fase 3 §3.7 (revisão adversarial I-3A): a organização em observação ou com envio
            // suspenso encontra no app o motivo e o caminho da revisão humana (LGPD art. 20).
            // Só o estado — nunca pontuação, limiar ou regra.
            'risk' => fn (): ?array => self::riskNotice(CurrentOrganization::instance()->get()),
        ];
    }

    /**
     * @return array{status: string, status_label: string, appeal_url: string}|null
     */
    public static function riskNotice(?Organization $organization): ?array
    {
        if ($organization === null || ! RiskFeature::enabled()) {
            return null;
        }

        $status = RiskStatus::fromStored($organization->getAttribute('risk_status'));

        if ($status === RiskStatus::Normal) {
            return null;
        }

        return [
            'status' => $status->value,
            'status_label' => $status->label(),
            'appeal_url' => route('risk.appeal.show', [], false),
        ];
    }

    /**
     * Flags de fase. As sete chaves da Fase 1 continuam; as da Fase 2 (onda A) vêm dos
     * resolvedores de cada área — interruptor global `assinavelox.features.*` E plano da
     * organização (roadmap §1 T8). Com tudo desligado (o padrão), todas são `false` e o
     * front mostra os placeholders da Fase 1. A flag liga a interface; a autorização
     * continua nas Policies.
     *
     * @return array<string, bool>
     */
    public static function features(?Organization $organization = null): array
    {
        $tools = ToolFlags::forOrganization($organization);
        $channels = ChannelFeatures::forOrganization($organization);

        return [
            'templates' => TemplatesFeature::enabled($organization),
            // Fase 2, onda D (D-API): era a chave reservada da Fase 1; agora global E plano.
            'api_integrations' => ApiFeature::enabled($organization),
            'reminders' => app(RemindersFeature::class)->enabledFor($organization),
            'sms_whatsapp' => $channels[ChannelFeatures::SMS_WHATSAPP],
            'branding' => BrandingFeature::enabled($organization),
            'multi_document' => DomainFeatures::multiDocument($organization),
            'certificate_login' => false,
            'participant_roles' => DomainFeatures::participantRoles($organization),
            'custom_roles' => Permissions::customRolesEnabled($organization),
            'tags' => $tools[ToolFlags::TAGS],
            'reports' => $tools[ToolFlags::REPORTS],
            'audit_log' => $tools[ToolFlags::AUDIT_LOG],
            // Plataforma: só o interruptor global (o painel interno não tem organização).
            'admin_users' => ToolFlags::adminUsers(),
            'admin_audit' => ToolFlags::adminAudit(),
            'impersonation' => ToolFlags::impersonation(),
            // Fase 2, onda B — mesma regra (global E plano). `sms_whatsapp` e `branding`, acima,
            // eram reservadas da Fase 1 e agora vêm dos resolvedores das áreas.
            ...$channels,
            ...IdentityFeatures::forOrganization($organization),
            // Cadastro e telas sem organização: só o interruptor global do CNPJ.
            'cnpj_lookup' => $organization === null
                ? IdentityFeatures::cnpjLookupWithoutOrganization()
                : IdentityFeatures::cnpjLookup($organization),
            ...PresenceFeatures::forOrganization($organization),
            'public_forms' => PublicFormsFeature::enabled($organization),
            // Fase 2, onda C (docs/fase-2/onda-c-relatorio.md). `dossier_export` e
            // `retention_policies`: global E plano. `operator_tsa` e `pades_bt`: só a chave da
            // plataforma, e NENHUMA das duas muda o perfil anunciado (continua PAdES-B-B, T2).
            // `participant_a1` não entra aqui: a página pública descobre o recurso pelo
            // `GET sign.certificate.show` (404 = desligado), docs/fase-2/a1-do-participante.md §1.
            'dossier_export' => DossierFeature::enabled($organization),
            'retention_policies' => RetentionFeature::enabled($organization),
            'operator_tsa' => TimestampFeatures::operatorTsa(),
            'pades_bt' => TimestampFeatures::padesBt(),
            // Fase 2, onda D (docs/fase-2/entrega-fase-2.md). `outbound_webhooks` e `rest_hooks`:
            // global E plano (`rest_hooks` exige também a API e os webhooks). `extended_payments`
            // e `fiscal_invoices`: só a chave da plataforma — a cobrança é da operadora, não do plano.
            'outbound_webhooks' => WebhooksFeature::enabled($organization),
            'rest_hooks' => RestHooksFeature::enabled($organization),
            'extended_payments' => app(BillingSettings::class)->extendedPayments(),
            'fiscal_invoices' => FiscalFeature::enabled(),
            // Fase 3, parte 1 (integração I-3A): só as chaves da PLATAFORMA, todas desligadas por
            // padrão (T8). `a3_signing` e `govbr_return` não entram: a página pública descobre os
            // recursos por `GET sign.external.show` / `sign.govbr.show` (404 = desligado), como o A1.
            // `pades_ltv*` nunca mudam o perfil anunciado (continua PAdES-B-B, T2).
            'antifraud' => RiskFeature::enabled(),
            'affiliates' => AffiliatesFeature::enabled(),
            'pades_ltv' => LtvFeatures::enabled(),
            'pades_ltv_advertise' => LtvFeatures::advertise(),
            // Fase 3 §3.3 (F-FLOW): `conditional_steps` e `delegation` — global E plano.
            ...FlowFeatures::forOrganization($organization),
            // Fase 3 §3.3 (F-VIDEO): vídeo curto no aceite — global E plano, desligada (T8).
            'identity_video' => IdentityFeatures::identityVideo($organization),
            // Fase 3 §3.1 (F-BULK): geração em lote — global E plano (exige `templates`), desligada (T8).
            'bulk_generation' => BulkGenerationFeature::enabled($organization),
            // Fase 3 §3.2 (F-ANCHOR): âncoras e OCR — global E plano (`ocr` exige `field_anchors`).
            ...AnchorFeatures::forOrganization($organization),
            // Fase 3 §3.3 (F-I18N): página pública e e-mails multilíngues — global E plano, desligada (T8).
            'multilingual' => MultilingualFeature::enabled($organization),
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
            // Fase 2 §2.8: logo da marca (flag `branding` + logo salvo); null na Fase 1.
            'logo_url' => app(BrandingPresenter::class)->logoUrl($organization),
            'timezone' => $organization->timezone,
            'role' => $membership->role->value,
            'plan' => [
                'code' => $plan->code ?? 'free',
                'key' => $plan->code ?? 'free', // alias consumido pelo front (ROUTES §0.3 `key`)
                'name' => $plan->name ?? 'Grátis',
                'status' => $subscription?->status->value ?? 'active',
            ],
            // Fase 2: derivadas da função efetiva (papel de sistema ou personalizada).
            'permissions' => Permissions::sharedMap($membership, $organization, $plan),
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
