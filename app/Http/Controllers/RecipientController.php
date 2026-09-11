<?php

namespace App\Http\Controllers;

use App\Enums\AuditEventType;
use App\Enums\Permission;
use App\Enums\RecipientRole;
use App\Enums\RecipientStatus;
use App\Http\Resources\RecipientListItemResource;
use App\Jobs\Envelopes\ResendPendingInvitations;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Recipient;
use App\Services\Envelopes\Sending\ResendInvitations;
use App\Services\Organizations\EnvelopeVisibility;
use App\Support\Csv;
use App\Support\CurrentOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Assinaturas (recipients cross-envelope) — ROUTES §2.9 / DESIGN §6.6.
 *
 * Uma linha por signatário entre os envelopes VISÍVEIS ao usuário: owner/admin veem toda a
 * organização, `member` só o que criou (RECONCILIACAO Q7 — a visibilidade vem de
 * `EnvelopeVisibility::recipients()`, que também descarta envelopes excluídos).
 */
class RecipientController extends Controller
{
    private const TABS = ['all', 'pending', 'signed', 'refused', 'expired'];

    /**
     * Data de referência da linha ("Quando"): assinou → recusou → notificado → criado.
     * Usada para filtro de período e ordenação, e espelhada em `when` no resource.
     */
    private const WHEN = 'COALESCE(recipients.signed_at, recipients.refused_at, recipients.last_notified_at, recipients.created_at)';

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        $current = CurrentOrganization::instance();
        $membership = $current->membership();
        $timezone = $current->get()->timezone;

        $base = fn (): Builder => $this->applyFilters(EnvelopeVisibility::recipients($membership), $filters);

        $tabs = [];

        foreach (self::TABS as $tab) {
            $tabs[$tab] = $this->applyTab($base(), $tab)->count();
        }

        $recipients = $this->applyTab($base(), $filters['status'])
            ->with(['envelope', 'acceptance'])
            ->orderByRaw(self::WHEN.($filters['sort'] === 'oldest' ? ' asc' : ' desc'))
            ->orderByDesc('recipients.id')
            ->paginate((int) $request->integer('per_page', 10) ?: 10)
            ->withQueryString();

        $viewedAt = $this->viewedAt($recipients->getCollection());

        foreach ($recipients as $recipient) {
            $recipient->setAttribute(RecipientListItemResource::VIEWED_AT, $viewedAt->get($recipient->getKey()));
        }

