<?php

namespace App\Http\Controllers\Site;

use App\Enums\PlanBillingPeriod;
use App\Http\Controllers\Billing\PlanController;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Catálogo de planos para o site institucional (assinavelox.com.br) — docs/site-institucional.md.
 *
 * Mesma regra de visibilidade de {@see PlanController}: em produção só planos ativos E públicos;
 * os planos sandbox (preços de desenvolvimento, `PlanSeeder`) só aparecem em `local`/`testing`,
 * e sempre rotulados como `price_is_placeholder` — o site não pode anunciar como oferta um
 * valor que a própria plataforma diz ser fictício.
 *
 * Não há sessão nem organização: nada aqui depende de quem consulta. O site decide para onde
 * cada botão leva a partir de `cta` (`register` abre o cadastro do app; `contact` é a conversa
 * comercial, no próprio site).
 */
class PlanCatalogController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = Plan::query()
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query
                ->where('is_public', true)
                ->when(app()->environment(['local', 'testing']), fn (Builder $inner) => $inner->orWhere('is_sandbox', true)))
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Plan $plan): array => $this->payload($plan))
            ->values()
            ->all();

        // O catálogo muda por configuração comercial, não por requisição: cinco minutos de cache
        // no navegador do visitante bastam e poupam o banco de uma consulta por visita.
        return response()
            ->json(['data' => $plans])
            ->header('Cache-Control', 'public, max-age=300');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Plan $plan): array
    {
        $yearly = $plan->billing_period === PlanBillingPeriod::Yearly;
        $sandbox = (bool) $plan->is_sandbox;

        return [
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->description,
            'currency' => $plan->currency,
            'billing_period' => $plan->billing_period->value,
            'billing_period_label' => $plan->billing_period->label(),
            'price_cents' => (int) $plan->price_cents,
            'price_cents_monthly' => $yearly ? (int) round($plan->price_cents / 12) : (int) $plan->price_cents,
            'price_formatted' => $plan->formatPrice(),
            'is_free' => $plan->isFree(),
            'features' => PlanController::featureLabels($plan),
            'limits' => [
                'envelopes_per_month' => $plan->envelope_quota,
                'members' => $plan->user_quota,
            ],
            'highlighted' => $plan->code === Plan::CODE_PROFESSIONAL,
            'is_sandbox' => $sandbox,
            'price_is_placeholder' => $sandbox && ! $plan->isFree(),
            'cta' => $plan->code === Plan::CODE_ENTERPRISE ? 'contact' : 'register',
            'register_url' => route('register'),
        ];
    }
}
