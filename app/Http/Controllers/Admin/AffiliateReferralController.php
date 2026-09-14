<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\User;
use App\Services\Affiliates\AffiliatesFeature;
use App\Services\Affiliates\AffiliateTrail;
use App\Services\Affiliates\CommissionLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Revisão HUMANA de uma indicação (LGPD art. 20): toda decisão automática (autoindicação,
 * possível conta duplicada) pode ser confirmada ou desfeita por uma pessoa, com motivo e trilha.
 *
 * - `release`: indicação passa a `active`; comissões de pagamentos já aprovados são calculadas
 *   na hora (e as seguradas seguem o prazo normal de estorno).
 * - `reject`: indicação passa a `rejected`; pendentes são revertidas e aprovadas/pagas ganham
 *   estorno negativo no próximo lote. Nada fora do programa é tocado.
 */
class AffiliateReferralController extends Controller
{
    public function review(Request $request, Referral $referral, CommissionLedger $ledger): RedirectResponse
    {
        abort_unless(AffiliatesFeature::enabled(), 404);

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['release', 'reject'])],
            'note' => ['required', 'string', 'min:5', 'max:500'],
        ], [], ['decision' => 'decisão', 'note' => 'justificativa']);

        /** @var User $actor */
        $actor = $request->user();
        $to = $validated['decision'] === 'release' ? Referral::STATUS_ACTIVE : Referral::STATUS_REJECTED;
        $note = trim((string) $validated['note']);

        DB::transaction(function () use ($referral, $actor, $to, $note): void {
            $locked = Referral::query()->whereKey($referral->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === $to) {
                throw ValidationException::withMessages(['decision' => 'A indicação já está nesse estado.']);
            }

            $from = $locked->status;

            $locked->forceFill([
                'status' => $to,
                'reviewed_by_user_id' => $actor->getKey(),
                'reviewed_at' => Carbon::now(),
                'review_note' => $note,
            ])->save();

            AffiliateTrail::record(AffiliateTrail::REFERRAL_REVIEWED, $actor, $locked->affiliate, $locked, payload: [
                'from' => $from,
                'to' => $to,
                'note' => $note,
                'rules' => $locked->block_reasons ?? [],
            ]);

            $referral->setRawAttributes($locked->getAttributes(), true);
        });

        if ($to === Referral::STATUS_REJECTED) {
            $ledger->reverseForReferral($referral);
        } elseif ($referral->organization_id !== null) {
            $ledger->syncOrganization((int) $referral->organization_id);
        }

        return back()->with('success', $to === Referral::STATUS_ACTIVE ? 'Indicação liberada.' : 'Indicação mantida como não elegível.');
    }
}
