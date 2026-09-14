<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PayoutBatch;
use App\Models\User;
use App\Services\Affiliates\AffiliatePresenter;
use App\Services\Affiliates\AffiliateSettings;
use App\Services\Affiliates\AffiliatesFeature;
use App\Services\Affiliates\AffiliateTrail;
use App\Services\Affiliates\PayoutBatches;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Painel interno › Afiliados › Lotes de repasse (Fase 3 §3.10). **O sistema calcula, não
 * paga**: montar o lote não move dinheiro; "marcar como pago" só REGISTRA (quem, quando,
 * referência externa) um repasse feito fora da plataforma, com senha confirmada.
 */
class AffiliatePayoutController extends Controller
{
    public function __construct(
        private readonly PayoutBatches $batches,
        private readonly AffiliateSettings $settings,
    ) {}

    public function index(): Response
    {
        abort_unless(AffiliatesFeature::enabled(), 404);

        $page = PayoutBatch::query()->with(['createdBy:id,name', 'paidBy:id,name'])->orderByDesc('id')->paginate(25);

        /** @var list<PayoutBatch> $items */
        $items = $page->items();

        return Inertia::render('admin/affiliates/payouts/index', [
            'batches' => AffiliatePresenter::paginated($page, array_map(fn (PayoutBatch $b): array => $this->batchRow($b), $items)),
            'preview' => array_map(fn (string $currency): array => $this->batches->preview($currency, Carbon::now()), $this->settings->currencies()),
            'currencies' => $this->settings->currencies(),
            'min_payout_cents' => $this->settings->minPayoutCents(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(AffiliatesFeature::enabled(), 404);

        $validated = $request->validate([
            'currency' => ['required', Rule::in($this->settings->currencies())],
            'cutoff' => ['nullable', 'date', 'before_or_equal:today'],
        ], [], ['currency' => 'moeda', 'cutoff' => 'data de corte']);

        $cutoff = isset($validated['cutoff']) ? Carbon::parse((string) $validated['cutoff'])->endOfDay() : Carbon::now();

        $batch = $this->batches->build((string) $validated['currency'], $cutoff->min(Carbon::now()), $this->actor($request));

        return redirect()->route('admin.affiliates.payouts.show', $batch)->with('success', 'Lote montado. Faça o repasse fora da plataforma e depois registre o pagamento aqui.');
    }

    public function show(PayoutBatch $batch): Response
    {
        abort_unless(AffiliatesFeature::enabled(), 404);

        $batch->loadMissing(['createdBy:id,name', 'paidBy:id,name', 'canceledBy:id,name']);

        return Inertia::render('admin/affiliates/payouts/show', [
            'batch' => [
                ...$this->batchRow($batch),
                'notes' => $batch->notes,
                'canceled_by' => $batch->canceledBy?->name,
                'canceled_at' => $batch->canceled_at?->toIso8601String(),
                'marked_paid_at' => $batch->marked_paid_at?->toIso8601String(),
            ],
            'lines' => $this->batches->lines($batch),
            'trail' => AffiliateTrail::forBatch($batch),
        ]);
    }

    public function markPaid(Request $request, PayoutBatch $batch): RedirectResponse
    {
        abort_unless(AffiliatesFeature::enabled(), 404);

        $validated = $request->validate([
            'paid_at' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:'.$batch->created_at?->toDateString()],
            'external_reference' => ['required', 'string', 'min:3', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['paid_at' => 'data do repasse', 'external_reference' => 'referência externa', 'notes' => 'observação']);

        $this->batches->markPaid(
            $batch,
            $this->actor($request),
            Carbon::parse((string) $validated['paid_at'])->startOfDay(),
            trim((string) $validated['external_reference']),
            isset($validated['notes']) ? trim((string) $validated['notes']) : null,
        );

        return back()->with('success', 'Lote registrado como pago.');
    }

    public function cancel(Request $request, PayoutBatch $batch): RedirectResponse
    {
        abort_unless(AffiliatesFeature::enabled(), 404);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [], ['reason' => 'motivo']);

        $this->batches->cancel($batch, $this->actor($request), trim((string) $validated['reason']));

        return redirect()->route('admin.affiliates.payouts.index')->with('success', 'Lote cancelado. Os lançamentos voltam para o próximo lote.');
    }

    public function export(Request $request, PayoutBatch $batch): StreamedResponse
    {
        abort_unless(AffiliatesFeature::enabled(), 404);

        return $this->batches->csv($batch, $this->actor($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function batchRow(PayoutBatch $batch): array
    {
        return [
            'id' => $batch->ulid,
            'currency' => $batch->currency,
            'status' => $batch->status,
            'status_label' => PayoutBatch::statusLabel($batch->status),
            'cutoff_at' => $batch->cutoff_at->toIso8601String(),
            'total_cents' => $batch->total_cents,
            'affiliates_count' => $batch->affiliates_count,
            'entries_count' => $batch->entries_count,
            'created_by' => $batch->createdBy?->name,
            'created_at' => $batch->created_at?->toIso8601String(),
            'paid_by' => $batch->paidBy?->name,
            'paid_at' => $batch->paid_at?->toIso8601String(),
            'external_reference' => $batch->external_reference,
        ];
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
