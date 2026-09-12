<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentEnvironment;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Integrations\Fiscal\FiscalInvoiceProviderFactory;
use App\Integrations\Fiscal\SefinNacionalFiscalInvoiceProvider;
use App\Integrations\Payments\CheckoutProGateway;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentChargeback;
use App\Models\PaymentRefund;
use App\Models\ReconciliationItem;
use App\Models\ReconciliationRun;
use App\Models\Subscription;
use App\Services\Billing\AdminBillingReport;
use App\Services\Billing\BillingSettings;
use App\Services\Billing\PaymentMethodPolicy;
use App\Services\Billing\PaymentMethods;
use App\Services\Billing\RecurringBillingStatus;
use App\Services\Fiscal\FiscalFeature;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Painel interno › Planos e faturamento (`admin.billing.index`, Fase 2 onda D — roadmap §2.20).
 * Flag `extended_payments` desligada: o placeholder da Fase 1.
 *
 * **Somente leitura e sem acesso a documentos.** A página lê pagamentos, estornos,
 * contestações, inadimplência e divergências de conciliação de todas as organizações; nenhum
 * dado de envelope, arquivo, evidência ou signatário passa por aqui. As operações financeiras
 * (estorno, cancelamento, conciliar agora, consultar meios) são rotas POST separadas, com senha
 * confirmada onde movem dinheiro (`BillingActionController`).
 */
class BillingController extends Controller
{
    private const TABS = ['payments', 'refunds', 'chargebacks', 'past_due', 'divergences'];

    public function __construct(
        private readonly AdminBillingReport $report,
        private readonly BillingSettings $settings,
        private readonly CheckoutProGateway $gateway,
        private readonly PaymentMethodPolicy $methods,
        private readonly RecurringBillingStatus $recurring,
        private readonly FiscalInvoiceProviderFactory $fiscal,
    ) {}

