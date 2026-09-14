<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformAuditEvent;
use App\Models\RiskReview;
use App\Models\RiskSignal;
use App\Models\User;
use App\Services\AdminLog\OrganizationAuditLog;
use App\Services\AdminLog\PlatformAction;
use App\Services\Risk\RiskDecision;
use App\Services\Risk\RiskEvidence;
use App\Services\Risk\RiskException;
use App\Services\Risk\RiskFeature;
use App\Services\Risk\RiskReviewDecisions;
use App\Services\Risk\RiskReviewStatus;
use App\Services\Risk\RiskRule;
use App\Services\Risk\RiskStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Painel interno › Antifraude — fila de revisão humana (Fase 3 §3.7, P3-RISK). Flag
 * `antifraud` (desligada: 404). Só platform admin (middleware `platform-admin` no grupo).
 *
 * Mostra a regra e a evidência minimizada de cada sinal, o histórico de estados e o pedido de
 * revisão da organização. A decisão (liberar, manter em observação, confirmar restrição)
 * exige motivo e fica em `platform_audit_events` com o autor.
 */
class RiskReviewController extends Controller
{
    private const STATUS_FILTERS = ['open', 'watching', 'cleared', 'confirmed', 'all'];

    public function index(Request $request): Response
    {
        abort_unless(RiskFeature::enabled(), 404);

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUS_FILTERS)],
            'appeal' => ['nullable', 'boolean'],
        ]);

        $status = $validated['status'] ?? 'open';

        $query = RiskReview::query()->with(['organization:id,ulid,name,risk_status']);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if (($validated['appeal'] ?? false) == true) {
            $query->whereNotNull('appeal_requested_at');
        }

        $page = $query->orderByRaw('appeal_requested_at is null')
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        /** @var list<RiskReview> $items */
        $items = $page->items();

        return Inertia::render('admin/risk/index', [
            'filters' => ['status' => $status, 'appeal' => (bool) ($validated['appeal'] ?? false)],
            'statuses' => [...RiskReviewStatus::options(), ['value' => 'all', 'label' => 'Todos']],
            'counts' => [
                'open' => RiskReview::query()->where('status', RiskReviewStatus::Open->value)->count(),
                'appeals' => RiskReview::query()->where('status', RiskReviewStatus::Open->value)->whereNotNull('appeal_requested_at')->count(),
            ],
            'reviews' => [
                'data' => array_map(fn (RiskReview $review): array => $this->summary($review), $items),
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
            ],
        ]);
    }

    public function show(Request $request, RiskReview $review): Response
    {
        abort_unless(RiskFeature::enabled(), 404);

        /** @var User $viewer */
        $viewer = $request->user();
        $conflict = RiskReviewDecisions::hasConflict($viewer, (int) $review->organization_id);

        $review->loadMissing(['organization', 'reviewer:id,name', 'appealRequester:id,name']);
        $organization = $review->organization;

        $signals = $review->signalsQuery()->with('envelope:id,ulid')->orderByDesc('id')->limit(200)->get();

        $history = PlatformAuditEvent::query()
            ->with('actor:id,name')
            ->where('organization_id', $review->organization_id)
            ->whereIn('action', [
                PlatformAction::RiskStatusChanged->value,
                PlatformAction::RiskReviewDecided->value,
                PlatformAction::RiskReviewRequested->value,
            ])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return Inertia::render('admin/risk/show', [
            'review' => [
                ...$this->summary($review),
                'decision' => $review->decision?->value,
                'decision_label' => $review->decision?->label(),
                'decision_reason' => $review->decision_reason,
                'reviewer' => $review->reviewer?->name,
                'decided_at' => $review->decided_at?->toIso8601String(),
                'restricted_at' => $review->restricted_at?->toIso8601String(),
                'status_before' => $review->status_before,
                'appeal' => $review->appeal_requested_at !== null ? [
                    'requested_at' => $review->appeal_requested_at->toIso8601String(),
                    'requested_by' => $review->appealRequester->name ?? '—',
                    'message' => (string) $review->appeal_message,
                ] : null,
            ],
            'organization' => [
                'id' => $organization->ulid,
                'name' => $organization->name,
                'created_at' => $organization->created_at?->toIso8601String(),
                'risk_status' => RiskStatus::fromStored($organization->getAttribute('risk_status'))->value,
                'risk_status_label' => RiskStatus::fromStored($organization->getAttribute('risk_status'))->label(),
                'risk_status_changed_at' => $this->iso($organization->getAttribute('risk_status_changed_at')),
            ],
            'signals' => $signals->map(fn (RiskSignal $signal): array => [
                'id' => $signal->ulid,
                'rule' => $signal->rule_code,
                'label' => $signal->rule()?->label() ?? $signal->rule_code,
                'explanation' => $signal->rule()?->publicExplanation(),
                'can_restrict' => $signal->rule()?->maxStatus() === RiskStatus::Restricted,
                'score' => $signal->score,
                'occurred_at' => $signal->occurred_at->toIso8601String(),
                'envelope' => $signal->envelope?->ulid,
                'evidence' => RiskEvidence::present($signal->evidence),
            ])->values()->all(),
            'history' => $history->map(fn (PlatformAuditEvent $event): array => [
                'id' => $event->ulid,
                'occurred_at' => $event->occurred_at->toIso8601String(),
                'action' => $event->action->value,
                'label' => $event->action->label(),
                'actor' => $event->actor->name ?? 'Sistema (automático)',
                'details' => $this->historyDetails($event->payload ?? []),
                'ip' => OrganizationAuditLog::maskIp($event->ip_address),
            ])->values()->all(),
            'decisions' => RiskDecision::options(),
            'can_decide' => $review->isOpen() && ! $conflict,
            'conflict_of_interest' => $review->isOpen() && $conflict,
            // A decisão só cobre os sinais até este (o mais recente exibido).
            'seen_through' => $signals->first()?->ulid,
            'min_reason' => RiskReviewDecisions::MIN_REASON,
        ]);
    }

    public function decide(Request $request, RiskReview $review, RiskReviewDecisions $decisions): RedirectResponse
    {
        abort_unless(RiskFeature::enabled(), 404);

        $validated = $request->validate([
            'decision' => ['required', Rule::enum(RiskDecision::class)],
            'reason' => ['required', 'string', 'min:'.RiskReviewDecisions::MIN_REASON, 'max:2000'],
            // Último sinal que estava na tela (ULID; vazio quando o caso não tinha sinais).
            'seen_through' => ['present', 'nullable', 'string', 'max:26'],
        ], [
            'seen_through.present' => 'Recarregue a página do caso antes de decidir.',
        ], ['decision' => 'decisão', 'reason' => 'motivo']);

        /** @var User $reviewer */
        $reviewer = $request->user();

        if (RiskReviewDecisions::hasConflict($reviewer, (int) $review->organization_id)) {
            abort(403, RiskException::conflictOfInterest()->getMessage());
        }

        $seen = 0;

        if (is_string($validated['seen_through'] ?? null) && $validated['seen_through'] !== '') {
            $seen = RiskSignal::query()
                ->where('organization_id', $review->organization_id)
                ->where('ulid', $validated['seen_through'])
                ->value('id');

            if ($seen === null) {
                throw ValidationException::withMessages(['seen_through' => 'Recarregue a página do caso antes de decidir.']);
            }
        }

        try {
            $decided = $decisions->decide($review, RiskDecision::from((string) $validated['decision']), (string) $validated['reason'], $reviewer, (int) $seen);
        } catch (RiskException $exception) {
            if ($exception->errorCode === 'reason_required') {
                throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
            }

            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.risk.show', $decided)
            ->with('success', 'Decisão registrada: '.mb_strtolower((string) $decided->decision?->label()).'.');
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(RiskReview $review): array
    {
        $rules = $review->signalsQuery()->selectRaw('rule_code, count(*) as total, sum(score) as score')->groupBy('rule_code')->get();
        $organization = $review->organization;

        return [
            'id' => $review->ulid,
            'status' => $review->status->value,
            'status_label' => $review->status->label(),
            'trigger' => $review->trigger,
            'opened_at' => $review->opened_at->toIso8601String(),
            'appeal_requested_at' => $review->appeal_requested_at?->toIso8601String(),
            'organization' => [
                'id' => $organization->ulid,
                'name' => $organization->name,
                'risk_status' => RiskStatus::fromStored($organization->getAttribute('risk_status'))->value,
            ],
            'rules' => $rules->map(fn ($row): array => [
                'rule' => (string) $row->getAttribute('rule_code'),
                'label' => RiskRule::tryFrom((string) $row->getAttribute('rule_code'))?->label() ?? (string) $row->getAttribute('rule_code'),
                'signals' => (int) $row->getAttribute('total'),
            ])->values()->all(),
            'score' => (int) $rules->sum(fn ($row): int => (int) $row->getAttribute('score')),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{label: string, value: string}>
     */
    private function historyDetails(array $payload): array
    {
        $labels = [
            'from' => 'De',
            'to' => 'Para',
            'origin' => 'Origem',
            'decision' => 'Decisão',
            'reason' => 'Motivo',
            'rules' => 'Regras',
            'status' => 'Estado',
            'score_total' => 'Pontuação',
        ];

        $rows = [];

        foreach ($labels as $key => $label) {
            if (! array_key_exists($key, $payload) || $payload[$key] === null || $payload[$key] === []) {
                continue;
            }

            $value = $payload[$key];

            $rows[] = ['label' => $label, 'value' => match ($key) {
                'from', 'to', 'status' => RiskStatus::fromStored($value)->label(),
                'origin' => $value === 'automatic' ? 'Automática (regras)' : 'Revisão humana',
                'decision' => RiskDecision::tryFrom((string) $value)?->label() ?? (string) $value,
                'rules' => implode(', ', array_map(
                    fn ($code): string => RiskRule::tryFrom((string) $code)?->label() ?? (string) $code,
                    is_array($value) ? $value : [$value],
                )),
                default => is_scalar($value) ? (string) $value : '',
            }];
        }

        return $rows;
    }

    private function iso(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return is_string($value) && $value !== '' ? Carbon::parse($value)->toIso8601String() : null;
    }
}
