<?php

namespace App\Http\Controllers\Billing;

use App\Enums\PlanBillingPeriod;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\Plans\PlanFeatures;
use App\Support\CurrentOrganization;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Escolha de planos (ROUTES §2.16), a partir da tabela `plans`.
 *
 * ## Honestidade sobre o catálogo
 *
 * O `PlanSeeder` marca **todos** os planos pagos como `is_sandbox = true` e
 * `is_public = false`: os preços são placeholders de desenvolvimento, não uma oferta
 * comercial. Duas consequências que este controller aplica:
 *
 * 1. **Visibilidade.** Em produção só entram planos ativos **e** públicos. Planos
 *    sandbox aparecem apenas em `local`/`testing`, onde alguém está desenvolvendo.
 * 2. **Rotulagem.** Todo plano vai para a interface com `is_sandbox` e o sinônimo
 *    explícito `price_is_placeholder` — é o que faz a tela marcar o card como sandbox e
 *    escrever que o valor é fictício, em vez de anunciá-lo como oferta real.
 */
class PlanController extends Controller
{
    /** @var array<string, string> */
    private const FEATURE_LABELS = [
        'email_otp' => 'Código por e-mail',
        'evidence_page' => 'Página de evidências',
        'company_signature' => 'Assinatura criptográfica da operadora',
        'folders' => 'Pastas',
        'priority_support' => 'Suporte prioritário',
        'api' => 'API e webhooks',
        'templates' => 'Modelos',
        'sms_whatsapp' => 'SMS e WhatsApp',
        'branding' => 'Logo da empresa',
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
            ->where(fn (Builder $query) => $query
                ->where('is_public', true)
                ->when(app()->environment(['local', 'testing']), fn (Builder $inner) => $inner->orWhere('is_sandbox', true)))
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Plan $plan): array => $this->payload($plan, $currentCode, $currentSort))
            ->values()
            ->all();

        $checkoutError = $request->session()->get('checkout_error');

        return Inertia::render('settings/plans', [
            'current_plan' => $currentCode,
            'interval' => $interval,
            'plans' => $plans,
            'checkout_error' => is_string($checkoutError) ? $checkoutError : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Plan $plan, string $currentCode, int $currentSort): array
    {
        $yearly = $plan->billing_period === PlanBillingPeriod::Yearly;
        $sandbox = (bool) $plan->is_sandbox;

        return [
            'key' => $plan->code,
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->description,
            'price_cents_monthly' => $yearly ? (int) round($plan->price_cents / 12) : (int) $plan->price_cents,
            'price_cents_yearly' => $yearly ? (int) $plan->price_cents : null,
            'features' => self::featureLabels($plan),
            'limits' => [
                'envelopes_per_month' => $plan->envelope_quota,
                'members' => $plan->user_quota,
                'storage_bytes' => isset($plan->features['storage_bytes']) ? (int) $plan->features['storage_bytes'] : null,
            ],
            'is_sandbox' => $sandbox,
            // Sinônimo explícito: uma interface que não conheça `is_sandbox` ainda
            // entende que o preço não é oferta.
            'price_is_placeholder' => $sandbox && ! $plan->isFree(),
            'is_public' => (bool) $plan->is_public,
            'highlighted' => $plan->code === Plan::CODE_PROFESSIONAL,
            'cta' => match (true) {
                $plan->code === $currentCode => 'current',
                $plan->code === Plan::CODE_ENTERPRISE => 'contact',
                $plan->sort_order > $currentSort => 'upgrade',
                default => 'downgrade',
            },
        ];
    }

    /**
     * @return list<string>
     */
    public static function featureLabels(?Plan $plan): array
    {
        $labels = [];

        // A assinatura criptográfica da operadora só é anunciada quando a instalação tem um
        // certificado ativo. Sem ele, nenhum plano entrega o item — e listá-lo venderia, ao
        // cliente do plano pago, algo que o próprio produto lhe nega na tela do documento
        // ("Nenhum certificado da operadora estava ativo na finalização"). É a mesma regra
        // que arquitetura.md §2 impõe ao restante da interface, aplicada ao discurso
        // comercial. Havendo certificado, a flag do plano volta a valer — e ela governa de
        // verdade: `EnvelopeFinalizer` consulta `PlanFeatures::allows()` antes de assinar.
        $offersCompanySignature = app(PlanFeatures::class)->isOffered();

        foreach ((array) ($plan->features ?? []) as $key => $enabled) {
            if ($key === PlanFeatures::COMPANY_SIGNATURE && ! $offersCompanySignature) {
                continue;
            }

            if ($enabled === true && isset(self::FEATURE_LABELS[$key]) && ! in_array(self::FEATURE_LABELS[$key], $labels, true)) {
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
