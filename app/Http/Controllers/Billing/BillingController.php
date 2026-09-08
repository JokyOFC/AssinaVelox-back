<?php

namespace App\Http\Controllers\Billing;

use App\Enums\MembershipStatus;
use App\Enums\PaymentStatus;
use App\Enums\PlanBillingPeriod;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\DocumentVersion;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plano e cobrança (ROUTES §2.15). Leitura real da assinatura/pagamentos existentes;
 * ações de cancelamento/perfil de faturamento // TODO(Wave B - cobrança).
 */
class BillingController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = CurrentOrganization::instance()->get();
        Gate::authorize('manageBilling', $organization);

        $subscription = $organization->currentSubscription()->with('plan')->first();
        $plan = $subscription?->plan;

        $payments = Payment::query()
            ->with('plan')
            ->latest()
            ->paginate(12)
            ->withQueryString();

        $lastApproved = Payment::query()->where('status', PaymentStatus::Approved->value)->latest('paid_at')->first();

        $payments->through(fn (Payment $payment): array => [
            'id' => $payment->ulid,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
            'description' => 'Plano '.($payment->plan->name ?? '—'),
            'amount_cents' => (int) $payment->amount_cents,
            'status' => $payment->status->value,
            'display_status' => $payment->status->displayStatus()->value,
            'status_label' => $payment->status->label(),
            'receipt_url' => $payment->status === PaymentStatus::Approved
                ? route('billing.payments.receipt', ['payment' => $payment->ulid])
                : null,
            'mp_payment_id' => $payment->provider_payment_id,
        ]);

        return Inertia::render('settings/billing', [
            'subscription' => $this->subscriptionPayload($subscription, $plan),
            'usage' => [
                'envelopes' => ['used' => (int) ($subscription->envelopes_used ?? 0), 'limit' => $plan?->envelope_quota],
                'members' => [
                    'used' => $organization->memberships()->where('status', MembershipStatus::Active->value)->count(),
                    'limit' => $plan?->user_quota,
                ],
                'storage' => [
                    'used_bytes' => (int) DocumentVersion::query()->sum('size_bytes'),
                    'limit_bytes' => isset($plan?->features['storage_bytes']) ? (int) $plan->features['storage_bytes'] : null,
                ],
            ],
            'payment_method' => $lastApproved ? [
                'type' => $this->paymentMethodType($lastApproved->payment_method_id),
                'label' => $this->paymentMethodLabel($lastApproved->payment_method_id),
                'last_four' => null,
            ] : null,
            'billing_profile' => null, // TODO(Wave B): dados de faturamento
            'payments' => [
                'data' => $payments->items(),
                'links' => [
                    'first' => $payments->url(1),
                    'last' => $payments->url($payments->lastPage()),
                    'prev' => $payments->previousPageUrl(),
                    'next' => $payments->nextPageUrl(),
                ],
                'meta' => [
                    'current_page' => $payments->currentPage(),
                    'from' => $payments->firstItem(),
                    'to' => $payments->lastItem(),
                    'last_page' => $payments->lastPage(),
                    'per_page' => $payments->perPage(),
                    'total' => $payments->total(),
                    'path' => $payments->path(),
                    'links' => $payments->linkCollection()->toArray(),
                ],
            ],
            'can' => [
                'manage' => true,
                'cancel' => $request->user()->can('delete', $organization) && $plan !== null && ! $plan->isFree(),
            ],
            'pending_checkout' => Payment::query()
                ->where('status', PaymentStatus::Pending->value)
                ->where('created_at', '>=', now()->subDay())
                ->exists(),
        ]);
    }

    public function cancel(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();
        Gate::authorize('delete', $organization);

        // TODO(Wave B): subscription.cancel_at_period_end = true + auditoria.
        return back()->with('info', 'O cancelamento da renovação estará disponível em breve.');
    }

    public function resume(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();
        Gate::authorize('manageBilling', $organization);

        // TODO(Wave B): cancel_at_period_end = false.
        return back()->with('info', 'A reativação da renovação estará disponível em breve.');
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();
        Gate::authorize('manageBilling', $organization);

        $request->validate([
            'legal_name' => ['required', 'string', 'max:160'],
            'document_number' => ['required', 'string', 'max:20'],
            'address_line' => ['required', 'string', 'max:200'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', 'string', 'size:2'],
            'postal_code' => ['required', 'digits:8'],
            'email' => ['required', 'email:rfc', 'max:255'],
        ], [], [
            'legal_name' => 'razão social',
            'document_number' => 'CPF/CNPJ',
            'address_line' => 'endereço',
            'city' => 'cidade',
            'state' => 'UF',
            'postal_code' => 'CEP',
            'email' => 'e-mail',
        ]);

        // TODO(Wave B): persistir dados de faturamento.
        return back()->with('info', 'Os dados de faturamento estarão disponíveis em breve.');
    }

    /**
     * @return array<string, mixed>
     */
    public static function subscriptionPayload(?Subscription $subscription, ?Plan $plan): array
    {
        $status = $subscription->status ?? SubscriptionStatus::Canceled;
        $yearly = $plan?->billing_period === PlanBillingPeriod::Yearly;

        return [
            'plan' => [
                'key' => $plan->code ?? 'free',
                'code' => $plan->code ?? 'free',
                'name' => $plan->name ?? 'Grátis',
                'price_cents_monthly' => $plan ? ($yearly ? (int) round($plan->price_cents / 12) : (int) $plan->price_cents) : 0,
                'price_cents_yearly' => $plan && $yearly ? (int) $plan->price_cents : null,
                'features' => PlanController::featureLabels($plan),
            ],
            'status' => $status->value,
            'status_label' => $status->label(),
            'interval' => $plan ? ($yearly ? 'yearly' : 'monthly') : null,
            'current_period_start' => $subscription?->current_period_start?->toIso8601String(),
            'current_period_end' => $subscription?->current_period_end?->toIso8601String(),
            'cancel_at_period_end' => (bool) ($subscription->cancel_at_period_end ?? false),
            'trial_ends_at' => $status === SubscriptionStatus::Trialing ? $subscription?->current_period_end?->toIso8601String() : null,
        ];
    }

    protected function paymentMethodType(?string $methodId): string
    {
        return match (true) {
            $methodId === null => 'other',
            $methodId === 'pix' => 'pix',
            in_array($methodId, ['bolbradesco', 'boleto', 'pec'], true) => 'boleto',
            $methodId === 'account_money' => 'account_money',
            in_array($methodId, ['debvisa', 'debmaster', 'debelo'], true) => 'debit_card',
            default => 'credit_card',
        };
    }

    protected function paymentMethodLabel(?string $methodId): string
    {
        return match ($this->paymentMethodType($methodId)) {
            'pix' => 'Pix',
            'boleto' => 'Boleto',
            'account_money' => 'Saldo Mercado Pago',
            'debit_card' => 'Cartão de débito',
            'credit_card' => 'Cartão de crédito'.($methodId ? ' ('.ucfirst($methodId).')' : ''),
            default => 'Outro',
        };
    }
}
