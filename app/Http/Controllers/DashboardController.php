<?php

namespace App\Http\Controllers;

use App\Enums\EnvelopeStatus;
use App\Enums\MembershipStatus;
use App\Enums\RecipientStatus;
use App\Http\Resources\EnvelopeResource;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\Recipient;
use App\Services\Organizations\EnvelopeVisibility;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dashboard (ROUTES §2.4) com dados reais do banco, respeitando a visibilidade por papel
 * (RECONCILIACAO Q7). Datas em UTC; agrupamento por dia/mês no fuso da organização.
 */
class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate(['range' => ['nullable', Rule::in(['30d', '90d', '12m'])]]);
        $range = $validated['range'] ?? '30d';

        $current = CurrentOrganization::instance();
        $organization = $current->get();
        $membership = $current->membership();
        $user = $request->user();
        $timezone = $organization->timezone;
        $now = Carbon::now($timezone);

        [$from, $previousFrom, $previousLabel] = $this->period($range, $now);

        $visible = fn (): Builder => EnvelopeVisibility::envelopes($membership);

        $sentInRange = $visible()->whereBetween('sent_at', [$from->utc(), $now->utc()])->count();
        $sentPrevious = $visible()->whereBetween('sent_at', [$previousFrom->utc(), $from->utc()])->count();

        $pending = $visible()->where('status', EnvelopeStatus::InProgress->value);
        $pendingCount = (clone $pending)->count();
        $expiring48h = (clone $pending)->whereBetween('expires_at', [now(), now()->addHours(48)])->count();

        $completedInRange = $visible()->whereBetween('completed_at', [$from->utc(), $now->utc()])->count();
        $refusedOrExpired = $visible()
            ->whereIn('status', [EnvelopeStatus::Refused->value, EnvelopeStatus::Expired->value, EnvelopeStatus::Canceled->value])
            ->whereBetween('updated_at', [$from->utc(), $now->utc()])
            ->count();
        $closedInRange = $completedInRange + $refusedOrExpired;

        $avgMinutes = $this->averageMinutesToComplete($visible()->whereBetween('completed_at', [$from->utc(), $now->utc()]));
        $avgPrevious = $this->averageMinutesToComplete($visible()->whereBetween('completed_at', [$previousFrom->utc(), $from->utc()]));

        return Inertia::render('dashboard', [
            'greeting' => [
                'first_name' => explode(' ', trim($user->name))[0],
                // DESIGN_SYSTEM §6.2: "Quarta-feira, 3 de setembro de 2026" — dia sem zero
                // à esquerda (`j`) e com o ano.
                'date_label' => ucfirst($now->setTimezone($user->timezone ?: $timezone)->translatedFormat('l, j \d\e F \d\e Y')),
                'pending_count' => $pendingCount,
            ],
            'range' => $range,
            'kpis' => [
                'sent' => [
                    'value' => $sentInRange,
                    'delta_pct' => $sentPrevious > 0 ? (int) round(($sentInRange - $sentPrevious) / $sentPrevious * 100) : null,
                    'previous_value' => $sentPrevious,
                    'previous_label' => $previousLabel,
                ],
                'pending' => ['value' => $pendingCount, 'expiring_48h' => $expiring48h],
                'completed' => [
                    'value' => $completedInRange,
                    'completion_rate_pct' => $closedInRange > 0 ? (int) round($completedInRange / $closedInRange * 100) : null,
                    'refused_or_expired' => $refusedOrExpired,
                ],
                'avg_time_to_complete' => [
                    'minutes' => $avgMinutes,
                    'delta_minutes' => $avgMinutes !== null && $avgPrevious !== null ? $avgMinutes - $avgPrevious : null,
                ],
            ],
            'chart' => $this->chart($visible, $range, $from, $now, $timezone),
            'pending_recipients' => $this->pendingRecipients($membership),
            'pending_recipients_total' => EnvelopeVisibility::recipients($membership)
                ->whereIn('status', [RecipientStatus::Notified->value, RecipientStatus::Viewed->value])
                ->whereHas('envelope', fn (Builder $q) => $q->where('status', EnvelopeStatus::InProgress->value))
                ->count(),
            'plan_usage' => $this->planUsage(),
            'recent_envelopes' => EnvelopeResource::collection(
                $visible()->with(['folder', 'creator', 'recipients', 'document'])
                    ->where('updated_at', '>=', now()->subDays(7))
                    ->latest('updated_at')
                    ->limit(5)
                    ->get(),
            )->resolve($request),
            'recent_total' => $visible()->count(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $validated = $request->validate(['range' => ['nullable', Rule::in(['30d', '90d', '12m'])]]);
        $range = $validated['range'] ?? '30d';

        $current = CurrentOrganization::instance();
        $timezone = $current->get()->timezone;
        [$from] = $this->period($range, Carbon::now($timezone));

        $query = EnvelopeVisibility::envelopes($current->membership())
            ->with(['folder', 'creator', 'recipients'])
            ->where('created_at', '>=', $from->utc())
            ->orderBy('created_at');

        $filename = 'documentos-'.$range.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query, $timezone): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Código', 'Título', 'Status', 'Pasta', 'Criado por', 'Signatários', 'Assinados', 'Criado em', 'Enviado em', 'Concluído em', 'Expira em'], ';');

            $query->chunk(200, function ($envelopes) use ($out, $timezone): void {
                foreach ($envelopes as $envelope) {
                    fputcsv($out, [
                        $envelope->display_code,
                        $envelope->title,
                        $envelope->statusLabel(),
                        $envelope->folder->name ?? '',
                        $envelope->creator->name ?? '',
                        $envelope->recipients->count(),
                        $envelope->signedCount(),
                        $envelope->created_at?->setTimezone($timezone)->format('d/m/Y H:i') ?? '',
                        $envelope->sent_at?->setTimezone($timezone)->format('d/m/Y H:i') ?? '',
                        $envelope->completed_at?->setTimezone($timezone)->format('d/m/Y H:i') ?? '',
                        $envelope->expires_at?->setTimezone($timezone)->format('d/m/Y') ?? '',
                    ], ';');
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    protected function period(string $range, Carbon $now): array
    {
        return match ($range) {
            // O rótulo entra na legenda como "vs. {valor} {rótulo}" (dashboard.tsx),
            // então ele NÃO leva "vs." — o mock diz "vs. 132 em agosto".
            '90d' => [$now->copy()->subDays(90)->startOfDay(), $now->copy()->subDays(180)->startOfDay(), 'nos 90 dias anteriores'],
            '12m' => [$now->copy()->subMonths(12)->startOfDay(), $now->copy()->subMonths(24)->startOfDay(), 'nos 12 meses anteriores'],
            default => [
                $now->copy()->subDays(30)->startOfDay(),
                $now->copy()->subDays(60)->startOfDay(),
                'em '.$now->copy()->subMonth()->translatedFormat('F'),
            ],
        };
    }

    /**
     * @param  Builder<Envelope>  $completed
     */
    protected function averageMinutesToComplete(Builder $completed): ?int
    {
        $rows = $completed->whereNotNull('sent_at')->get(['sent_at', 'completed_at']);

        if ($rows->isEmpty()) {
            return null;
        }

        $total = $rows->sum(fn (Envelope $e): int => (int) $e->sent_at->diffInMinutes($e->completed_at));

        return (int) round($total / $rows->count());
    }

    /**
     * @param  callable(): Builder<Envelope>  $visible
     * @return array{buckets: list<array{date: string, sent: int, completed: int}>, totals: array{sent: int, completed: int}, axis_labels: list<string>}
     */
    protected function chart(callable $visible, string $range, Carbon $from, Carbon $now, string $timezone): array
    {
        $monthly = $range === '12m';

        $sent = $visible()->whereBetween('sent_at', [$from->utc(), $now->utc()])->get(['sent_at'])
            ->groupBy(fn (Envelope $e) => $e->sent_at->setTimezone($timezone)->format($monthly ? 'Y-m' : 'Y-m-d'))
            ->map->count();

        $completed = $visible()->whereBetween('completed_at', [$from->utc(), $now->utc()])->get(['completed_at'])
            ->groupBy(fn (Envelope $e) => $e->completed_at->setTimezone($timezone)->format($monthly ? 'Y-m' : 'Y-m-d'))
            ->map->count();

        $buckets = [];
        $labels = [];
        $cursor = $monthly ? $from->copy()->startOfMonth() : $from->copy();
        $step = 0;
        $count = $monthly ? 12 : ($range === '90d' ? 90 : 30);

        while ($step < $count) {
            $key = $cursor->format($monthly ? 'Y-m' : 'Y-m-d');
            $buckets[] = [
                'date' => $monthly ? $cursor->format('Y-m-01') : $cursor->format('Y-m-d'),
                'sent' => (int) ($sent[$key] ?? 0),
                'completed' => (int) ($completed[$key] ?? 0),
            ];

            $labelEvery = $monthly ? 1 : ($range === '90d' ? 15 : 7);

            if ($step % $labelEvery === 0) {
                $labels[] = $monthly ? $cursor->translatedFormat('M/y') : $cursor->translatedFormat('j M');
            }

            $cursor = $monthly ? $cursor->addMonth() : $cursor->addDay();
            $step++;
        }

        return [
            'buckets' => $buckets,
            'totals' => ['sent' => (int) $sent->sum(), 'completed' => (int) $completed->sum()],
            'axis_labels' => $labels,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function pendingRecipients(Membership $membership): array
    {
        $throttle = (int) config('assinavelox.resend.throttle_minutes', 10);

        return EnvelopeVisibility::recipients($membership)
            ->with('envelope')
            ->whereIn('status', [RecipientStatus::Notified->value, RecipientStatus::Viewed->value])
            ->whereHas('envelope', fn (Builder $q) => $q->where('status', EnvelopeStatus::InProgress->value))
            ->orderBy('last_notified_at')
            ->limit(4)
            ->get()
            ->map(fn (Recipient $recipient): array => [
                'id' => $recipient->ulid,
                'envelope_id' => $recipient->envelope->ulid,
                'name' => $recipient->name,
                'initials' => $recipient->initials,
                'envelope_title' => $recipient->envelope->title,
                'waiting_since' => ($recipient->last_notified_at ?? $recipient->envelope->sent_at ?? $recipient->created_at)?->toIso8601String(),
                'can_resend' => $recipient->last_notified_at === null || $recipient->last_notified_at->lte(now()->subMinutes($throttle)),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function planUsage(): array
    {
        $organization = CurrentOrganization::instance()->get();
        $subscription = $organization->currentSubscription()->with('plan')->first();
        $plan = $subscription?->plan;
        $features = $plan->features ?? [];

        return [
            'plan_name' => $plan->name ?? 'Grátis',
            'renews_at' => $plan && ! $plan->isFree() ? $subscription->current_period_end?->toIso8601String() : null,
            'envelopes' => ['used' => (int) ($subscription->envelopes_used ?? 0), 'limit' => $plan?->envelope_quota],
            'members' => [
                'used' => $organization->memberships()->where('status', MembershipStatus::Active->value)->count(),
                'limit' => $plan?->user_quota,
            ],
            'storage' => [
                'used_bytes' => (int) DocumentVersion::query()->sum('size_bytes'),
                'limit_bytes' => isset($features['storage_bytes']) ? (int) $features['storage_bytes'] : null,
            ],
        ];
    }
}
