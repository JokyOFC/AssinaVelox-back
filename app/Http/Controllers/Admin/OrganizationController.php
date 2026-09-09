<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EnvelopeStatus;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\PaymentStatus;
use App\Enums\PlanBillingPeriod;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\MembershipResource;
use App\Http\Resources\UserRefResource;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Csv;
use App\Support\OrganizationSettings;
use App\Support\TaxId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Painel interno › Clientes (ROUTES §1.5 / §2.20). Somente leitura; NÃO passa por `org`:
 * toda consulta a models escopados usa withoutOrganizationScope()/forOrganization().
 */
class OrganizationController extends Controller
{
    private const TABS = ['all', 'active', 'trialing', 'past_due', 'canceled'];

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        $query = $this->baseQuery($filters);
        $tabs = $this->tabCounts($filters);

        $perPage = min(50, max(10, (int) $request->integer('per_page', 25)));

        $organizations = $query->latest('organizations.created_at')->paginate($perPage)->withQueryString();

        $organizations->through(fn (Organization $organization): array => $this->row($organization));

        return Inertia::render('admin/organizations/index', [
            'filters' => $filters,
            'kpis' => $this->kpis(),
            'tabs' => $tabs,
            'customers' => [
                'data' => $organizations->items(),
                'links' => [
                    'first' => $organizations->url(1),
                    'last' => $organizations->url($organizations->lastPage()),
                    'prev' => $organizations->previousPageUrl(),
                    'next' => $organizations->nextPageUrl(),
                ],
                'meta' => [
                    'current_page' => $organizations->currentPage(),
                    'from' => $organizations->firstItem(),
                    'to' => $organizations->lastItem(),
                    'last_page' => $organizations->lastPage(),
                    'per_page' => $organizations->perPage(),
                    'total' => $organizations->total(),
                    'path' => $organizations->path(),
                    'links' => $organizations->linkCollection()->toArray(),
                ],
            ],
        ]);
    }

    public function show(Request $request, Organization $organization): Response
    {
        $organization->load(['currentSubscription.plan', 'creator']);
        $subscription = $organization->currentSubscription;
        $plan = $subscription?->plan;
        $settings = OrganizationSettings::of($organization);

        $envelopes = Envelope::forOrganization($organization);
        $members = Membership::query()->with('user')->where('organization_id', $organization->getKey())->get();

        $storage = (int) DocumentVersion::forOrganization($organization)->sum('size_bytes');

        return Inertia::render('admin/organizations/show', [
            'customer' => [
                'id' => $organization->ulid,
                'public_id' => 'acc_'.$organization->getKey(),
                'name' => $organization->name,
                'legal_name' => $organization->legal_name,
                'initials' => $organization->initials,
                'tax_id_masked' => TaxId::mask($organization->tax_id),
                'contact_email' => $settings->contactEmail() ?? $organization->creator?->email,
                'timezone' => $organization->timezone,
                'created_at' => $organization->created_at?->toIso8601String(),
                'last_seen_at' => $this->lastSeenAt($organization),
                'deletion_requested_at' => $settings->deletionRequestedAt()?->toIso8601String(),
            ],
            'kpis' => [
                'envelopes_total' => (clone $envelopes)->count(),
                'envelopes_completed' => (clone $envelopes)->where('status', EnvelopeStatus::Completed->value)->count(),
                'envelopes_in_progress' => (clone $envelopes)->where('status', EnvelopeStatus::InProgress->value)->count(),
                'members_active' => $members->where('status', MembershipStatus::Active)->count(),
                'storage_used_bytes' => $storage,
                'mrr_cents' => $this->mrrFor($subscription),
            ],
            'subscription' => $this->subscriptionPayload($subscription, $plan),
            'usage' => [
                'envelopes' => ['used' => (int) ($subscription->envelopes_used ?? 0), 'limit' => $plan?->envelope_quota],
                'members' => ['used' => $members->where('status', MembershipStatus::Active)->count(), 'limit' => $plan?->user_quota],
                'storage' => ['used_bytes' => $storage, 'limit_bytes' => $this->storageLimit($plan)],
            ],
            'members' => MembershipResource::collection($members->sortByDesc(fn (Membership $m) => $m->role->weight())->values())->resolve($request),
            'payments' => Payment::forOrganization($organization)
                ->latest()
                ->limit(24)
                ->get()
                ->map(fn (Payment $payment): array => $this->paymentRow($payment))
                ->values()
                ->all(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $query = $this->baseQuery($filters)->latest('organizations.created_at');
        $filename = 'clientes-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['ID', 'Nome', 'Plano', 'Status da assinatura', 'Membros', 'Documentos no ciclo', 'MRR (R$)', 'Proprietário', 'E-mail do proprietário', 'Criada em'], ';');

            $query->chunk(200, function ($organizations) use ($out): void {
                foreach ($organizations as $organization) {
                    $row = $this->row($organization);
                    fputcsv($out, Csv::row([
                        $row['public_id'],
                        $row['name'],
                        $row['plan']['name'],
                        $row['status_label'],
                        $row['members']['used'].($row['members']['limit'] !== null ? '/'.$row['members']['limit'] : ''),
                        $row['envelopes_cycle']['used'].($row['envelopes_cycle']['limit'] !== null ? '/'.$row['envelopes_cycle']['limit'] : ''),
                        number_format($row['mrr_cents'] / 100, 2, ',', '.'),
                        $row['owner']['name'],
                        $row['owner']['email'],
                        $row['created_at'] ? Carbon::parse($row['created_at'])->format('d/m/Y') : '',
                    ]), ';');
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{status: string, plan: string|null, created_from: string|null, created_to: string|null, q: string}
     */
    protected function filters(Request $request): array
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(self::TABS)],
            'plan' => ['nullable', 'string', Rule::exists('plans', 'code')],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date', 'after_or_equal:created_from'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        return [
            'status' => $validated['status'] ?? 'all',
            'plan' => $validated['plan'] ?? null,
            'created_from' => $validated['created_from'] ?? null,
            'created_to' => $validated['created_to'] ?? null,
            'q' => trim((string) ($validated['q'] ?? '')),
        ];
    }

    /**
     * @param  array{status: string, plan: string|null, created_from: string|null, created_to: string|null, q: string}  $filters
     * @return Builder<Organization>
     */
    protected function baseQuery(array $filters, bool $applyStatus = true): Builder
    {
        $query = Organization::query()
            ->with(['currentSubscription.plan', 'creator'])
            ->withCount([
                'memberships as active_members_count' => fn ($q) => $q->where('status', MembershipStatus::Active->value),
            ]);

        if ($filters['q'] !== '') {
            $like = '%'.$filters['q'].'%';
            $query->where(function (Builder $q) use ($like, $filters): void {
                $q->where('name', 'like', $like)
                    ->orWhere('legal_name', 'like', $like)
                    ->orWhereHas('memberships', fn ($m) => $m
                        ->where('role', MembershipRole::Owner->value)
                        ->whereHas('user', fn ($u) => $u->where('email', 'like', $like)->orWhere('name', 'like', $like)));

                if (preg_match('/^acc_(\d+)$/i', $filters['q'], $matches)) {
                    $q->orWhere('id', (int) $matches[1]);
                }
            });
        }

        if ($filters['plan'] !== null) {
            $query->whereHas('currentSubscription.plan', fn ($p) => $p->where('code', $filters['plan']));
        }

        if ($filters['created_from'] !== null) {
            $query->where('organizations.created_at', '>=', Carbon::parse($filters['created_from'])->startOfDay());
        }

        if ($filters['created_to'] !== null) {
            $query->where('organizations.created_at', '<=', Carbon::parse($filters['created_to'])->endOfDay());
        }

        if ($applyStatus && $filters['status'] !== 'all') {
            $this->applyStatusFilter($query, $filters['status']);
        }

        return $query;
    }

    /**
     * @param  Builder<Organization>  $query
     */
    protected function applyStatusFilter(Builder $query, string $status): void
    {
        match ($status) {
            'active' => $query->whereHas('currentSubscription', fn ($s) => $s->where('status', SubscriptionStatus::Active->value)),
            'trialing' => $query->whereHas('currentSubscription', fn ($s) => $s->where('status', SubscriptionStatus::Trialing->value)),
            'past_due' => $query->whereHas('currentSubscription', fn ($s) => $s->where('status', SubscriptionStatus::PastDue->value)),
            'canceled' => $query->whereDoesntHave('currentSubscription'),
            default => null,
        };
    }

    /**
     * @param  array{status: string, plan: string|null, created_from: string|null, created_to: string|null, q: string}  $filters
     * @return array<string, int>
     */
    protected function tabCounts(array $filters): array
    {
        $counts = [];

        foreach (self::TABS as $tab) {
            $query = $this->baseQuery($filters, applyStatus: false);

            if ($tab !== 'all') {
                $this->applyStatusFilter($query, $tab);
            }

            $counts[$tab] = $query->count();
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    protected function kpis(): array
    {
        $now = now();
        $activeSubscriptions = Subscription::withoutOrganizationScope()
            ->with('plan')
            ->where('status', SubscriptionStatus::Active->value)
            ->get();

        $mrr = $activeSubscriptions->sum(fn (Subscription $subscription): int => $this->mrrFor($subscription));

        $today = Envelope::withoutOrganizationScope()
            ->whereBetween('created_at', [$now->startOfDay(), $now->endOfDay()])
            ->get(['created_at']);

        $peak = $today->groupBy(fn (Envelope $e) => (int) $e->created_at?->format('G'))->map->count()->sortDesc();

        $trialsExpiring = Subscription::withoutOrganizationScope()
            ->where('status', SubscriptionStatus::Trialing->value)
            ->whereBetween('current_period_end', [$now, $now->addDays(7)])
            ->get();

        $pastDue = Subscription::withoutOrganizationScope()
            ->with('plan')
            ->where('status', SubscriptionStatus::PastDue->value)
            ->get();

        return [
            'active_accounts' => [
                'value' => Organization::query()->whereHas('currentSubscription', fn ($s) => $s->where('status', SubscriptionStatus::Active->value))->count(),
                'new_this_month' => Organization::query()->where('created_at', '>=', $now->startOfMonth())->count(),
            ],
            'mrr_cents' => ['value' => (int) $mrr, 'delta_pct' => null],
            'envelopes_today' => [
                'value' => $today->count(),
                'peak_hour' => $peak->isNotEmpty() ? (int) $peak->keys()->first() : null,
                'peak_count' => $peak->isNotEmpty() ? (int) $peak->first() : null,
            ],
            'trials_expiring_7d' => [
                'value' => $trialsExpiring->count(),
                'without_envelope' => $trialsExpiring->filter(fn (Subscription $s) => ! Envelope::forOrganization($s->organization_id)->exists())->count(),
            ],
            'past_due' => [
                'value' => $pastDue->count(),
                'overdue_cents' => (int) $pastDue->sum(fn (Subscription $s) => (int) $s->plan->price_cents),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(Organization $organization): array
    {
        $subscription = $organization->currentSubscription;
        $plan = $subscription?->plan;
        $owner = $organization->memberships()
            ->with('user')
            ->where('role', MembershipRole::Owner->value)
            ->orderBy('id')
            ->first()->user ?? $organization->creator;
        $used = (int) ($subscription->envelopes_used ?? 0);
        $limit = $plan?->envelope_quota;
        $status = $subscription->status ?? SubscriptionStatus::Canceled;

        return [
            'id' => $organization->ulid,
            'public_id' => 'acc_'.$organization->getKey(),
            'name' => $organization->name,
            'initials' => $organization->initials,
            'plan' => ['key' => $plan->code ?? 'free', 'code' => $plan->code ?? 'free', 'name' => $plan->name ?? 'Grátis'],
            'members' => ['used' => (int) ($organization->active_members_count ?? 0), 'limit' => $plan?->user_quota],
            'envelopes_cycle' => [
                'used' => $used,
                'limit' => $limit,
                'pct' => $limit ? (int) round(min(100, $used / max(1, $limit) * 100)) : null,
            ],
            'mrr_cents' => $this->mrrFor($subscription),
            'subscription_status' => $status->value,
            'status_label' => $status->label(),
            'last_seen_at' => $this->lastSeenAt($organization),
            'created_at' => $organization->created_at?->toIso8601String(),
            'owner' => UserRefResource::ref($owner, withEmail: true) ?? ['id' => '', 'name' => '—', 'initials' => '—', 'email' => ''],
        ];
    }

    protected function mrrFor(?Subscription $subscription): int
    {
        $plan = $subscription?->plan;

        if ($subscription === null || $plan === null || $subscription->status !== SubscriptionStatus::Active) {
            return 0;
        }

        return $plan->billing_period === PlanBillingPeriod::Yearly
            ? (int) round($plan->price_cents / 12)
            : (int) $plan->price_cents;
    }

    protected function lastSeenAt(Organization $organization): ?string
    {
        return Envelope::forOrganization($organization)->max('updated_at')
            ? Carbon::parse(Envelope::forOrganization($organization)->max('updated_at'))->toIso8601String()
            : null;
    }

    protected function storageLimit(?Plan $plan): ?int
    {
        $features = $plan->features ?? [];

        return isset($features['storage_bytes']) ? (int) $features['storage_bytes'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function subscriptionPayload(?Subscription $subscription, ?Plan $plan): array
    {
        $status = $subscription->status ?? SubscriptionStatus::Canceled;

        return [
            'plan' => [
                'key' => $plan->code ?? 'free',
                'code' => $plan->code ?? 'free',
                'name' => $plan->name ?? 'Grátis',
                'price_cents_monthly' => $plan && $plan->billing_period === PlanBillingPeriod::Monthly ? (int) $plan->price_cents : (int) round(($plan->price_cents ?? 0) / 12),
                'price_cents_yearly' => $plan && $plan->billing_period === PlanBillingPeriod::Yearly ? (int) $plan->price_cents : null,
                'features' => array_keys(array_filter($plan->features ?? [], fn ($v) => $v === true)),
            ],
            'status' => $status->value,
            'status_label' => $status->label(),
            'interval' => $plan ? ($plan->billing_period === PlanBillingPeriod::Yearly ? 'yearly' : 'monthly') : null,
            'current_period_start' => $subscription?->current_period_start?->toIso8601String(),
            'current_period_end' => $subscription?->current_period_end?->toIso8601String(),
            'cancel_at_period_end' => (bool) ($subscription->cancel_at_period_end ?? false),
            'trial_ends_at' => $status === SubscriptionStatus::Trialing ? $subscription?->current_period_end?->toIso8601String() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function paymentRow(Payment $payment): array
    {
        return [
            'id' => $payment->ulid,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
            'description' => 'Plano '.($payment->plan->name ?? '—'),
            'amount_cents' => (int) $payment->amount_cents,
            'status' => $payment->status->value,
            'status_label' => $payment->status->label(),
            'receipt_url' => $payment->status === PaymentStatus::Approved ? null : null,
            'mp_payment_id' => $payment->provider_payment_id,
        ];
    }
}
