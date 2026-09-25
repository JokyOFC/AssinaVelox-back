<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PlanBillingPeriod;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SavePlanRequest;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Plans\PlanCatalogEditor;
use App\Services\Plans\PlanFeatureCatalog;
use App\Services\Plans\PlanFeatures;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Painel interno › Planos (docs/cobranca.md §18): o catálogo de planos que o app e o site
 * anunciam — preço, período, cotas, armazenamento, recursos e visibilidade.
 *
 * Só para `platform-admin`; criar e editar exigem senha confirmada e ficam em
 * `platform_audit_events` (PlanCatalogEditor). Não há exclusão: um plano com assinaturas
 * é desativado (`is_active = false`) e some das ofertas, sem tocar em quem já o contratou.
 */
class PlanController extends Controller
{
    public function __construct(private readonly PlanCatalogEditor $editor) {}

    public function index(): Response
    {
        $subscriptions = Subscription::query()
            ->select('plan_id', DB::raw('count(*) as total'))
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as active', [SubscriptionStatus::Active->value])
            ->groupBy('plan_id')
            ->get()
            ->keyBy('plan_id');

        $plans = Plan::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (Plan $plan) use ($subscriptions): array {
                $counts = $subscriptions->get($plan->getKey());
                $features = is_array($plan->features) ? $plan->features : [];
                $storage = isset($features[PlanCatalogEditor::STORAGE_KEY]) ? (int) $features[PlanCatalogEditor::STORAGE_KEY] : null;

                return [
                    'code' => $plan->code,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'price_cents' => (int) $plan->price_cents,
                    'price_formatted' => $plan->formatPrice(),
                    'billing_period' => $plan->billing_period->value,
                    'billing_period_label' => $plan->billing_period->label(),
                    'envelope_quota' => $plan->envelope_quota,
                    'user_quota' => $plan->user_quota,
                    'storage_gb' => $storage !== null ? round($storage / (1024 * 1024 * 1024), 1) : null,
                    'features' => PlanFeatureCatalog::booleans($plan),
                    // Chaves fora do catálogo (ex.: bulk_generation_limits): mostradas, nunca editadas.
                    'extra_feature_keys' => array_values(array_diff(
                        array_keys($features),
                        PlanFeatureCatalog::keys(),
                        [PlanCatalogEditor::STORAGE_KEY],
                    )),
                    'is_active' => (bool) $plan->is_active,
                    'is_public' => (bool) $plan->is_public,
                    'is_sandbox' => (bool) $plan->is_sandbox,
                    'sort_order' => (int) $plan->sort_order,
                    'is_free_plan' => $plan->code === Plan::CODE_FREE,
                    'subscriptions' => [
                        'active' => (int) ($counts->active ?? 0),
                        'total' => (int) ($counts->total ?? 0),
                    ],
                    'updated_at' => $plan->updated_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('admin/plans/index', [
            'plans' => $plans,
            'catalog' => [
                'groups' => PlanFeatureCatalog::groups(),
                'entries' => PlanFeatureCatalog::forAdmin(),
            ],
            'billing_periods' => array_map(
                fn (PlanBillingPeriod $period): array => ['value' => $period->value, 'label' => $period->label()],
                PlanBillingPeriod::cases(),
            ),
            'company_signature_offered' => app(PlanFeatures::class)->isOffered(),
            'environment' => app()->environment(),
        ]);
    }

    public function store(SavePlanRequest $request): RedirectResponse
    {
        $plan = $this->editor->create($request->validated(), $request->user());

        return back()->with('success', 'Plano "'.$plan->name.'" criado.');
    }

    public function update(SavePlanRequest $request, Plan $plan): RedirectResponse
    {
        $plan = $this->editor->update($plan, $request->validated(), $request->user());

        return back()->with('success', 'Plano "'.$plan->name.'" atualizado.');
    }
}
