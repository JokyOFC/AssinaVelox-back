<?php

namespace App\Services\Reports;

use App\Enums\EnvelopeStatus;
use App\Enums\Permission;
use App\Enums\PlanConsumptionStatus;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\PlanConsumption;
use App\Models\Team;
use App\Models\User;
use App\Services\Organizations\EnvelopeVisibility;
use App\Services\Tags\EnvelopeTagIndex;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Relatório de documentos (Fase 2, roadmap §2.14).
 *
 * REGRA DE OURO: toda agregação parte de `EnvelopeVisibility::envelopes($membership)` — a
 * mesma consulta da lista de documentos. Um envelope que a pessoa não pode abrir nunca entra
 * em contagem, média, agrupamento por usuário/time, série diária nem CSV. Os filtros só
 * ESTREITAM esse conjunto.
 *
 * Um documento "entra no período" quando algum marco do ciclo cai dentro dele: enviado,
 * concluído, recusado, expirado ou cancelado. Cada indicador conta o seu marco:
 *  - enviados: `sent_at` no período;
 *  - concluídos / recusados / expirados / cancelados: o respectivo `*_at` no período;
 *  - tempo médio até concluir: média de `completed_at - sent_at` dos concluídos no período;
 *  - taxa de conclusão: dos enviados no período, quantos já estão concluídos.
 *
 * O cálculo é feito em PHP sobre as linhas do período (colunas mínimas, em lotes) para ser
 * idêntico em MySQL e SQLite; o período é limitado a 366 dias.
 */
final class EnvelopeReport
{
    private const MILESTONES = ['sent_at', 'completed_at', 'refused_at', 'expired_at', 'canceled_at'];

    public function __construct(
        private readonly Membership $membership,
        private readonly ReportFilters $filters,
    ) {}

    /**
     * Envelopes visíveis + filtros (sem o recorte de período).
     *
     * @return Builder<Envelope>
     */
    public function scoped(): Builder
    {
        $query = EnvelopeVisibility::envelopes($this->membership)
            ->where('envelopes.organization_id', $this->membership->organization_id);

        if ($this->filters->unresolved) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->filters->status !== null) {
            $query->where('envelopes.status', $this->filters->status->value);
        }

        if ($this->filters->folder !== null) {
            $query->where('envelopes.folder_id', $this->filters->folder->getKey());
        }

        if ($this->filters->creatorId !== null) {
            $query->where('envelopes.created_by_user_id', $this->filters->creatorId);
        }

        if ($this->filters->team !== null) {
            $query->whereIn('envelopes.created_by_user_id', $this->teamUserIdsQuery($this->filters->team));
        }

        if ($this->filters->tag !== null) {
            EnvelopeTagIndex::whereTagged($query, $this->filters->tag);
        }

