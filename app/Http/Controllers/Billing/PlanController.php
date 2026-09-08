<?php

namespace App\Http\Controllers\Billing;

use App\Enums\PlanBillingPeriod;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Support\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Escolha de planos (ROUTES §2.16) a partir da tabela `plans` (PlanSeeder).
 * Planos sandbox aparecem apenas em ambiente local/testing.
 */
class PlanController extends Controller
{
    /** @var array<string, string> */
    private const FEATURE_LABELS = [
        'email_otp' => 'Código por e-mail',
        'evidence_page' => 'Página de evidências',
        'company_a1' => 'Assinatura criptográfica da operadora',
        'folders' => 'Pastas',
        'priority_support' => 'Suporte prioritário',
        'api' => 'API e webhooks (Fase 2)',
        'templates' => 'Modelos (Fase 2)',
        'sms_whatsapp' => 'SMS e WhatsApp (Fase 2)',
        'branding' => 'Logo da empresa (Fase 2)',
    ];

    public function index(Request $request): Response
    {
        $organization = CurrentOrganization::instance()->get();
        Gate::authorize('manageBilling', $organization);

        $validated = $request->validate(['interval' => ['nullable', Rule::in(['monthly', 'yearly'])]]);
        $interval = $validated['interval'] ?? 'monthly';

        $current = $organization->currentSubscription()->with('plan')->first()?->plan;
        $currentCode = $current->code ?? Plan::CODE_FREE;
        $currentSort = $current->sort_order ?? 0;

        $plans = Plan::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('is_public', true)->when(app()->environment(['local', 'testing']), fn ($q) => $q->orWhere('is_sandbox', true)))
            ->orderBy('sort_order')
            ->get()
            ->map(function (Plan $plan) use ($currentCode, $currentSort): array {
                $yearly = $plan->billing_period === PlanBillingPeriod::Yearly;

                return [
                    'key' => $plan->code,
                    'code' => $plan->code,
                    'name' => $plan->name,
                    'price_cents_monthly' => $yearly ? (int) round($plan->price_cents / 12) : (int) $plan->price_cents,
                    'price_cents_yearly' => $yearly ? (int) $plan->price_cents : null,
                    'features' => self::featureLabels($plan),
                    'limits' => [
                        'envelopes_per_month' => $plan->envelope_quota,
                        'members' => $plan->user_quota,
                        'storage_bytes' => isset($plan->features['storage_bytes']) ? (int) $plan->features['storage_bytes'] : null,
                    ],
                    'highlighted' => $plan->code === Plan::CODE_PROFESSIONAL,
                    'cta' => match (true) {
                        $plan->code === $currentCode => 'current',
                        $plan->code === Plan::CODE_ENTERPRISE => 'contact',
                        $plan->sort_order > $currentSort => 'upgrade',
                        default => 'downgrade',
                    },
                ];
            })
            ->values()
            ->all();

        return Inertia::render('settings/plans', [
            'current_plan' => $currentCode,
            'interval' => $interval,
            'plans' => $plans,
        ]);
    }

    /**
     * @return list<string>
     */
    public static function featureLabels(?Plan $plan): array
    {
        $labels = [];

        foreach ((array) ($plan->features ?? []) as $key => $enabled) {
            if ($enabled === true && isset(self::FEATURE_LABELS[$key])) {
                $labels[] = self::FEATURE_LABELS[$key];
            }
        }

        if ($plan?->envelope_quota !== null) {
            array_unshift($labels, $plan->envelope_quota.' documentos/mês');
        } elseif ($plan !== null) {
            array_unshift($labels, 'Documentos ilimitados');
        }

        if ($plan?->user_quota !== null) {
            $labels[] = $plan->user_quota.' '.($plan->user_quota === 1 ? 'usuário' : 'usuários');
        }

        return $labels;
    }
}
