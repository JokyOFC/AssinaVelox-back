<?php

namespace App\Http\Controllers\Members;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\StoreTeamRequest;
use App\Http\Requests\Teams\UpdateTeamRequest;
use App\Models\Folder;
use App\Models\Team;
use App\Support\CurrentOrganization;
use App\Support\Permissions;
use App\Support\PermissionsFolderAccess;
use App\Support\PermissionsTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Times (Fase 2 §2.14, flag `custom_roles`): nome, participantes e pastas liberadas. Um
 * time não concede permissões de conta — só acesso a pastas.
 */
class TeamController extends Controller
{
    public function store(StoreTeamRequest $request): RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();

        if ($request->hasFoldersInput()) {
            Gate::authorize('grantAccess', Folder::class);
        }

        $team = DB::transaction(function () use ($request, $organization): Team {
            $team = new Team;
            $team->forceFill([
                'organization_id' => $organization->getKey(),
                'name' => $request->validated('name'),
                'description' => $request->validated('description'),
                'created_by_user_id' => $request->user()->getKey(),
            ])->save();

            $team->memberships()->sync($request->memberIds());

            if ($request->hasFoldersInput()) {
                PermissionsFolderAccess::sync($organization->getKey(), PermissionsFolderAccess::SUBJECT_TEAM, (int) $team->getKey(), $request->grants(), $request->user()->getKey());
            }

            return $team;
        });

        PermissionsTrail::record($organization->getKey(), AuditEventType::TeamCreated, [
            'team' => $team->ulid,
            'name' => $team->name,
            'members_count' => count($request->memberIds()),
            'folders_count' => count($request->grants()),
        ]);

        Permissions::forgetCounts($organization->getKey());

        return back()->with('success', 'Time "'.$team->name.'" criado.');
    }

    public function update(UpdateTeamRequest $request, Team $team): RedirectResponse
    {
        if ($request->hasFoldersInput()) {
            Gate::authorize('manageFolders', $team);
        }

        DB::transaction(function () use ($request, $team): void {
            $validated = $request->validated();

            if (array_key_exists('name', $validated)) {
                $team->name = $validated['name'];
            }

            if (array_key_exists('description', $validated)) {
                $team->description = $validated['description'];
            }

            $team->save();

            if ($request->hasMembersInput()) {
                $team->memberships()->sync($request->memberIds());
            }

            if ($request->hasFoldersInput()) {
                PermissionsFolderAccess::sync($team->organization_id, PermissionsFolderAccess::SUBJECT_TEAM, (int) $team->getKey(), $request->grants(), $request->user()->getKey());
            }
        });

        PermissionsTrail::record($team->organization_id, AuditEventType::TeamUpdated, [
            'team' => $team->ulid,
            'name' => $team->name,
            'members_changed' => $request->hasMembersInput(),
            'folders_changed' => $request->hasFoldersInput(),
        ]);

        Permissions::forgetCounts($team->organization_id);

        return back()->with('success', 'Time "'.$team->name.'" atualizado.');
    }

    public function destroy(Request $request, Team $team): RedirectResponse
    {
        Permissions::ensureCustomRoles(CurrentOrganization::instance()->get());
        Gate::authorize('delete', $team);

        $name = $team->name;
        $team->delete();

        PermissionsTrail::record($team->organization_id, AuditEventType::TeamDeleted, [
            'team' => $team->ulid,
            'name' => $name,
        ]);

        Permissions::forgetCounts($team->organization_id);

        return back()->with('success', "Time \"{$name}\" excluído. As pastas liberadas por ele deixaram de valer.");
    }
}
