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
use App\Services\Billing\BillingProfile;
use App\Services\Billing\PaymentMethods;
use App\Services\Billing\SubscriptionLifecycle;
use App\Support\CurrentOrganization;
use App\Support\TaxId;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plano e cobrança (ROUTES §2.15, DESIGN §6.11 aba "Plano e cobrança").
 *
 * Três coisas que esta tela **não** faz, de propósito:
 *
 * - não mostra cartão salvo: o Checkout Pro não guarda meio de pagamento e não devolve
 *   dado de cartão (RECONCILIACAO Q21). O card "Forma de pagamento" é **somente
 *   leitura** e descreve o meio do último pagamento aprovado — `last_four` é sempre
 *   `null` porque esse dado simplesmente não existe do nosso lado;
 * - não emite nota fiscal: o "PDF" da lista é recibo interno (Fase 2 trata NFS-e);
 * - não decide nada a partir do retorno do checkout: `checkout_return` é só um aviso
 *   "estamos confirmando seu pagamento".
 *
 * O uso do ciclo vem dos contadores da assinatura, que são mantidos pelo ledger
 * `plan_consumptions` (reserva no envio, confirmação na conclusão, liberação na falha).
 */
class BillingController extends Controller
{
    public function __construct(private readonly SubscriptionLifecycle $lifecycle) {}

