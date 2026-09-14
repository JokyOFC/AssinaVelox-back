<?php

namespace App\Services\Risk;

use App\Models\Organization;
use App\Models\RiskReview;
use App\Models\User;
use App\Services\AdminLog\PlatformAction;
use App\Services\AdminLog\PlatformTrail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pedido de revisão feito pela própria organização (LGPD art. 20; e-notariado-e-regulatorio
 * §5). Canal registrado: o pedido fica no caso (`risk_reviews.appeal_*`) e na trilha
 * `platform_audit_events` (`risk.review_requested`), com autor e data.
 *
 * Só existe quando há algo a revisar (estado `watch` ou `restricted`). Se não houver caso
 * aberto (ex.: restrição confirmada antes), abre um novo caso com `trigger = appeal`.
 */
final class RiskAppeals
{
    public function request(Organization $organization, User $user, string $message): RiskReview
    {
        $message = trim($message);

        return DB::transaction(function () use ($organization, $user, $message): RiskReview {
            /** @var Organization $locked */
            $locked = Organization::withTrashed()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $current = RiskStatus::fromStored($locked->getAttribute('risk_status'));

            if ($current === RiskStatus::Normal) {
                throw RiskException::appealNotApplicable();
            }

            $review = RiskAssessment::openReviewFor($locked, $current, RiskAssessment::baseline((int) $locked->getKey()), RiskReview::TRIGGER_APPEAL);

            if ($review->appeal_requested_at !== null) {
                throw RiskException::appealAlreadyRequested();
            }

            $review->forceFill([
                'appeal_requested_at' => Carbon::now(),
                'appeal_requested_by_user_id' => $user->getKey(),
                'appeal_message' => mb_substr($message, 0, max(1, (int) config('assinavelox.risk.appeal.max_message', 2000))),
            ])->save();

            PlatformTrail::record(
                PlatformAction::RiskReviewRequested,
                $user,
                PlatformTrail::TARGET_ORGANIZATION,
                (int) $locked->getKey(),
                (int) $locked->getKey(),
                ['review' => $review->ulid, 'status' => $current->value],
            );

            return $review;
        });
    }
}
