<?php

namespace App\Http\Controllers\Affiliates;

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
use App\Services\Affiliates\PayoutBatches;
use App\Support\Csv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Portal do afiliado `/afiliados` (Fase 3 §3.10). Qualquer conta verificada pode se candidatar;
 * a aprovação é da operadora. Mostra código, link, indicados (só o NOME da organização, datas
 * e estado) e comissões por estado. Dados de repasse sempre mascarados. Flag desligada: 404.
 */
class AffiliatePortalController extends Controller
{
    public function __construct(
        private readonly AffiliateProgram $program,
        private readonly AffiliateSettings $settings,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless(AffiliatesFeature::enabled(), 404);

        /** @var User $user */
        $user = $request->user();
        $affiliate = $this->affiliateOf($user);

        if ($affiliate !== null && $affiliate->isApproved()) {
            $this->program->touchIp($affiliate, $request->ip());
        }

        $status = $request->query('status');
        $status = is_string($status) && in_array($status, Commission::STATUSES, true) ? $status : null;

        $referrals = [];
        $commissions = null;

        if ($affiliate !== null) {
            $referrals = AffiliatePresenter::referralRows(
                Referral::query()->where('affiliate_id', $affiliate->getKey())->orderByDesc('attributed_at')->limit(200)->get(),
            );

            $page = Commission::query()
                ->where('affiliate_id', $affiliate->getKey())
                ->when($status !== null, fn ($q) => $q->where('status', $status))
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString();

            /** @var list<Commission> $items */
            $items = $page->items();
            $commissions = AffiliatePresenter::paginated($page, AffiliatePresenter::commissionRows($items));
        }

        return Inertia::render('affiliates/index', [
            'program' => AffiliatePresenter::program($this->settings),
            'affiliate' => $affiliate !== null ? AffiliatePresenter::affiliate($affiliate) : null,
            'totals' => $affiliate !== null ? AffiliatePresenter::totals((int) $affiliate->getKey()) : [],
            'referrals' => $referrals,
            'commissions' => $commissions,
            'filters' => ['status' => $status ?? 'all'],
        ]);
    }

    public function apply(Request $request): RedirectResponse
    {
        abort_unless(AffiliatesFeature::enabled(), 404);

        $request->validate(['terms' => ['accepted']], [], ['terms' => 'termos do programa']);

        /** @var User $user */
        $user = $request->user();

        $this->program->apply($user, $request->only(['pix_key_type', 'pix_key', 'holder_name', 'holder_tax_id']), $request->ip());

        return back()->with('success', 'Candidatura enviada. A equipe AssinaVelox vai analisar e avisar por aqui.');
    }

    public function updatePayout(Request $request): RedirectResponse
    {
        abort_unless(AffiliatesFeature::enabled(), 404);

        /** @var User $user */
        $user = $request->user();
        $affiliate = $this->affiliateOf($user);
        abort_if($affiliate === null || $affiliate->status === Affiliate::STATUS_REJECTED, 404);

        $this->program->updatePayoutDetails($affiliate, $user, $request->only(['pix_key_type', 'pix_key', 'holder_name', 'holder_tax_id']));

        return back()->with('success', 'Dados de repasse atualizados.');
    }

    /**
     * Revisão humana de uma decisão automática (indicação barrada ou segurada) — LGPD art. 20.
     */
    public function requestReview(Request $request, Referral $referral): RedirectResponse
    {
        abort_unless(AffiliatesFeature::enabled(), 404);

        /** @var User $user */
        $user = $request->user();
        $affiliate = $this->affiliateOf($user);
        abort_if($affiliate === null || (int) $referral->affiliate_id !== (int) $affiliate->getKey(), 404);

        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:500'],
        ], [], ['message' => 'mensagem']);

        $updated = DB::transaction(function () use ($referral, $user, $affiliate, $validated): bool {
            $changed = Referral::query()
                ->whereKey($referral->getKey())
                ->whereIn('status', [Referral::STATUS_HELD, Referral::STATUS_REJECTED])
                ->whereNull('review_requested_at')
                ->whereNull('reviewed_at')
                ->update(['review_requested_at' => Carbon::now(), 'updated_at' => Carbon::now()]) > 0;

            if ($changed) {
                AffiliateTrail::record(AffiliateTrail::REFERRAL_REVIEW_REQUESTED, $user, $affiliate, $referral, payload: [
                    'message' => isset($validated['message']) ? trim((string) $validated['message']) : null,
                ]);
            }

            return $changed;
        });

        return $updated
            ? back()->with('success', 'Revisão solicitada. Uma pessoa da equipe vai analisar a indicação.')
            : back()->with('warning', 'Esta indicação não aceita pedido de revisão.');
    }

    /**
     * Relatório do afiliado (roadmap §3.10: "relatório de afiliado exportável"). CSV com
     * proteção contra fórmula.
     */
    public function export(Request $request): StreamedResponse
    {
        abort_unless(AffiliatesFeature::enabled(), 404);

        /** @var User $user */
        $user = $request->user();
        $affiliate = $this->affiliateOf($user);
        abort_if($affiliate === null, 404);

        $rows = AffiliatePresenter::commissionRows(
            Commission::query()->where('affiliate_id', $affiliate->getKey())->orderBy('id')->get(),
        );

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            fwrite($out, Csv::BOM);
            fputcsv($out, Csv::row(['Lançamento', 'Data', 'Organização', 'Tipo', 'Base', 'Taxa (%)', 'Valor', 'Moeda', 'Estado', 'Motivo', 'Disponível em', 'Pago em', 'Lote']), ';');

            foreach ($rows as $row) {
                fputcsv($out, Csv::row([
                    $row['id'],
                    $row['created_at'],
                    $row['organization_name'],
                    $row['kind_label'],
                    PayoutBatches::decimal((int) $row['base_amount_cents']),
                    number_format(((int) $row['rate_bp']) / 100, 2, ',', ''),
                    PayoutBatches::decimal((int) $row['amount_cents']),
                    $row['currency'],
                    $row['status_label'],
                    $row['reason_label'],
                    $row['available_at'],
                    $row['paid_at'],
                    $row['payout_batch_id'],
                ]), ';');
            }

            fclose($out);
        }, 'comissoes-afiliado.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function affiliateOf(User $user): ?Affiliate
    {
        return Affiliate::query()->where('user_id', $user->getKey())->first();
    }
}
