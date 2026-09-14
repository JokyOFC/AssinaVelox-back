<?php

namespace App\Services\Risk;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\RiskReview;
use App\Models\RiskSignal;
use App\Models\User;
use App\Services\AdminLog\PlatformAction;
use App\Services\AdminLog\PlatformTrail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Decisão humana sobre um caso (roadmap §3.7): liberar, manter em observação ou confirmar a
 * restrição. Sempre com motivo (mínimo de 10 caracteres) e autor; registrada em
 * `platform_audit_events` (`risk.review_decided`) e, se o estado mudar, também como
 * `risk.status_changed` com origem `review`.
 *
 * A decisão fixa o intervalo de sinais do caso (`through_signal_id`): sinais liberados não
 * voltam a contar. Nenhuma decisão toca envelopes, aceites ou evidências — nem mesmo
 * "confirmar restrição", cujo efeito continua sendo só o bloqueio de NOVOS envios.
 */
final class RiskReviewDecisions
{
    public const MIN_REASON = 10;

    public function __construct(private readonly RiskTransitions $transitions) {}

    /**
     * @param  int|null  $seenThroughSignalId  último sinal que o revisor tinha na tela (0 = nenhum);
     *                                         null só em chamadas internas, sem tela
     */
    public function decide(RiskReview $review, RiskDecision $decision, string $reason, User $reviewer, ?int $seenThroughSignalId = null): RiskReview
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON) {
            throw RiskException::reasonRequired();
        }

        if (self::hasConflict($reviewer, (int) $review->organization_id)) {
            throw RiskException::conflictOfInterest();
        }

        [$decided, $organization, $changed] = DB::transaction(function () use ($review, $decision, $reason, $reviewer, $seenThroughSignalId): array {
            /** @var Organization $organization */
            $organization = Organization::withTrashed()->whereKey($review->organization_id)->lockForUpdate()->firstOrFail();

            /** @var RiskReview $locked */
            $locked = RiskReview::query()->whereKey($review->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw RiskException::alreadyDecided();
            }

            // A decisão só cobre o que o revisor viu: sinal novo desde a tela ⇒ recarregar
            // (revisão adversarial I-3A; "toda decisão tem regra + evidência + revisor").
            if ($seenThroughSignalId !== null && $locked->signalsQuery()->where('id', '>', $seenThroughSignalId)->exists()) {
                throw RiskException::caseChanged();
            }

            $from = RiskStatus::fromStored($organization->getAttribute('risk_status'));
            $through = (int) RiskSignal::query()->where('organization_id', $organization->getKey())->max('id');

            $locked->forceFill([
                'status' => $decision->reviewStatus(),
                'decision' => $decision,
                'decision_reason' => mb_substr($reason, 0, 2000),
                'reviewer_id' => $reviewer->getKey(),
                'decided_at' => Carbon::now(),
                'through_signal_id' => max($through, $locked->after_signal_id),
            ])->save();

            $rules = array_values($locked->signalsQuery()->distinct()->pluck('rule_code')->map(fn ($code): string => (string) $code)->sort()->all());
            $to = $decision->resultingStatus();

            $changed = $this->transitions->apply($organization, $to, 'review', $reviewer, $locked, $rules);

            PlatformTrail::record(
                PlatformAction::RiskReviewDecided,
                $reviewer,
                PlatformTrail::TARGET_ORGANIZATION,
                (int) $organization->getKey(),
                (int) $organization->getKey(),
                [
                    'review' => $locked->ulid,
                    'decision' => $decision->value,
                    'reason' => mb_substr($reason, 0, 500),
                    'from' => $from->value,
                    'to' => $to->value,
                    'rules' => $rules,
                    'appeal' => $locked->appeal_requested_at !== null,
                ],
            );

            return [$locked, $organization, $changed];
        });

        if ($changed && $decision === RiskDecision::Confirm) {
            $this->transitions->notifyRestricted($organization);
        } else {
            $this->transitions->notifyDecision($organization, $decision);
        }

        return $decided;
    }

    /**
     * Separação de interesse (revisão adversarial I-3A): quem tem vínculo ativo com a
     * organização do caso — dono, administrador ou operador — não é o revisor humano dela.
     */
    public static function hasConflict(User $reviewer, int $organizationId): bool
    {
        return Membership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $reviewer->getKey())
            ->where('status', MembershipStatus::Active->value)
            ->exists();
    }
}