        return $query;
    }

    /**
     * Envelopes com algum marco dentro do período.
     *
     * @return Builder<Envelope>
     */
    public function inPeriod(): Builder
    {
        $start = $this->filters->startUtc();
        $end = $this->filters->endUtc();

        return $this->scoped()->where(function (Builder $query) use ($start, $end): void {
            foreach (self::MILESTONES as $column) {
                $query->orWhereBetween('envelopes.'.$column, [$start, $end]);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $start = $this->filters->startUtc();
        $end = $this->filters->endUtc();
        $timezone = $this->filters->timezone;

        $totals = ['sent' => 0, 'completed' => 0, 'refused' => 0, 'expired' => 0, 'canceled' => 0, 'sent_completed' => 0, 'sent_pending' => 0];
        $durations = [];
        $byUser = [];
        $sentByDay = [];
        $completedByDay = [];

        $in = fn (?CarbonInterface $at): bool => $at !== null && $at->betweenIncluded($start, $end);

        $this->inPeriod()
            ->select(['envelopes.id', 'envelopes.created_by_user_id', 'envelopes.status', ...array_map(fn (string $c): string => 'envelopes.'.$c, self::MILESTONES)])
            ->orderBy('envelopes.id')
            ->chunk(500, function ($envelopes) use (&$totals, &$durations, &$byUser, &$sentByDay, &$completedByDay, $in, $timezone): void {
                foreach ($envelopes as $envelope) {
                    /** @var Envelope $envelope */
                    $userId = (int) $envelope->created_by_user_id;
                    $byUser[$userId] ??= ['sent' => 0, 'completed' => 0, 'closed_unsigned' => 0, 'durations' => []];

                    if ($in($envelope->sent_at)) {
                        $totals['sent']++;
                        $byUser[$userId]['sent']++;
                        $day = $envelope->sent_at->copy()->setTimezone($timezone)->format('Y-m-d');
                        $sentByDay[$day] = ($sentByDay[$day] ?? 0) + 1;

                        if ($envelope->status === EnvelopeStatus::Completed) {
                            $totals['sent_completed']++;
                        } elseif (in_array($envelope->status, [EnvelopeStatus::InProgress, EnvelopeStatus::Finalizing], true)) {
                            $totals['sent_pending']++;
                        }
                    }

                    if ($in($envelope->completed_at)) {
                        $totals['completed']++;
                        $byUser[$userId]['completed']++;
                        $day = $envelope->completed_at->copy()->setTimezone($timezone)->format('Y-m-d');
                        $completedByDay[$day] = ($completedByDay[$day] ?? 0) + 1;

                        if ($envelope->sent_at !== null && $envelope->completed_at->greaterThanOrEqualTo($envelope->sent_at)) {
                            $minutes = ($envelope->completed_at->getTimestamp() - $envelope->sent_at->getTimestamp()) / 60;
                            $durations[] = $minutes;
                            $byUser[$userId]['durations'][] = $minutes;
                        }
                    }

                    foreach (['refused', 'expired', 'canceled'] as $outcome) {
                        if ($in($envelope->{$outcome.'_at'})) {
                            $totals[$outcome]++;
                            $byUser[$userId]['closed_unsigned']++;
                        }
                    }
                }
            });

        return [
            'totals' => [
                'sent' => $totals['sent'],
                'completed' => $totals['completed'],
                'refused' => $totals['refused'],
                'expired' => $totals['expired'],
                'canceled' => $totals['canceled'],
                'pending' => $totals['sent_pending'],
                'avg_minutes_to_complete' => self::average($durations),
                'completion_rate' => $totals['sent'] > 0 ? round($totals['sent_completed'] / $totals['sent'] * 100, 1) : null,
            ],
            'series' => $this->series($sentByDay, $completedByDay),
            'by_user' => $this->byUser($byUser),
            'by_team' => $this->byTeam($byUser),
        ];
    }

    /**
     * Uso do plano no período — só para quem vê o faturamento (`manage_billing`). O consumo
     * é da organização inteira; para os demais a seção não existe (null).
     *
     * @return array<string, mixed>|null
     */
    public function planUsage(Organization $organization): ?array
    {
        if (! $this->membership->hasPermission(Permission::ManageBilling)) {
            return null;
        }

        $subscription = $organization->currentSubscription()->with('plan')->first();

        $committed = (int) PlanConsumption::forOrganization($organization)
            ->where('status', PlanConsumptionStatus::Committed->value)
            ->whereBetween('committed_at', [$this->filters->startUtc(), $this->filters->endUtc()])
            ->sum('quantity');

        return [
            'plan_name' => $subscription->plan->name ?? 'Grátis',
            'envelopes_committed_in_period' => $committed,
            'cycle_used' => (int) ($subscription->envelopes_used ?? 0),
            'cycle_limit' => $subscription?->plan?->envelope_quota,
            'cycle_end' => $subscription?->current_period_end?->toIso8601String(),
        ];
    }

    /**
     * Opções dos filtros — todas derivadas do que a pessoa já pode ver.
     *
     * @return array{folders: list<array{value: string, label: string}>, teams: list<array{value: string, label: string}>, creators: list<array{value: string, label: string}>}
     */
    public function filterOptions(): array
    {
        $visible = EnvelopeVisibility::envelopes($this->membership)
            ->where('envelopes.organization_id', $this->membership->organization_id);

        $folderIds = (clone $visible)->whereNotNull('envelopes.folder_id')->distinct()->pluck('envelopes.folder_id');
        $creatorIds = (clone $visible)->distinct()->pluck('envelopes.created_by_user_id');

        return [
            'folders' => array_values(Folder::forOrganization($this->membership->organization_id)
                ->whereIn('id', $folderIds)
                ->orderBy('name')
                ->get()
                ->map(fn (Folder $folder): array => ['value' => $folder->ulid, 'label' => $folder->name])
                ->all()),
            'teams' => array_values(Team::forOrganization($this->membership->organization_id)
                ->orderBy('name')
                ->get()
                ->map(fn (Team $team): array => ['value' => $team->ulid, 'label' => $team->name])
                ->all()),
            'creators' => array_values(User::query()
                ->whereIn('id', $creatorIds)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user): array => ['value' => (string) $user->getKey(), 'label' => $user->name])
                ->all()),
        ];
    }

    /**
     * Série diária completa do período (dias sem movimento aparecem com zero).
     *
     * @param  array<string, int>  $sentByDay
     * @param  array<string, int>  $completedByDay
     * @return list<array{date: string, sent: int, completed: int}>
     */
    private function series(array $sentByDay, array $completedByDay): array
    {
        $series = [];

        for ($day = $this->filters->from->copy(); $day->lessThanOrEqualTo($this->filters->to); $day->addDay()) {
            $key = $day->format('Y-m-d');
            $series[] = ['date' => $key, 'sent' => $sentByDay[$key] ?? 0, 'completed' => $completedByDay[$key] ?? 0];
        }

        return $series;
    }

    /**
     * Membros (user_id) de um time, restritos à organização e a memberships ativas.
     */
    private function teamUserIdsQuery(Team $team): \Illuminate\Database\Query\Builder
    {
        return DB::table('team_memberships')
            ->join('memberships', 'memberships.id', '=', 'team_memberships.membership_id')
            ->where('team_memberships.team_id', $team->getKey())
            ->where('memberships.organization_id', $this->membership->organization_id)
            ->select('memberships.user_id');
    }

    /**
     * @param  array<int, array{sent: int, completed: int, closed_unsigned: int, durations: list<float>}>  $byUser
     * @return list<array{user: array{id: string, name: string}, sent: int, completed: int, closed_unsigned: int, avg_minutes_to_complete: float|null}>
     */
    private function byUser(array $byUser): array
    {
        if ($byUser === []) {
            return [];
        }

        $names = User::query()->whereIn('id', array_keys($byUser))->pluck('name', 'id');

        $rows = [];

        foreach ($byUser as $userId => $row) {
            $rows[] = [
                'user' => ['id' => (string) $userId, 'name' => (string) ($names[$userId] ?? 'Usuário removido')],
                'sent' => $row['sent'],
                'completed' => $row['completed'],
                'closed_unsigned' => $row['closed_unsigned'],
                'avg_minutes_to_complete' => self::average($row['durations']),
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$b['sent'], $b['completed']] <=> [$a['sent'], $a['completed']]);

        return $rows;
    }

    /**
     * Por time: soma dos criadores que participam de cada time. Quem está em dois times conta
     * nos dois (a tabela diz isso); quem não está em time nenhum aparece em "Sem time".
     *
     * @param  array<int, array{sent: int, completed: int, closed_unsigned: int, durations: list<float>}>  $byUser
     * @return list<array{team: array{id: string|null, name: string}, sent: int, completed: int, closed_unsigned: int, avg_minutes_to_complete: float|null}>
     */
    private function byTeam(array $byUser): array
    {
        $teams = Team::forOrganization($this->membership->organization_id)->orderBy('name')->get(['id', 'ulid', 'name']);

        if ($teams->isEmpty() || $byUser === []) {
            return [];
        }

        $links = DB::table('team_memberships')
            ->join('memberships', 'memberships.id', '=', 'team_memberships.membership_id')
            ->whereIn('team_memberships.team_id', $teams->pluck('id'))
            ->where('memberships.organization_id', $this->membership->organization_id)
            ->get(['team_memberships.team_id', 'memberships.user_id']);

        $usersByTeam = [];
        $teamed = [];

        foreach ($links as $link) {
            $usersByTeam[(int) $link->team_id][] = (int) $link->user_id;
            $teamed[(int) $link->user_id] = true;
        }

        $rows = [];

        foreach ($teams as $team) {
            $rows[] = $this->sumUsers(['id' => $team->ulid, 'name' => $team->name], $usersByTeam[(int) $team->getKey()] ?? [], $byUser);
        }

        $withoutTeam = array_values(array_filter(array_keys($byUser), fn (int $userId): bool => ! isset($teamed[$userId])));

        if ($withoutTeam !== []) {
            $rows[] = $this->sumUsers(['id' => null, 'name' => 'Sem time'], $withoutTeam, $byUser);
        }

        return $rows;
    }

    /**
     * @param  array{id: string|null, name: string}  $team
     * @param  list<int>  $userIds
     * @param  array<int, array{sent: int, completed: int, closed_unsigned: int, durations: list<float>}>  $byUser
     * @return array{team: array{id: string|null, name: string}, sent: int, completed: int, closed_unsigned: int, avg_minutes_to_complete: float|null}
     */
    private function sumUsers(array $team, array $userIds, array $byUser): array
    {
        $row = ['team' => $team, 'sent' => 0, 'completed' => 0, 'closed_unsigned' => 0, 'avg_minutes_to_complete' => null];
        $durations = [];

        foreach (array_unique($userIds) as $userId) {
            if (! isset($byUser[$userId])) {
                continue;
            }

            $row['sent'] += $byUser[$userId]['sent'];
            $row['completed'] += $byUser[$userId]['completed'];
            $row['closed_unsigned'] += $byUser[$userId]['closed_unsigned'];
            array_push($durations, ...$byUser[$userId]['durations']);
        }

        $row['avg_minutes_to_complete'] = self::average($durations);

        return $row;
    }

    /**
     * @param  list<float>  $values
     */
    private static function average(array $values): ?float
    {
        return $values === [] ? null : round(array_sum($values) / count($values), 1);
    }
}
