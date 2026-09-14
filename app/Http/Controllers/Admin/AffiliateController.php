<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\Commission;
use App\Models\Referral;
use App\Models\User;
use App\Services\Affiliates\AffiliatePresenter;
use App\Services\Affiliates\AffiliateProgram;
use App\Services\Affiliates\AffiliateSettings;
use App\Services\Affiliates\AffiliatesFeature;
use App\Services\Affiliates\AffiliateTrail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Painel interno › Afiliados (Fase 3 §3.10). Aprovação de candidaturas, taxa por afiliado
 * (com trilha: antes → depois, motivo, quem), suspensão e fila de revisão humana das indicações
 * barradas ou seguradas pelas regras automáticas (LGPD art. 20). Flag desligada: 404.
 */
class AffiliateController extends Controller
{
    private const STATUSES = ['all', Affiliate::STATUS_PENDING, Affiliate::STATUS_APPROVED, Affiliate::STATUS_SUSPENDED, Affiliate::STATUS_REJECTED];

    public function __construct(
        private readonly AffiliateProgram $program,
        private readonly AffiliateSettings $settings,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless(AffiliatesFeature::enabled(), 404);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
        ]);

        $filters = ['q' => trim((string) ($validated['q'] ?? '')), 'status' => $validated['status'] ?? 'all'];

        $query = Affiliate::query()->with('user:id,name,email');

        if ($filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if ($filters['q'] !== '') {
            $like = '%'.$filters['q'].'%';
            $query->where(fn (Builder $q) => $q->where('code', 'like', $like)
                ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like)));
        }

        $page = $query->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->orderByDesc('id')->paginate(25)->withQueryString();

        /** @var list<Affiliate> $items */
        $items = $page->items();
        $ids = array_map(fn (Affiliate $a): int => (int) $a->getKey(), $items);

        $referralCounts = Referral::query()->whereIn('affiliate_id', $ids)->selectRaw('affiliate_id, COUNT(*) as n')->groupBy('affiliate_id')->pluck('n', 'affiliate_id');
        $sums = DB::table('commissions')->whereIn('affiliate_id', $ids)
            ->selectRaw('affiliate_id, status, SUM(amount_cents) as total')->groupBy('affiliate_id', 'status')->get()
            ->groupBy('affiliate_id');

        $rows = array_map(function (Affiliate $a) use ($referralCounts, $sums): array {
            $byStatus = collect($sums->get($a->getKey(), []))->mapWithKeys(fn ($row): array => [(string) $row->status => (int) $row->total]);

            return [
                'id' => $a->ulid,
                'name' => (string) ($a->user->name ?? 'Conta excluída'),
                'email' => $a->user?->email,
                'code' => $a->code,
                'status' => $a->status,
                'status_label' => Affiliate::statusLabel($a->status),
                'commission_rate_bp' => $a->commission_rate_bp,
                'referrals_count' => (int) ($referralCounts[$a->getKey()] ?? 0),
                'pending_cents' => (int) ($byStatus[Commission::STATUS_PENDING] ?? 0),
                'approved_cents' => (int) ($byStatus[Commission::STATUS_APPROVED] ?? 0),
                'paid_cents' => (int) ($byStatus[Commission::STATUS_PAID] ?? 0),
                'has_payout_details' => $a->hasPayoutDetails(),
                'applied_at' => $a->terms_accepted_at->toIso8601String(),
            ];
        }, $items);

        $reviewQueue = Referral::query()
            ->with('affiliate.user:id,name')
            ->where(fn (Builder $q) => $q->where('status', Referral::STATUS_HELD)
                ->orWhere(fn (Builder $r) => $r->whereNotNull('review_requested_at')->whereNull('reviewed_at')))
            ->orderByRaw('CASE WHEN review_requested_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('attributed_at')
            ->limit(100)
            ->get();

        return Inertia::render('admin/affiliates/index', [
            'filters' => $filters,
            'summary' => [
                'pending' => Affiliate::query()->where('status', Affiliate::STATUS_PENDING)->count(),
                'approved' => Affiliate::query()->where('status', Affiliate::STATUS_APPROVED)->count(),
                'suspended' => Affiliate::query()->where('status', Affiliate::STATUS_SUSPENDED)->count(),
                'review_queue' => $reviewQueue->count(),
            ],
            'totals' => AffiliatePresenter::totals(),
            'affiliates' => AffiliatePresenter::paginated($page, $rows),
            'review_queue' => AffiliatePresenter::referralRows($reviewQueue, true),
            'program' => AffiliatePresenter::program($this->settings),
        ]);
    }

    public function show(Affiliate $affiliate): Response
    {
        abort_unless(AffiliatesFeature::enabled(), 404);

        $affiliate->loadMissing('user:id,name,email');

        return Inertia::render('admin/affiliates/show', [
            'affiliate' => [
                ...AffiliatePresenter::affiliate($affiliate),
                'name' => (string) ($affiliate->user->name ?? 'Conta excluída'),
                'email' => $affiliate->user?->email,
                'has_payout_details' => $affiliate->hasPayoutDetails(),
            ],
            'totals' => AffiliatePresenter::totals((int) $affiliate->getKey()),
            'referrals' => AffiliatePresenter::referralRows(
                Referral::query()->with('affiliate.user:id,name')->where('affiliate_id', $affiliate->getKey())->orderByDesc('attributed_at')->limit(200)->get(),
                true,
            ),
            'commissions' => AffiliatePresenter::commissionRows(
                Commission::query()->where('affiliate_id', $affiliate->getKey())->orderByDesc('id')->limit(100)->get(),
            ),
            'trail' => AffiliateTrail::forAffiliate($affiliate),
            'program' => AffiliatePresenter::program($this->settings),
        ]);
    }

    public function approve(Request $request, Affiliate $affiliate): RedirectResponse
    {
        abort_unless(AffiliatesFeature::enabled(), 404);

        $validated = $request->validate([
            'commission_rate_bp' => ['nullable', 'integer', 'min:0', 'max:'.$this->settings->maxRateBp()],
        ], [], ['commission_rate_bp' => 'taxa de comissão']);

        $this->program->approve($affiliate, $this->actor($request), isset($validated['commission_rate_bp']) ? (int) $validated['commission_rate_bp'] : null);

        return back()->with('success', 'Afiliado aprovado. Código '.$affiliate->code.'.');
    }

    public function reject(Request $request, Affiliate $affiliate): RedirectResponse
    {
        abort_unless(AffiliatesFeature::enabled(), 404);

        $this->program->reject($affiliate, $this->actor($request), $this->reason($request));

        return back()->with('success', 'Candidatura recusada.');
    }

    public function suspend(Request $request, Affiliate $affiliate): RedirectResponse
    {
        abort_unless(AffiliatesFeature::enabled(), 404);

        $this->program->suspend($affiliate, $this->actor($request), $this->reason($request));

        return back()->with('success', 'Afiliado suspenso. O link deixa de atribuir novas indicações.');
    }

    public function reactivate(Request $request, Affiliate $affiliate): RedirectResponse
    {
        abort_unless(AffiliatesFeature::enabled(), 404);

        $this->program->reactivate($affiliate, $this->actor($request), $this->reason($request));

        return back()->with('success', 'Afiliado reativado.');
    }

    public function updateRate(Request $request, Affiliate $affiliate): RedirectResponse
    {
        abort_unless(AffiliatesFeature::enabled(), 404);

        $validated = $request->validate([
            'commission_rate_bp' => ['required', 'integer', 'min:0', 'max:'.$this->settings->maxRateBp()],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [], ['commission_rate_bp' => 'taxa de comissão', 'reason' => 'motivo']);

        $this->program->changeRate($affiliate, $this->actor($request), (int) $validated['commission_rate_bp'], trim((string) $validated['reason']));

        return back()->with('success', 'Taxa alterada. Vale para os pagamentos aprovados daqui em diante.');
    }

    private function reason(Request $request): string
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [], ['reason' => 'motivo']);

        return trim((string) $validated['reason']);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
