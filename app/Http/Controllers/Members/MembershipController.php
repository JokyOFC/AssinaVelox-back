<?php

namespace App\Http\Controllers\Members;

use App\Enums\AuditEventType;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Requests\Members\UpdateMembershipRoleRequest;
use App\Http\Requests\Members\UpdateMembershipStatusRequest;
use App\Http\Resources\FolderResource;
use App\Http\Resources\MembershipInvitationResource;
use App\Http\Resources\MembershipResource;
use App\Models\Folder;
use App\Models\FolderPermission;
use App\Models\Membership;
use App\Models\MembershipInvitation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Policies\MembershipPolicy;
use App\Services\Organizations\SeatUsage;
use App\Support\CurrentOrganization;
use App\Support\Permissions;
use App\Support\PermissionsFolderAccess;
use App\Support\PermissionsSystemRoles;
use App\Support\PermissionsTrail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Usuários da conta (ROUTES §2.10). Rotas sob `org` + `org.role:owner,admin`
 * (transferência: `org.role:owner`); as Policies decidem por permissão.
 *
 * Fase 2 (flag `custom_roles`, docs/fase-2/permissoes-e-times.md): funções
 * personalizadas no seletor de função, matriz editável, times e "Pastas com acesso".
 * Com a flag desligada a página traz só os papéis de sistema, como na Fase 1.
 */
