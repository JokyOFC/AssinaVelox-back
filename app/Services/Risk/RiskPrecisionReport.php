<?php

namespace App\Services\Risk;

use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Relatório de precisão por regra (roadmap §3.7, "relatório mensal de precisão da regra").
 *
 * Para cada regra: sinais gravados no mês, casos decididos no mês em que a regra aparece
 * (confirmados, mantidos em observação, liberados) e casos ainda abertos. Precisão =
 * confirmados ÷ (confirmados + liberados); nula enquanto não houver decisão. Um caso com
 * duas regras conta para as duas — é o que se quer para ajustar cada limiar.
 */
final class RiskPrecisionReport
{
    /**
     * @return array{month: string, from: string, to: string, rows: list<array{rule: string, label: string, max_status: string, signals: int, confirmed: int, watching: int, cleared: int, open: int, precision: float|null}>, totals: array{signals: int, confirmed: int, watching: int, cleared: int, open: int}}
     */
    public function build(?string $month = null): array
    {
        $timezone = Organization::DEFAULT_TIMEZONE;
        $start = $month !== null && preg_match('/^\d{4}-\d{2}$/', $month) === 1
            ? CarbonImmutable::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00', $timezone)
            : null;
        $start = ($start instanceof CarbonImmutable ? $start : CarbonImmutable::now($timezone))->startOfMonth();
        $end = $start->endOfMonth();
        [$fromUtc, $toUtc] = [$start->utc(), $end->utc()];

        $signals = DB::table('risk_signals')
            ->whereBetween('occurred_at', [$fromUtc, $toUtc])
            ->selectRaw('rule_code, count(*) as total')
            ->groupBy('rule_code')
            ->pluck('total', 'rule_code');

        $decided = DB::table('risk_reviews as r')
            ->join('risk_signals as s', function (JoinClause $join): void {
                $join->on('s.organization_id', '=', 'r.organization_id')
                    ->on('s.id', '>', 'r.after_signal_id')
                    ->on('s.id', '<=', 'r.through_signal_id');
            })
            ->whereNotNull('r.decided_at')
            ->whereBetween('r.decided_at', [$fromUtc, $toUtc])
            ->selectRaw('s.rule_code as rule_code, r.status as status, count(distinct r.id) as cases')
            ->groupBy('s.rule_code', 'r.status')
            ->get();

        $open = DB::table('risk_reviews as r')
            ->join('risk_signals as s', function (JoinClause $join): void {
                $join->on('s.organization_id', '=', 'r.organization_id')
                    ->on('s.id', '>', 'r.after_signal_id');
            })
            ->where('r.status', RiskReviewStatus::Open->value)
            ->selectRaw('s.rule_code as rule_code, count(distinct r.id) as cases')
            ->groupBy('s.rule_code')
            ->pluck('cases', 'rule_code');

        $rows = [];
        $totals = ['signals' => 0, 'confirmed' => 0, 'watching' => 0, 'cleared' => 0, 'open' => 0];

        foreach (RiskRule::cases() as $rule) {
            $count = fn (RiskReviewStatus $status): int => (int) ($decided
                ->first(fn (object $row): bool => $row->rule_code === $rule->value && $row->status === $status->value)
                ->cases ?? 0);

            $row = [
                'rule' => $rule->value,
                'label' => $rule->label(),
                'max_status' => $rule->maxStatus()->value,
                'signals' => (int) ($signals[$rule->value] ?? 0),
                'confirmed' => $count(RiskReviewStatus::Confirmed),
                'watching' => $count(RiskReviewStatus::Watching),
                'cleared' => $count(RiskReviewStatus::Cleared),
                'open' => (int) ($open[$rule->value] ?? 0),
                'precision' => null,
            ];

            $judged = $row['confirmed'] + $row['cleared'];
            $row['precision'] = $judged > 0 ? round($row['confirmed'] / $judged, 4) : null;

            foreach (array_keys($totals) as $key) {
                $totals[$key] += $row[$key];
            }

            $rows[] = $row;
        }

        return [
            'month' => $start->format('Y-m'),
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'rows' => $rows,
            'totals' => $totals,
        ];
    }
}