    public function index(Request $request): Response
    {
        $organization = CurrentOrganization::instance()->get();
        Gate::authorize('manageBilling', $organization);

        $subscription = $organization->currentSubscription()->with('plan')->first();
        $plan = $subscription?->plan;

        /*
         * A coluna de data da tabela mostra `paid_at ?? created_at`; ordenar por `id` deixaria
         * as datas visíveis fora de ordem (um pendente de agosto acima de um pago de setembro).
         * A ordenação segue exatamente o valor exibido. `COALESCE` existe em MySQL e SQLite.
         */
        $payments = Payment::query()
            ->with('plan')
            ->orderByRaw('COALESCE(paid_at, created_at) DESC')
            ->orderByDesc('id')
            ->paginate(12)
            ->withQueryString();

        $lastApproved = Payment::query()
            ->where('status', PaymentStatus::Approved->value)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->first();

        $payments->through(fn (Payment $payment): array => [
            'id' => $payment->ulid,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
            'description' => 'Plano '.($payment->plan->name ?? '—'),
            'amount_cents' => (int) $payment->amount_cents,
            'status' => $payment->status->value,
            'display_status' => $payment->status->displayStatus()->value,
            'status_label' => $payment->status->label(),
            // O recibo só existe para pagamento efetivamente pago.
            'receipt_url' => $payment->isPaid()
                ? route('billing.payments.receipt', ['payment' => $payment->ulid])
                : null,
            'mp_payment_id' => $payment->provider_payment_id,
        ]);

        $checkoutReturn = $request->session()->get('checkout_return');
        $checkoutError = $request->session()->get('checkout_error');

        return Inertia::render('settings/billing', [
            'subscription' => $this->subscriptionPayload($subscription, $plan),
            'usage' => [
                'envelopes' => [
                    // O que conta para a cota é o consumo confirmado + o reservado (em
                    // trânsito): é isso que o ledger desconta do plano.
                    'used' => (int) (($subscription->envelopes_used ?? 0) + ($subscription->envelopes_reserved ?? 0)),
                    'limit' => $plan?->envelope_quota,
                ],
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
                'type' => PaymentMethods::type($lastApproved->payment_method_id),
                'label' => PaymentMethods::label($lastApproved->payment_method_id),
                // Não existe: o Checkout Pro não devolve dado de cartão e nada é guardado.
                'last_four' => null,
            ] : null,
            'billing_profile' => BillingProfile::for($organization),
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
                'cancel' => $request->user()->can('delete', $organization)
                    && $plan !== null
                    && ! $plan->isFree()
                    && ! (bool) ($subscription->cancel_at_period_end ?? false),
            ],
            'pending_checkout' => Payment::query()
                ->where('status', PaymentStatus::Pending->value)
                ->where('created_at', '>=', now()->subDay())
                ->exists(),
            'checkout_return' => is_string($checkoutReturn) ? $checkoutReturn : null,
            'checkout_error' => is_string($checkoutError) ? $checkoutError : null,
        ]);
    }

    /**
     * Cancelar a renovação (rota com `org.role:owner` e `password.confirm`).
     *
     * Não corta o acesso: o plano vale até o fim do ciclo já pago. A volta ao Grátis
     * acontece na expiração, pelo comando agendado.
     */
    public function cancel(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();
        Gate::authorize('delete', $organization);

        $subscription = $this->lifecycle->currentFor($organization);

        if ($subscription === null || $subscription->plan->isFree()) {
            return back()->with('info', 'Sua organização já está no plano Grátis — não há renovação para cancelar.');
        }

        if ($subscription->cancel_at_period_end) {
            return back()->with('info', 'A renovação deste plano já está cancelada.');
        }

        $this->lifecycle->cancelAtPeriodEnd($subscription);

        $end = $subscription->current_period_end?->timezone($organization->timezone)?->format('d/m/Y');

        return back()->with('success', $end !== null
            ? "Renovação cancelada. O plano continua ativo até {$end}."
            : 'Renovação cancelada. O plano continua ativo até o fim do ciclo atual.');
    }

    public function resume(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();
        Gate::authorize('manageBilling', $organization);

        $subscription = $this->lifecycle->currentFor($organization);

        if ($subscription === null || ! $subscription->cancel_at_period_end) {
            return back()->with('info', 'A renovação deste plano já está ativa.');
        }

        $this->lifecycle->resume($subscription);

        return back()->with('success', 'Renovação reativada. O plano segue sendo cobrado a cada ciclo.');
    }

    /**
     * Dados de faturamento. Razão social e documento vão para as colunas da organização
     * (o documento é criptografado); endereço, cidade, UF, CEP e e-mail ficam em
     * `organizations.settings.billing_profile`.
     */
    public function updateProfile(Request $request): RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();
        Gate::authorize('manageBilling', $organization);

        $validated = $request->validate([
            'legal_name' => ['required', 'string', 'max:160'],
            'document_number' => ['required', 'string', 'max:20', function (string $attribute, mixed $value, callable $fail): void {
                if (! TaxId::isValid(is_string($value) ? $value : null)) {
                    $fail('Informe um CPF ou CNPJ válido.');
                }
            }],
            'address_line' => ['required', 'string', 'max:200'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', 'string', 'size:2', 'alpha'],
            'postal_code' => ['required', 'string', 'max:9'],
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

        if (strlen(TaxId::digits($validated['postal_code'])) !== 8) {
            return back()->withErrors(['postal_code' => 'O CEP precisa ter 8 dígitos.'])->withInput();
        }

        BillingProfile::store($organization, [
            'legal_name' => $validated['legal_name'],
            'document_number' => $validated['document_number'],
            'address_line' => $validated['address_line'],
            'city' => $validated['city'],
            'state' => $validated['state'],
            'postal_code' => $validated['postal_code'],
            'email' => $validated['email'],
        ]);

        return back()->with('success', 'Dados de faturamento salvos. Eles aparecem nos próximos recibos.');
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
                'description' => $plan->description ?? null,
                'price_cents_monthly' => $plan ? ($yearly ? (int) round($plan->price_cents / 12) : (int) $plan->price_cents) : 0,
                'price_cents_yearly' => $plan && $yearly ? (int) $plan->price_cents : null,
                'features' => PlanController::featureLabels($plan),
                // Preço fictício: a interface precisa saber para não anunciá-lo como oferta.
                'is_sandbox' => (bool) ($plan->is_sandbox ?? false),
                'is_public' => (bool) ($plan->is_public ?? true),
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
}