        return Inertia::render('recipients/index', [
            'filters' => $filters,
            'kpis' => $this->kpis($membership, $timezone),
            'tabs' => $tabs,
            'recipients' => RecipientListItemResource::collection($recipients),
            // Mesma permissão que a rota exige (`org.role` → Permissions::routeAllows).
            'can' => [
                'resend_pending' => $membership->hasPermission(Permission::ManageAnyEnvelope),
                // Mesma regra de `export()`.
                'export' => $membership->hasPermission(Permission::ExportData),
            ],
        ]);
    }

    /**
     * CSV com os mesmos filtros da listagem. Células neutralizadas contra injeção de
     * fórmula (App\Support\Csv).
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);

        $current = CurrentOrganization::instance();
        $membership = $current->membership();

        // `export_data` = "Planilhas CSV dos documentos e assinaturas visíveis".
        abort_unless($membership->hasPermission(Permission::ExportData), 403, 'Sua função não permite exportar dados.');

        $timezone = $current->get()->timezone;

        $query = $this->applyTab(
            $this->applyFilters(EnvelopeVisibility::recipients($membership), $filters),
            $filters['status'],
        )->with(['envelope'])->orderByRaw(self::WHEN.' desc');

        $filename = 'assinaturas-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query, $timezone): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, Csv::BOM);
            fputcsv($out, ['Signatário', 'E-mail', 'Papel', 'Documento', 'Título', 'Status', 'Canal', 'Autenticação', 'Enviado em', 'Assinado em', 'Recusado em', 'Motivo'], ';');

            $query->chunk(200, function (Collection $rows) use ($out, $timezone): void {
                foreach ($rows as $recipient) {
                    fputcsv($out, Csv::row([
                        $recipient->name,
                        $recipient->email,
                        $recipient->role_label ?? '',
                        $recipient->envelope->display_code,
                        $recipient->envelope->title,
                        $recipient->status->label(),
                        'E-mail',
                        $recipient->auth_method->value,
                        $recipient->last_notified_at?->setTimezone($timezone)->format('d/m/Y H:i') ?? '',
                        $recipient->signed_at?->setTimezone($timezone)->format('d/m/Y H:i') ?? '',
                        $recipient->refused_at?->setTimezone($timezone)->format('d/m/Y H:i') ?? '',
                        $recipient->refusal_reason ?? '',
                    ]), ';');
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * "Lembrar todos os pendentes" (ROUTES §1.2 `recipients.resend_pending`): job em fila,
     * throttle global de 1×/hora por organização, throttle de 10 min por destinatário
     * dentro do job. O escopo é o do usuário: `member` só reenvia o que ele criou.
     */
    public function resendPending(Request $request, ResendInvitations $resends): RedirectResponse
    {
        $membership = CurrentOrganization::instance()->membership();

        abort_if($membership === null, 403);

        $organization = $membership->organization;
        $cooldown = $resends->organizationCooldownMinutes($organization);

        if ($cooldown > 0) {
            return back()->with('error', "Os lembretes em lote já foram disparados há pouco. Tente novamente em {$cooldown} minuto(s).");
        }

        $pending = EnvelopeVisibility::recipients($membership)
            ->whereIn('recipients.status', [
                RecipientStatus::Pending->value,
                RecipientStatus::Notified->value,
                RecipientStatus::Viewed->value,
            ])
            // Mesma seleção de ResendInvitations::eligible(): visualizador não é pendência.
            ->whereIn('recipients.role', RecipientRole::participatingValues())
            ->count();

        if ($pending === 0) {
            return back()->with('info', 'Não há signatários pendentes.');
        }

        $resends->markOrganizationCooldown($organization);

        ResendPendingInvitations::dispatch($organization->getKey(), $membership->user_id);

        return back()->with('success', "Reenviando convites para {$pending} signatário(s) pendente(s).");
    }

    // -- Filtros e agregações ----------------------------------------------------------

    /**
     * @return array{status: string, q: string, period_from: string|null, period_to: string|null, sort: string}
     */
    protected function filters(Request $request): array
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(self::TABS)],
            'q' => ['nullable', 'string', 'max:120'],
            'period_from' => ['nullable', 'date'],
            'period_to' => ['nullable', 'date', 'after_or_equal:period_from'],
            'sort' => ['nullable', Rule::in(['recent', 'oldest'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
        ]);

        return [
            'status' => $validated['status'] ?? 'all',
            'q' => trim((string) ($validated['q'] ?? '')),
            'period_from' => $validated['period_from'] ?? null,
            'period_to' => $validated['period_to'] ?? null,
            'sort' => $validated['sort'] ?? 'recent',
        ];
    }

    /**
     * @param  Builder<Recipient>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Recipient>
     */
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        if ($filters['q'] !== '') {
            $like = '%'.$filters['q'].'%';
            $numeric = ltrim(preg_replace('/^av-?/i', '', $filters['q']) ?? '', '0');

            $query->where(function (Builder $q) use ($like, $numeric): void {
                $q->where('recipients.name', 'like', $like)
                    ->orWhere('recipients.email', 'like', $like)
                    ->orWhereHas('envelope', function (Builder $envelope) use ($like, $numeric): void {
                        $envelope->where('envelopes.title', 'like', $like);

                        if ($numeric !== '' && ctype_digit($numeric)) {
                            $envelope->orWhere('envelopes.number', (int) $numeric);
                        }
                    });
            });
        }

        if ($filters['period_from']) {
            $query->whereRaw(self::WHEN.' >= ?', [Carbon::parse($filters['period_from'])->startOfDay()]);
        }

        if ($filters['period_to']) {
            $query->whereRaw(self::WHEN.' <= ?', [Carbon::parse($filters['period_to'])->endOfDay()]);
        }

        return $query;
    }

    /**
     * Abas → status (ROUTES §2.9): `pending` = pending|notified|viewed;
     * `expired` = expired|canceled.
     *
     * @param  Builder<Recipient>  $query
     * @return Builder<Recipient>
     */
    protected function applyTab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            // Fase 2 §2.4: o visualizador só acompanha — não tem aceite a dar, então não é
            // pendência (na Fase 1 todos são `signer` e o filtro não muda nada).
            'pending' => $query->whereIn('recipients.status', [
                RecipientStatus::Pending->value,
                RecipientStatus::Notified->value,
                RecipientStatus::Viewed->value,
            ])->whereIn('recipients.role', RecipientRole::participatingValues()),
            'signed' => $query->where('recipients.status', RecipientStatus::Signed->value),
            'refused' => $query->where('recipients.status', RecipientStatus::Refused->value),
            'expired' => $query->whereIn('recipients.status', [
                RecipientStatus::Expired->value,
                RecipientStatus::Canceled->value,
            ]),
            default => $query,
        };
    }

    /**
     * KPIs do topo (ROUTES §2.9). Sempre sobre os signatários visíveis ao usuário.
     *
     * @return array<string, mixed>
     */
    protected function kpis(Membership $membership, string $timezone): array
    {
        $now = Carbon::now($timezone);
        $visible = fn (): Builder => EnvelopeVisibility::recipients($membership);

        $signedToday = (clone $visible())
            ->where('recipients.status', RecipientStatus::Signed->value)
            ->whereBetween('recipients.signed_at', [$now->copy()->startOfDay()->utc(), $now->copy()->endOfDay()->utc()])
            ->count();

        $signedYesterday = (clone $visible())
            ->where('recipients.status', RecipientStatus::Signed->value)
            ->whereBetween('recipients.signed_at', [
                $now->copy()->subDay()->startOfDay()->utc(),
                $now->copy()->subDay()->endOfDay()->utc(),
            ])
            ->count();

        // Os três indicadores falam de quem tem aceite a dar: o visualizador (Fase 2 §2.4)
        // não pode estar pendente, "visualizar" para ele não é passo de coleta, e ele não
        // recusa — contá-lo no total diluiria a taxa de recusa.
        $participating = fn (): Builder => (clone $visible())
            ->whereIn('recipients.role', RecipientRole::participatingValues());

        $pending = $participating()->whereIn('recipients.status', [
            RecipientStatus::Pending->value,
            RecipientStatus::Notified->value,
            RecipientStatus::Viewed->value,
        ])->count();

        $viewed = $participating()->where('recipients.status', RecipientStatus::Viewed->value)->count();

        $total = $participating()->count();

        $refused = (clone $visible())
            ->where('recipients.status', RecipientStatus::Refused->value)
            ->where('recipients.refused_at', '>=', $now->copy()->subDays(30)->utc())
            ->count();

        return [
            'signed_today' => ['value' => $signedToday, 'delta_vs_yesterday' => $signedToday - $signedYesterday],
            'pending' => ['value' => $pending, 'viewed' => $viewed],
            'refused_30d' => [
                'value' => $refused,
                'pct_of_total' => $total > 0 ? round($refused * 100 / $total, 1) : null,
            ],
            'avg_minutes_to_sign' => ['value' => $this->averageMinutesToSign($membership)],
        ];
    }

    /**
     * Média de minutos entre o envio do documento e o aceite, nos últimos 90 dias.
     * Calculada em PHP para não depender de DATEDIFF/julianday (MySQL × SQLite).
     */
    protected function averageMinutesToSign(Membership $membership): ?int
    {
        $rows = EnvelopeVisibility::recipients($membership)
            ->where('recipients.status', RecipientStatus::Signed->value)
            ->where('recipients.signed_at', '>=', Carbon::now()->subDays(90))
            ->with('envelope:id,sent_at')
            ->limit(1000)
            ->get(['recipients.id', 'recipients.envelope_id', 'recipients.signed_at']);

        $minutes = [];

        foreach ($rows as $recipient) {
            $sentAt = $recipient->envelope?->sent_at;

            if ($sentAt === null || $recipient->signed_at === null || $recipient->signed_at->lt($sentAt)) {
                continue;
            }

            $minutes[] = $sentAt->diffInMinutes($recipient->signed_at);
        }

        return $minutes === [] ? null : (int) round(array_sum($minutes) / count($minutes));
    }

    /**
     * Primeira abertura do convite por signatário (evento `invitation.opened`), em uma
     * consulta só para a página inteira.
     *
     * @param  EloquentCollection<int, Recipient>  $recipients
     * @return Collection<int, CarbonInterface>
     */
    protected function viewedAt(Collection $recipients): Collection
    {
        if ($recipients->isEmpty()) {
            return collect();
        }

        return AuditEvent::query()
            ->where('event_type', AuditEventType::InvitationOpened)
            ->whereIn('recipient_id', $recipients->modelKeys())
            ->orderBy('occurred_at')
            ->get(['recipient_id', 'occurred_at'])
            ->groupBy('recipient_id')
            ->map(fn (Collection $group): CarbonInterface => $group->first()->occurred_at);
    }

    /**
     * Shape Paginated<T> vazio (ROUTES §0.4) para esqueletos.
     *
     * @return array<string, mixed>
     */
    public static function emptyPaginated(string $path, int $perPage = 10): array
    {
        return [
            'data' => [],
            'links' => ['first' => $path.'?page=1', 'last' => $path.'?page=1', 'prev' => null, 'next' => null],
            'meta' => [
                'current_page' => 1,
                'from' => null,
                'to' => null,
                'last_page' => 1,
                'per_page' => $perPage,
                'total' => 0,
                'path' => $path,
                'links' => [
                    ['url' => null, 'label' => '&laquo; Anterior', 'active' => false],
                    ['url' => $path.'?page=1', 'label' => '1', 'active' => true],
                    ['url' => null, 'label' => 'Próxima &raquo;', 'active' => false],
                ],
            ],
        ];
    }
}
