<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\PlatformAuditEvent;
use App\Models\User;
use App\Services\AdminLog\OrganizationAuditLog;
use App\Services\AdminLog\PlatformAction;
use App\Services\AdminLog\ToolFlags;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Painel interno › Logs e auditoria (Fase 2, roadmap §2.14). Flag `admin_audit`
 * (desligada: placeholder da Fase 1).
 *
 * Lista `platform_audit_events` — ações da equipe (bloqueios, "acessar como"). Somente
 * leitura: nenhuma rota faz UPDATE/DELETE nessa tabela, e o model recusa ambos.
 */
class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        if (! ToolFlags::adminAudit()) {
            return PlaceholderController::render('admin.audit.index');
        }

        $validated = $request->validate([
            'action' => ['nullable', Rule::enum(PlatformAction::class)],
            'actor' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
        ]);

        // O painel interno não tem organização: datas do filtro no fuso padrão da plataforma.
        $timezone = Organization::DEFAULT_TIMEZONE;

        $query = PlatformAuditEvent::query()->with(['actor:id,name', 'organization:id,ulid,name']);

        if (! empty($validated['action'])) {
            $query->where('action', $validated['action']);
        }

        if (! empty($validated['actor'])) {
            $query->where('actor_user_id', (int) $validated['actor']);
        }

        if (! empty($validated['from'])) {
            $query->where('occurred_at', '>=', Carbon::createFromFormat('Y-m-d', $validated['from'], $timezone)->startOfDay()->utc());
        }

        if (! empty($validated['to'])) {
            $query->where('occurred_at', '<=', Carbon::createFromFormat('Y-m-d', $validated['to'], $timezone)->endOfDay()->utc());
        }

        $page = $query->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 25))
            ->withQueryString();

        return Inertia::render('admin/audit/index', [
            'filters' => [
                'action' => $validated['action'] ?? null,
                'actor' => isset($validated['actor']) ? (string) $validated['actor'] : null,
                'from' => $validated['from'] ?? null,
                'to' => $validated['to'] ?? null,
            ],
            'actions' => PlatformAction::options(),
            'actors' => User::query()
                ->where('is_platform_admin', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user): array => ['value' => (string) $user->getKey(), 'label' => $user->name])
                ->values()
                ->all(),
            'events' => [
                'data' => collect($page->items())->map(fn (PlatformAuditEvent $event): array => [
                    'id' => $event->ulid,
                    'occurred_at' => $event->occurred_at->toIso8601String(),
                    'action' => $event->action->value,
                    'label' => $event->action->label(),
                    'kind' => $event->action->kind(),
                    'actor' => $event->actor->name ?? 'Sistema',
                    'organization' => $event->organization !== null
                        ? ['id' => $event->organization->ulid, 'name' => $event->organization->name]
                        : null,
                    'details' => $this->details($event->payload ?? []),
                    'ip' => OrganizationAuditLog::maskIp($event->ip_address),
                ])->values()->all(),
                'links' => [
                    'first' => $page->url(1),
                    'last' => $page->url($page->lastPage()),
                    'prev' => $page->previousPageUrl(),
                    'next' => $page->nextPageUrl(),
                ],
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'from' => $page->firstItem(),
                    'to' => $page->lastItem(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'path' => $page->path(),
                    'links' => $page->linkCollection()->toArray(),
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{label: string, value: string}>
     */
    private function details(array $payload): array
    {
        $labels = [
            'target_name' => 'Usuário',
            'organization_name' => 'Organização',
            'reason' => 'Motivo',
            'end_reason' => 'Encerramento',
            'pages_viewed' => 'Páginas visitadas',
        ];

        $details = [];

        foreach ($labels as $key => $label) {
            $value = $payload[$key] ?? null;

            if (! is_scalar($value) || $value === '') {
                continue;
            }

            $text = $key === 'end_reason' ? OrganizationAuditLog::endReasonLabel((string) $value) : (string) $value;
            $details[] = ['label' => $label, 'value' => mb_strimwidth($text, 0, 200, '…')];
        }

        return $details;
    }
}