    public function index(Request $request): Response
    {
        if (! $this->settings->extendedPayments()) {
            return PlaceholderController::render('admin.billing.index');
        }

        $validated = $request->validate([
            'tab' => ['nullable', Rule::in(self::TABS)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'environment' => ['nullable', Rule::in(['all', 'sandbox', 'production'])],
            'status' => ['nullable', Rule::in(array_map(static fn (PaymentStatus $status): string => $status->value, PaymentStatus::cases()))],
            'q' => ['nullable', 'string', 'max:120'],
            'divergences' => ['nullable', Rule::in(['open', 'all'])],
        ]);

        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : Carbon::now()->startOfMonth();
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : Carbon::now()->endOfDay();
        $environmentFilter = $validated['environment'] ?? $this->gateway->environment()->value;
        $environment = $environmentFilter === 'all' ? null : $environmentFilter;
        $tab = $validated['tab'] ?? 'payments';

        $filters = [
            'tab' => $tab,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'environment' => $environmentFilter,
            'status' => $validated['status'] ?? null,
            'q' => trim((string) ($validated['q'] ?? '')),
            'divergences' => $validated['divergences'] ?? 'open',
        ];

        $lastRun = ReconciliationRun::query()->latest('started_at')->latest('id')->first();
        $lastCheck = $this->methods->latestCheck($this->gateway->name(), $this->gateway->environment());

        return Inertia::render('admin/billing', [
            'filters' => $filters,
            'gateway' => [
                'name' => $this->gateway->name(),
                'is_fake' => $this->gateway->isFake(),
                'environment' => $this->gateway->environment()->value,
                'configured' => $this->gateway->isConfigured(),
            ],
            'revenue' => $this->report->revenue($from, $to, $environment),
            'counters' => $this->report->counters($environment),
            'rows' => $this->rows($tab, $from, $to, $environment, $filters),
            'reconciliation' => $lastRun === null ? null : [
                'id' => $lastRun->ulid,
                'status' => $lastRun->status,
                'status_label' => $lastRun->statusLabel(),
                'trigger' => $lastRun->trigger,
                'environment' => $lastRun->environment,
                'window_start' => $lastRun->window_start->toIso8601String(),
                'window_end' => $lastRun->window_end->toIso8601String(),
                'remote_count' => $lastRun->remote_count,
                'matched_count' => $lastRun->matched_count,
                'divergence_count' => $lastRun->divergence_count,
                'error' => $lastRun->error,
                'started_at' => $lastRun->started_at->toIso8601String(),
                'finished_at' => $lastRun->finished_at?->toIso8601String(),
            ],
            'methods' => [
                'families' => $this->methods->families($this->gateway->name(), $this->gateway->environment()),
                'checked_at' => $lastCheck?->checked_at->toIso8601String(),
                'offline_expiration_hours' => $this->settings->offlineExpirationHours(),
            ],
            'refund_policy' => [
                'owner_can_request' => $this->settings->ownerCanRequestRefund(),
                'owner_window_days' => $this->settings->ownerRefundWindowDays(),
                'max_age_days' => $this->settings->refundMaxAgeDays(),
            ],
            'recurring' => $this->recurring->summary(),
            'fiscal' => [
                'enabled' => FiscalFeature::enabled(),
                'mode' => $this->fiscal->mode(),
                'missing' => SefinNacionalFiscalInvoiceProvider::MISSING,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function rows(string $tab, Carbon $from, Carbon $to, ?string $environment, array $filters): array
    {
        return match ($tab) {
            'refunds' => $this->paginated($this->report->refundList($from, $to), fn (PaymentRefund $refund): array => [
                'id' => $refund->ulid,
                'payment_id' => $refund->payment->ulid,
                'provider_payment_id' => $refund->payment->provider_payment_id,
                'organization' => $this->organizationRef($refund->organization),
                'amount_cents' => (int) $refund->amount_cents,
                'currency' => $refund->currency,
                'kind' => $refund->kind,
                'status' => $refund->status,
                'status_label' => $refund->statusLabel(),
                'initiator' => $refund->initiator,
                'requested_by' => $refund->requestedBy?->name,
                'reason' => $refund->reason,
                'requested_at' => $refund->requested_at->toIso8601String(),
                'confirmed_at' => $refund->confirmed_at?->toIso8601String(),
            ]),
            'chargebacks' => $this->paginated($this->report->chargebackList(), fn (PaymentChargeback $chargeback): array => [
                'id' => $chargeback->ulid,
                'provider_chargeback_id' => $chargeback->provider_chargeback_id,
                'payment_id' => $chargeback->payment->ulid,
                'payment_status' => $chargeback->payment->status->value,
                'payment_status_detail' => $chargeback->payment->status_detail,
                'organization' => $this->organizationRef($chargeback->organization),
                'amount_cents' => (int) $chargeback->amount_cents,
                'currency' => $chargeback->currency,
                'reason' => $chargeback->reason,
                'outcome_label' => $chargeback->outcomeLabel(),
                'documentation_status' => $chargeback->documentation_status,
                'documentation_deadline_at' => $chargeback->documentation_deadline_at?->toIso8601String(),
                'received_at' => $chargeback->received_at->toIso8601String(),
            ]),
            'past_due' => $this->paginated($this->report->pastDueList(), fn (Subscription $subscription): array => [
                'id' => $subscription->ulid,
                'organization' => $this->organizationRef($subscription->organization),
                'plan' => $subscription->plan->name,
                'status_label' => $subscription->status->label(),
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                'days_overdue' => $subscription->current_period_end !== null && $subscription->current_period_end->isPast()
                    ? (int) $subscription->current_period_end->diffInDays(Carbon::now(), true)
                    : 0,
            ]),
            'divergences' => $this->paginated($this->report->divergenceList(($filters['divergences'] ?? 'open') === 'open'), fn (ReconciliationItem $item): array => [
                'id' => $item->getKey(),
                'run_id' => $item->run->ulid,
                'divergence' => $item->divergence,
                'divergence_label' => $item->divergenceLabel(),
                'organization' => $this->organizationRef($item->organization),
                'payment_id' => $item->payment?->ulid,
                'provider_payment_id' => $item->provider_payment_id,
                'local_status' => $item->local_status,
                'provider_status' => $item->provider_status,
                'local_amount_cents' => $item->local_amount_cents,
                'provider_amount_cents' => $item->provider_amount_cents,
                'local_currency' => $item->local_currency,
                'provider_currency' => $item->provider_currency,
                'note' => $item->note,
                'detected_at' => $item->created_at?->toIso8601String(),
                'resolved_at' => $item->resolved_at?->toIso8601String(),
                'resolution_note' => $item->resolution_note,
            ]),
            default => $this->paginated(
                $this->report->paymentList($from, $to, $environment, $filters['status'] ?? null, (string) $filters['q']),
                fn (Payment $payment): array => $this->paymentRow($payment),
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentRow(Payment $payment): array
    {
        $ours = $payment->provider === $this->gateway->name();
        $refundable = $ours
            && $payment->status === PaymentStatus::Approved
            && $payment->provider_payment_id !== null
            && $payment->refundableCents() > 0;

        return [
            'id' => $payment->ulid,
            'organization' => $this->organizationRef($payment->organization),
            'plan' => $payment->plan->name ?? '—',
            'amount_cents' => (int) $payment->amount_cents,
            'refunded_cents' => (int) $payment->refunded_cents,
            'refundable_cents' => $payment->refundableCents(),
            'currency' => $payment->currency,
            'status' => $payment->status->value,
            'status_label' => $payment->status->label(),
            'status_detail' => $payment->status_detail,
            'method_label' => $payment->payment_method_id !== null ? PaymentMethods::label($payment->payment_method_id) : null,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
            'expires_at' => $payment->expires_at?->toIso8601String(),
            'environment' => $payment->environment->value,
            'is_sandbox' => $payment->environment === PaymentEnvironment::Sandbox,
            'provider' => $payment->provider,
            'provider_payment_id' => $payment->provider_payment_id,
            'can' => [
                'refund' => $refundable,
                'cancel' => in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::InProcess, PaymentStatus::Authorized], true),
                'resync' => $ours && $payment->provider_payment_id !== null,
            ],
        ];
    }

    /**
     * Cliente da linha, só nome e ULID. Aceita nulo: uma organização pode deixar de existir
     * para a consulta (exclusão agendada), e a linha financeira continua legível.
     *
     * @return array{id: string, name: string}|null
     */
    private function organizationRef(?Organization $organization): ?array
    {
        return $organization === null ? null : ['id' => $organization->ulid, 'name' => $organization->name];
    }

    /**
     * @template TModel
     *
     * @param  LengthAwarePaginator<int, TModel>  $page
     * @param  callable(TModel): array<string, mixed>  $map
     * @return array<string, mixed>
     */
    private function paginated(LengthAwarePaginator $page, callable $map): array
    {
        return [
            'data' => array_map($map, $page->items()),
            'links' => [
                'first' => $page->url(1),
                'last' => $page->url($page->lastPage()),
                'prev' => $page->previousPageUrl(),
                'next' => $page->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $page->currentPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'path' => $page->path(),
                'links' => $page->linkCollection()->toArray(),
            ],
        ];
    }
}
