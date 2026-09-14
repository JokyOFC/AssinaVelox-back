<?php

namespace App\Services\Risk;

use App\Models\Organization;
use App\Models\RiskReview;
use App\Models\RiskSignal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Motor de estado de risco (roadmap §3.7). Transições AUTOMÁTICAS só sobem:
 *
 *     normal → watch        soma das pontuações pendentes ≥ `thresholds.watch`
 *     * → restricted       soma das regras que podem suspender o envio ≥ `thresholds.restrict`
 *                          (e `auto_restrict` ligado)
 *
 * "Pendentes" = sinais da organização posteriores ao último caso decidido e dentro de
 * `lookback_days`. Descer (liberar) é SEMPRE decisão humana (RiskReviewDecisions). Cada
 * mudança abre (ou reaproveita) um caso na fila e fica em `platform_audit_events` com as
 * regras que a explicam. Organizações da lista de confiança nunca mudam sozinhas.
 */
final class RiskAssessment
{
    public function __construct(private readonly RiskTransitions $transitions) {}

    public function evaluate(Organization $organization): ?RiskStatus
    {
        if (! RiskFeature::enabled() || $this->isTrusted($organization)) {
            return null;
        }

        $result = DB::transaction(function () use ($organization): ?array {
            /** @var Organization|null $locked */
            $locked = Organization::withTrashed()->whereKey($organization->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return null;
            }

            $current = RiskStatus::fromStored($locked->getAttribute('risk_status'));
            $baseline = self::baseline((int) $locked->getKey());

            $signals = RiskSignal::query()
                ->where('organization_id', $locked->getKey())
                ->where('id', '>', $baseline)
                ->where('occurred_at', '>=', Carbon::now()->subDays(max(1, (int) config('assinavelox.risk.lookback_days', 30))))
                ->get(['id', 'rule_code', 'score']);

            $total = 0;
            $restrictable = 0;
            $rules = [];

            foreach ($signals as $signal) {
                $rule = RiskRule::tryFrom($signal->rule_code);

                if ($rule === null) {
                    continue;
                }

                $total += $signal->score;
                $rules[$rule->value] = true;

                if ($rule->maxStatus() === RiskStatus::Restricted) {
                    $restrictable += $signal->score;
                }
            }

            $target = RiskStatus::Normal;

            if ($total >= (int) config('assinavelox.risk.thresholds.watch', 30)) {
                $target = RiskStatus::Watch;
            }

            if (RiskFeature::autoRestrict() && $restrictable >= (int) config('assinavelox.risk.thresholds.restrict', 70)) {
                $target = RiskStatus::Restricted;
            }

            if ($target === RiskStatus::Normal) {
                return null;
            }

            $review = self::openReviewFor($locked, $current, $baseline, RiskReview::TRIGGER_SIGNALS);

            if ($target->rank() <= $current->rank()) {
                return null;
            }

            $this->transitions->apply($locked, $target, 'automatic', null, $review, array_keys($rules), [
                'score_total' => $total,
                'score_restrictable' => $restrictable,
            ]);

            if ($target === RiskStatus::Restricted) {
                $review->forceFill(['restricted_at' => Carbon::now()])->save();
            }

            return ['organization' => $locked, 'status' => $target];
        });

        if ($result === null) {
            return null;
        }

        if ($result['status'] === RiskStatus::Restricted) {
            $this->transitions->notifyRestricted($result['organization']);
        }

        return $result['status'];
    }

    /**
     * Último sinal já coberto por um caso decidido (0 = nenhum).
     */
    public static function baseline(int $organizationId): int
    {
        $open = RiskReview::query()
            ->where('organization_id', $organizationId)
            ->where('status', RiskReviewStatus::Open->value)
            ->latest('id')
            ->value('after_signal_id');

        if ($open !== null) {
            return (int) $open;
        }

        return (int) RiskReview::query()
            ->where('organization_id', $organizationId)
            ->whereNotNull('through_signal_id')
            ->max('through_signal_id');
    }

    /**
     * Caso aberto da organização, criado se não houver. Chamar com a organização travada.
     */
    public static function openReviewFor(Organization $organization, RiskStatus $current, int $baseline, string $trigger): RiskReview
    {
        /** @var RiskReview|null $open */
        $open = RiskReview::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', RiskReviewStatus::Open->value)
            ->latest('id')
            ->first();

        if ($open !== null) {
            return $open;
        }

        return RiskReview::query()->create([
            'organization_id' => $organization->getKey(),
            'status' => RiskReviewStatus::Open,
            'trigger' => $trigger,
            'after_signal_id' => $baseline,
            'status_before' => $current->value,
            'opened_at' => Carbon::now(),
        ]);
    }

    private function isTrusted(Organization $organization): bool
    {
        $trusted = config('assinavelox.risk.trusted_organizations', []);

        return is_array($trusted) && in_array($organization->ulid, $trusted, true);
    }
}
