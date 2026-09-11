<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\User;
use App\Services\AdminLog\ToolFlags;
use App\Services\Impersonation\AccountBlocking;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Painel interno › Usuários da plataforma (Fase 2, roadmap §2.14). Flag `admin_users`
 * (desligada: placeholder da Fase 1).
 *
 * Lista contas (busca por nome/e-mail), organizações de cada uma, último acesso, 2FA e
 * bloqueio. Bloquear/desbloquear exige senha confirmada e motivo; tudo vai para
 * `platform_audit_events`. Nada aqui dá acesso a conteúdo de documentos.
 */
class UserController extends Controller
{
    private const STATUSES = ['all', 'active', 'blocked', 'platform_admin'];

    public function __construct(private readonly AccountBlocking $blocking) {}

    public function index(Request $request): Response
    {
        if (! ToolFlags::adminUsers()) {
            return PlaceholderController::render('admin.users.index');
        }

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
        ]);

        $filters = [
            'q' => trim((string) ($validated['q'] ?? '')),
            'status' => $validated['status'] ?? 'all',
        ];

        $query = User::query();

        if ($filters['q'] !== '') {
            $like = '%'.$filters['q'].'%';
            $query->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
        }

        match ($filters['status']) {
            'active' => $query->whereNull('blocked_at'),
            'blocked' => $query->whereNotNull('blocked_at'),
            'platform_admin' => $query->where('is_platform_admin', true),
            default => null,
        };

        $page = $query->orderBy('name')->orderBy('id')
            ->paginate((int) ($validated['per_page'] ?? 25))
            ->withQueryString();

        return Inertia::render('admin/users/index', [
            'filters' => $filters,
            'summary' => [
                'total' => User::query()->count(),
                'blocked' => User::query()->whereNotNull('blocked_at')->count(),
                'platform_admins' => User::query()->where('is_platform_admin', true)->count(),
                'without_2fa' => User::query()->whereNull('two_factor_confirmed_at')->count(),
            ],
            'users' => [
                'data' => $this->rows($page, $request->user()),
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

    public function block(Request $request, User $user): RedirectResponse
    {
        abort_unless(ToolFlags::adminUsers(), 404);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [], ['reason' => 'motivo']);

        $this->blocking->block($user, $request->user(), trim((string) $validated['reason']));

        return back()->with('success', 'Conta de '.$user->name.' bloqueada.');
    }

    public function unblock(Request $request, User $user): RedirectResponse
    {
        abort_unless(ToolFlags::adminUsers(), 404);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ], [], ['reason' => 'observação']);

        $this->blocking->unblock($user, $request->user(), isset($validated['reason']) ? trim((string) $validated['reason']) : null);

        return back()->with('success', 'Conta de '.$user->name.' desbloqueada.');
    }

    /**
     * @param  LengthAwarePaginator<int, User>  $page
     * @return list<array<string, mixed>>
     */
    private function rows(LengthAwarePaginator $page, User $actor): array
    {
        /** @var Collection<int, User> $users */
        $users = collect($page->items());
        $ids = array_values($users->map(fn (User $user): int => (int) $user->getKey())->all());

        $memberships = Membership::query()
            ->with('organization:id,ulid,name')
            ->whereIn('user_id', $ids)
            ->whereHas('organization')
            ->orderBy('id')
            ->get()
            ->groupBy('user_id');

        $sessions = $this->lastSessionActivity($ids);

        return array_values($users->map(function (User $user) use ($memberships, $sessions, $actor): array {
            $blockedAt = $user->getAttribute('blocked_at');
            $lastSeen = $user->getAttribute('last_seen_at') ?? $sessions[$user->getKey()] ?? null;
            /** @var Collection<int, Membership> $own */
            $own = $memberships->get($user->getKey(), collect());

            return [
                'id' => (string) $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'initials' => $user->initials,
                'is_platform_admin' => (bool) $user->is_platform_admin,
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                'email_verified' => $user->email_verified_at !== null,
                'created_at' => $user->created_at?->toIso8601String(),
                'last_seen_at' => $lastSeen !== null ? Carbon::parse($lastSeen)->toIso8601String() : null,
                'blocked' => $blockedAt !== null,
                'blocked_at' => $blockedAt !== null ? Carbon::parse($blockedAt)->toIso8601String() : null,
                'blocked_reason' => $user->getAttribute('blocked_reason'),
                'organizations' => $own->map(fn (Membership $m): array => [
                    'id' => $m->organization->ulid,
                    'name' => $m->organization->name,
                    'role_label' => $m->roleLabel(),
                    'active' => $m->status === MembershipStatus::Active,
                ])->values()->all(),
                'can' => [
                    'block' => $blockedAt === null && ! $user->is_platform_admin && ! $user->is($actor),
                    'unblock' => $blockedAt !== null,
                ],
            ];
        })->all());
    }

    /**
     * Último `last_activity` da tabela de sessões (driver database) — complemento de
     * `users.last_seen_at` para contas que ainda não passaram pela aplicação com a flag.
     *
     * @param  list<int>  $userIds
     * @return array<int, Carbon>
     */
    private function lastSessionActivity(array $userIds): array
    {
        if ($userIds === [] || config('session.driver') !== 'database') {
            return [];
        }

        return DB::connection(config('session.connection'))
            ->table((string) config('session.table', 'sessions'))
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->selectRaw('user_id, max(last_activity) as last_activity')
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->user_id => Carbon::createFromTimestamp((int) $row->last_activity)])
            ->all();
    }
}
