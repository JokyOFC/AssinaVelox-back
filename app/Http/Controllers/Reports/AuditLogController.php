<?php

namespace App\Http\Controllers\Reports;

use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Services\AdminLog\AdminEventCatalog;
use App\Services\AdminLog\OrganizationAuditLog;
use App\Services\AdminLog\ToolFlags;
use App\Support\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configurações › Registro de atividades — log administrativo da organização (Fase 2,
 * roadmap §2.14). Exige a flag `audit_log` e a permissão `view_audit_log`.
 *
 * Somente leitura: nenhum UPDATE/DELETE em `audit_events`. Sem dados de signatário (ver
 * AdminEventCatalog).
 */
class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $current = CurrentOrganization::instance();
        $organization = $current->get();
        $membership = $current->membership();

        abort_if($organization === null || $membership === null, 403, 'Nenhuma organização ativa.');

        if (! ToolFlags::auditLog($organization)) {
            return Inertia::render('settings/audit', ['enabled' => false]);
        }

        abort_unless($membership->hasPermission(Permission::ViewAuditLog), 403, 'Você não tem permissão para ver o registro de atividades.');

        $validated = $request->validate([
            'category' => ['nullable', Rule::in(array_keys(AdminEventCatalog::categories()))],
            'actor' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
        ], [], ['category' => 'categoria', 'actor' => 'autor', 'from' => 'início', 'to' => 'fim']);

        $timezone = $organization->timezone;

        $filters = [
            'category' => $validated['category'] ?? null,
            'actor' => isset($validated['actor']) ? (int) $validated['actor'] : null,
            'from' => isset($validated['from']) ? Carbon::createFromFormat('Y-m-d', $validated['from'], $timezone)->startOfDay()->utc() : null,
            'to' => isset($validated['to']) ? Carbon::createFromFormat('Y-m-d', $validated['to'], $timezone)->endOfDay()->utc() : null,
        ];

        $page = OrganizationAuditLog::query((int) $organization->getKey(), $filters)
            ->paginate((int) ($validated['per_page'] ?? 25))
            ->withQueryString();

        return Inertia::render('settings/audit', [
            'enabled' => true,
            'filters' => [
                'category' => $filters['category'],
                'actor' => $filters['actor'] !== null ? (string) $filters['actor'] : null,
                'from' => $validated['from'] ?? null,
                'to' => $validated['to'] ?? null,
            ],
            'categories' => array_map(
                fn (string $key, array $definition): array => ['value' => $key, 'label' => $definition['label']],
                array_keys(AdminEventCatalog::categories()),
                AdminEventCatalog::categories(),
            ),
            // Autores possíveis: membros da organização (nome apenas).
            'actors' => Membership::query()
                ->with('user:id,name')
                ->where('organization_id', $organization->getKey())
                ->whereIn('status', [MembershipStatus::Active->value, MembershipStatus::Suspended->value])
                ->get()
                ->map(fn (Membership $m): array => ['value' => (string) $m->user_id, 'label' => $m->user->name])
                ->sortBy('label')
                ->values()
                ->all(),
            'events' => [
                'data' => OrganizationAuditLog::present($page),
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
}
