<?php

namespace App\Http\Controllers\Members;

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
use App\Models\Membership;
use App\Policies\MembershipPolicy;
use App\Services\Organizations\SeatUsage;
use App\Support\CurrentOrganization;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Usuários da conta (ROUTES §2.10). Rotas sob `org` + `org.role:owner,admin`
 * (transferência: `org.role:owner`).
 */
class MembershipController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Membership::class);

        $organization = CurrentOrganization::instance()->get();

        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(['members', 'roles'])],
            'q' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', Rule::enum(MembershipRole::class)],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'invited'])],
        ]);

        $q = trim((string) ($filters['q'] ?? ''));

        $members = Membership::query()
            ->with('user')
            ->where('organization_id', $organization->getKey())
            ->when($q !== '', fn ($query) => $query->whereHas('user', fn ($user) => $user
                ->where('name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")))
            ->when(! empty($filters['role']), fn ($query) => $query->where('role', $filters['role']))
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
            ->when(! empty($filters['role']), fn ($query) => $query->where('role', $filters['role']))
            ->when(($filters['status'] ?? null) === 'active' || ($filters['status'] ?? null) === 'suspended', fn ($query) => $query->whereRaw('1 = 0'))
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('members/index', [
            'tab' => $filters['tab'] ?? 'members',
            'seats' => SeatUsage::for($organization),
            'filters' => [
                'q' => $q,
                'role' => $filters['role'] ?? null,
                'status' => $filters['status'] ?? null,
            ],
            'members' => MembershipResource::collection($members)->resolve($request),
            'invitations' => MembershipInvitationResource::collection($invitations)->resolve($request),
            'roles' => Permissions::roles(),
            'permission_matrix' => Permissions::matrix(),
            'folders' => FolderResource::collection(Folder::query()->whereNull('parent_id')->orderBy('name')->get())->resolve($request),
        ]);
    }

    public function update(UpdateMembershipRoleRequest $request, Membership $membership): RedirectResponse
    {
        $role = $request->role();

        if ($membership->isOwner() && MembershipPolicy::isLastActiveOwner($membership)) {
            return back()->with('error', 'A organização precisa ter pelo menos um proprietário. Transfira a propriedade antes.');
        }

        $membership->forceFill(['role' => $role])->save();

        HandleInertiaRequests::forgetCounts($membership->organization_id, $membership->user_id);

        return back()->with('success', 'Função de '.$membership->user->name.' alterada para '.$role->label().'.');
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

            // Envelopes criados permanecem (created_by_user_id preservado).
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

            $membership->forceFill(['role' => MembershipRole::Owner])->save();
            $current->forceFill(['role' => MembershipRole::Admin])->save();
        });

        HandleInertiaRequests::forgetCounts($membership->organization_id, $actor->getKey());
        HandleInertiaRequests::forgetCounts($membership->organization_id, $membership->user_id);

        return back()->with('success', 'Propriedade transferida para '.$membership->user->name.'. Você agora é Administrador.');
    }
}