class MembershipController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Membership::class);

        $current = CurrentOrganization::instance();
        $organization = $current->get();
        $customRoles = Permissions::customRolesEnabled($organization);

        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(['members', 'roles', 'teams'])],
            'q' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', 'string', 'max:26'],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'invited'])],
        ]);

        $q = trim((string) ($filters['q'] ?? ''));
        [$systemFilter, $customFilter] = $this->roleFilter($filters['role'] ?? null, $customRoles);

        $members = Membership::query()
            ->with(['user', 'assignedRole', 'teams', 'folderPermissions.folder'])
            ->where('organization_id', $organization->getKey())
            ->when($q !== '', fn ($query) => $query->whereHas('user', fn ($user) => $user
                ->where('name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")))
            ->when($systemFilter !== null, fn (Builder $query) => $this->whereSystemRole($query, $systemFilter))
            ->when($customFilter !== null, fn (Builder $query) => $query->where('role_id', $customFilter?->getKey())->where('role', '!=', MembershipRole::Owner->value))
            ->when(in_array($filters['status'] ?? null, ['active', 'suspended'], true), fn ($query) => $query->where('status', $filters['status']))
            ->when(($filters['status'] ?? null) === 'invited', fn ($query) => $query->whereRaw('1 = 0'))
            ->get()
            ->sortBy([
                fn (Membership $a, Membership $b) => $b->role->weight() <=> $a->role->weight(),
                fn (Membership $a, Membership $b) => strcmp($a->user->name, $b->user->name),
            ])
            ->values();

        $invitations = $organization->invitations()
            ->with('inviter')
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->when($q !== '', fn ($query) => $query->where('email', 'like', "%{$q}%"))
            ->when($systemFilter !== null, fn (Builder $query) => $this->whereSystemRole($query, $systemFilter))
            ->when($customFilter !== null, fn (Builder $query) => $query->where('role_id', $customFilter?->getKey()))
            ->when(($filters['status'] ?? null) === 'active' || ($filters['status'] ?? null) === 'suspended', fn ($query) => $query->whereRaw('1 = 0'))
            ->orderByDesc('created_at')
            ->get();

        $customRoleModels = $customRoles
            ? Role::query()->where('is_system', false)->with('permissionRows')->orderBy('name')->get()
            : new Collection;

        return Inertia::render('members/index', [
            'tab' => $filters['tab'] ?? 'members',
            'seats' => SeatUsage::for($organization),
            'filters' => [
                'q' => $q,
                'role' => $filters['role'] ?? null,
                'status' => $filters['status'] ?? null,
            ],
            'members' => $this->presentMembers($request, $members, $customRoles),
            'invitations' => $this->presentInvitations($request, $invitations),
            'roles' => Permissions::roles(),
            'permission_matrix' => Permissions::matrix(),
            'folders' => FolderResource::collection(Folder::query()->whereNull('parent_id')->orderBy('name')->get())->resolve($request),
            // Fase 2 — funções, times e acesso por pasta.
            'custom_roles_enabled' => $customRoles,
            'permission_catalog' => Permissions::catalog(),
            'role_catalog' => $this->roleCatalog($request->user(), $organization, $customRoleModels, $customRoles),
            'teams' => $customRoles ? $this->presentTeams($request->user(), $organization) : [],
            'can' => [
                'create_role' => $customRoles && $request->user()->can('create', Role::class),
                'create_team' => $customRoles && $request->user()->can('create', Team::class),
                'manage_folder_access' => $customRoles && $request->user()->can('grantAccess', Folder::class),
            ],
        ]);
    }

    public function update(UpdateMembershipRoleRequest $request, Membership $membership): RedirectResponse
    {
        $role = $request->role();
        $custom = $request->customRole();

        if ($membership->isOwner() && MembershipPolicy::isLastActiveOwner($membership)) {
            return back()->with('error', 'A organização precisa ter pelo menos um proprietário. Transfira a propriedade antes.');
        }

        $from = $membership->customRole()->ulid ?? $membership->role->value;

        $membership->forceFill(['role' => $role, 'role_id' => $custom?->getKey()])->save();
        $membership->forgetPermissions();

        $to = $custom->ulid ?? $role->value;

        if ($from !== $to) {
            PermissionsTrail::record($membership->organization_id, AuditEventType::MembershipRoleChanged, [
                'membership' => (int) $membership->getKey(),
                'from' => $from,
                'to' => $to,
            ]);
        }

        HandleInertiaRequests::forgetCounts($membership->organization_id, $membership->user_id);

        return back()->with('success', 'Função de '.$membership->user->name.' alterada para '.$request->targetLabel().'.');
    }

    public function updateStatus(UpdateMembershipStatusRequest $request, Membership $membership): RedirectResponse
    {
        $status = $request->status();

        if ($status === MembershipStatus::Suspended && $membership->isOwner() && MembershipPolicy::isLastActiveOwner($membership)) {
            return back()->with('error', 'Não é possível suspender o único proprietário da organização.');
        }

        // Reativar ocupa um assento: só é permitido se o plano ainda tiver assento livre.
        if ($status === MembershipStatus::Active && $membership->status !== MembershipStatus::Active) {
            $organization = CurrentOrganization::instance()->get();

            if (! SeatUsage::hasAvailable($organization, 1)) {
                return back()->with('error', SeatUsage::unavailableMessage($organization));
            }
        }

        $membership->forceFill(['status' => $status])->save();

        if ($status === MembershipStatus::Suspended && $membership->user->current_organization_id === $membership->organization_id) {
            $membership->user->forceFill(['current_organization_id' => null])->save();
        }

        return back()->with('success', $status === MembershipStatus::Suspended
            ? $membership->user->name.' foi suspenso(a). Ele(a) não poderá acessar esta organização.'
            : $membership->user->name.' foi reativado(a).');
    }

    public function destroy(Request $request, Membership $membership): RedirectResponse
    {
        Gate::authorize('delete', $membership);

        if ($membership->isOwner() && MembershipPolicy::isLastActiveOwner($membership)) {
            return back()->with('error', 'Não é possível remover o único proprietário da organização.');
        }

        $name = $membership->user->name;

        DB::transaction(function () use ($membership): void {
            if ($membership->user->current_organization_id === $membership->organization_id) {
                $membership->user->forceFill(['current_organization_id' => null])->save();
            }

            // Envelopes criados permanecem (created_by_user_id preservado). Times e acesso
            // direto por pasta saem em cascata (FK).
            $membership->delete();
        });

        return back()->with('success', "{$name} foi removido(a) da organização.");
    }

    public function transferOwnership(Request $request, Membership $membership): RedirectResponse
    {
        Gate::authorize('transferOwnership', $membership);

        $actor = $request->user();

        DB::transaction(function () use ($membership, $actor): void {
            $current = Membership::query()
                ->where('organization_id', $membership->organization_id)
                ->where('user_id', $actor->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Owner e Administrador são papéis de sistema: nenhuma função personalizada fica.
            $membership->forceFill(['role' => MembershipRole::Owner, 'role_id' => null])->save();
            $current->forceFill(['role' => MembershipRole::Admin, 'role_id' => null])->save();
        });

        HandleInertiaRequests::forgetCounts($membership->organization_id, $actor->getKey());
        HandleInertiaRequests::forgetCounts($membership->organization_id, $membership->user_id);

        return back()->with('success', 'Propriedade transferida para '.$membership->user->name.'. Você agora é Administrador.');
    }

    // -- Apresentação ------------------------------------------------------------------------

    /**
     * Filtro "Função": papel de sistema (owner|admin|member) ou ulid de função
     * personalizada (só com a flag). Valor desconhecido é ignorado.
     *
     * @return array{0: MembershipRole|null, 1: Role|null}
     */
    protected function roleFilter(?string $value, bool $customRoles): array
    {
        if ($value === null || $value === '') {
            return [null, null];
        }

        $system = MembershipRole::tryFrom($value);

        if ($system !== null) {
            return [$system, null];
        }

        if (! $customRoles) {
            return [null, null];
        }

        return [null, Role::query()->where('ulid', $value)->where('is_system', false)->first()];
    }

    /**
     * Papel de sistema "puro": Operador exclui quem tem função personalizada.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function whereSystemRole(Builder $query, MembershipRole $role): Builder
    {
        $query->where('role', $role->value);

        if ($role === MembershipRole::Member) {
            $query->whereNull('role_id');
        }

        return $query;
    }

    /**
     * @param  Collection<int, Membership>|\Illuminate\Support\Collection<int, Membership>  $members
     * @return array<int, array<string, mixed>>
     */
    protected function presentMembers(Request $request, $members, bool $customRoles): array
    {
        $rows = MembershipResource::collection($members)->resolve($request);
        $user = $request->user();

        foreach ($members->values() as $index => $membership) {
            $custom = $membership->customRole();

            $rows[$index]['role_label'] = $membership->roleLabel();
            $rows[$index]['role_id'] = $custom?->ulid;
            $rows[$index]['teams'] = $customRoles
                ? $membership->teams->sortBy('name')->map(fn (Team $team): array => ['id' => $team->ulid, 'name' => $team->name])->values()->all()
                : [];
            $rows[$index]['folders'] = $customRoles
                ? $membership->folderPermissions
                    ->sortBy(fn (FolderPermission $grant): string => $grant->folder->name)
                    ->map(fn (FolderPermission $grant): array => ['folder' => $grant->folder->ulid, 'name' => $grant->folder->name, 'level' => $grant->level->value])
                    ->values()
                    ->all()
                : [];
            $rows[$index]['can']['manage_folders'] = $customRoles && $user->can('manageFolders', $membership);
        }

        return $rows;
    }

    /**
     * @param  Collection<int, MembershipInvitation>  $invitations
     * @return array<int, array<string, mixed>>
     */
    protected function presentInvitations(Request $request, Collection $invitations): array
    {
        $rows = MembershipInvitationResource::collection($invitations)->resolve($request);
        $roleIds = $invitations->map(fn (MembershipInvitation $i) => $i->getAttribute('role_id'))->filter()->unique()->values()->all();
        $names = $roleIds === [] ? collect() : Role::query()->whereIn('id', $roleIds)->where('is_system', false)->get(['id', 'ulid', 'name'])->keyBy('id');

        foreach ($invitations->values() as $index => $invitation) {
            $role = $names->get($invitation->getAttribute('role_id'));

            $rows[$index]['role_id'] = $role?->ulid;

            if ($role !== null) {
                $rows[$index]['role_label'] = $role->name;
            }
        }

        return $rows;
    }

    /**
     * Papéis de sistema (sempre) + funções personalizadas (só com a flag), com contagem de
     * membros, permissões, pastas liberadas e o que o ator pode fazer com cada um.
     *
     * @param  Collection<int, Role>  $customRoles
     * @return list<array<string, mixed>>
     */
    protected function roleCatalog(User $user, Organization $organization, Collection $customRoles, bool $enabled): array
    {
        $system = PermissionsSystemRoles::ensureFor($organization);
        $counts = $this->memberCounts($organization, $customRoles);
        $catalog = [];

        foreach ([MembershipRole::Owner, MembershipRole::Admin, MembershipRole::Member] as $key) {
            $role = $system->get($key->value);

            if ($role instanceof Role) {
                $catalog[] = $this->presentRole($user, $organization, $role, $counts[$key->value] ?? 0, $enabled, $key->label());
            }
        }

        foreach ($customRoles as $role) {
            $catalog[] = $this->presentRole($user, $organization, $role, $counts[$role->ulid] ?? 0, $enabled);
        }

        return $catalog;
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentRole(User $user, Organization $organization, Role $role, int $membersCount, bool $enabled, ?string $label = null): array
    {
        $isOwner = $role->systemRole() === MembershipRole::Owner;

        return [
            'id' => $role->ulid,
            'key' => $role->is_system ? $role->key : null,
            'name' => $label ?? $role->name,
            'description' => $role->description,
            'is_system' => $role->is_system,
            'permissions' => $role->grantedPermissionValues(),
            'members_count' => $membersCount,
            'folders' => $enabled && ! $isOwner
                ? PermissionsFolderAccess::present((int) $organization->getKey(), PermissionsFolderAccess::SUBJECT_ROLE, (int) $role->getKey())
                : [],
            'can' => [
                'update' => $enabled && $user->can('update', $role),
                'delete' => $enabled && $user->can('delete', $role),
                'manage_folders' => $enabled && ! $isOwner && $user->can('manageFolders', $role),
                'assign' => ! $isOwner && ($enabled || $role->is_system) && $user->can('assign', $role),
            ],
        ];
    }

    /**
     * Membros por função efetiva (mesma regra de Membership::customRole()).
     *
     * @param  Collection<int, Role>  $customRoles
     * @return array<string, int> key de sistema ou ulid da função → total
     */
    protected function memberCounts(Organization $organization, Collection $customRoles): array
    {
        $ulids = $customRoles->pluck('ulid', 'id');
        $counts = [];

        Membership::query()
            ->where('organization_id', $organization->getKey())
            ->get(['id', 'role', 'role_id'])
            ->each(function (Membership $membership) use ($ulids, &$counts): void {
                $key = ! $membership->isOwner() && $membership->role_id !== null && $ulids->has($membership->role_id)
                    ? $ulids->get($membership->role_id)
                    : $membership->role->value;

                $counts[$key] = ($counts[$key] ?? 0) + 1;
            });

        return $counts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function presentTeams(User $user, Organization $organization): array
    {
        return Team::query()
            ->with('memberships:id')
            ->orderBy('name')
            ->get()
            ->map(fn (Team $team): array => [
                'id' => $team->ulid,
                'name' => $team->name,
                'description' => $team->description,
                'member_ids' => $team->memberships->map(fn (Membership $m): string => (string) $m->getKey())->values()->all(),
                'folders' => PermissionsFolderAccess::present((int) $organization->getKey(), PermissionsFolderAccess::SUBJECT_TEAM, (int) $team->getKey()),
                'can' => [
                    'update' => $user->can('update', $team),
                    'delete' => $user->can('delete', $team),
                    'manage_folders' => $user->can('manageFolders', $team),
                ],
            ])
            ->values()
            ->all();
    }
}
