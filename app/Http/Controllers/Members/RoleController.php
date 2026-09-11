<?php

namespace App\Http\Controllers\Members;

use App\Enums\AuditEventType;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Members\SyncFolderAccessRequest;
use App\Http\Requests\Roles\StoreRoleRequest;
use App\Http\Requests\Roles\UpdateRoleRequest;
use App\Models\Membership;
use App\Models\Role;
use App\Support\CurrentOrganization;
use App\Support\Permissions;
use App\Support\PermissionsFolderAccess;
use App\Support\PermissionsTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Funções personalizadas (Fase 2 §2.14, flag `custom_roles`): criar, editar, excluir e
 * definir as pastas liberadas para quem tem a função. Papéis de sistema só recebem
 * pastas (nunca têm permissões editadas). Toda mudança invalida os contadores cacheados
 * da organização e entra na trilha.
 */
class RoleController extends Controller
{
    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $organization = CurrentOrganization::instance()->get();

        $role = DB::transaction(function () use ($request, $organization): Role {
            $role = new Role;
            $role->forceFill([
                'organization_id' => $organization->getKey(),
                'key' => null,
                'name' => $request->validated('name'),
                'description' => $request->validated('description'),
                'is_system' => false,
                'created_by_user_id' => $request->user()->getKey(),
            ])->save();

            $role->syncPermissions($request->permissions());

            return $role;
        });

        PermissionsTrail::record($organization->getKey(), AuditEventType::RoleCreated, [
            'role' => $role->ulid,
            'name' => $role->name,
            'permissions' => $role->grantedPermissionValues(),
        ]);

        return back()->with('success', 'Função "'.$role->name.'" criada.');
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $before = $role->grantedPermissionValues();

        DB::transaction(function () use ($request, $role): void {
            $validated = $request->validated();

            if (array_key_exists('name', $validated)) {
                $role->name = $validated['name'];
            }

            if (array_key_exists('description', $validated)) {
                $role->description = $validated['description'];
            }

            $role->save();

            if ($request->hasPermissionsInput()) {
                $role->syncPermissions($request->permissions());
            }
        });

        $after = $role->grantedPermissionValues();

        PermissionsTrail::record($role->organization_id, AuditEventType::RoleUpdated, [
            'role' => $role->ulid,
            'name' => $role->name,
            'added' => array_values(array_diff($after, $before)),
            'removed' => array_values(array_diff($before, $after)),
        ]);

        // Remover uma permissão tira o acesso na hora — inclusive das contagens cacheadas.
        Permissions::forgetCounts($role->organization_id);

        return back()->with('success', 'Função "'.$role->name.'" atualizada.');
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        Permissions::ensureCustomRoles(CurrentOrganization::instance()->get());
        Gate::authorize('delete', $role);

        $name = $role->name;

        // Excluir nunca pode DAR poder. Uma função personalizada pode ser mais restrita que
        // Operador (ex.: só relatórios); mover quem a tinha — ou os convites pendentes com ela
        // — para Operador concederia criar, enviar e exportar sem ninguém ter decidido isso.
        // Por isso a função só é excluída vazia: quem a tem é reatribuído antes (Usuários ›
        // Membros) e os convites pendentes são revogados.
        $members = Membership::query()
            ->where('organization_id', $role->organization_id)
            ->where('role_id', $role->getKey())
            ->count();

        $invitations = DB::table('membership_invitations')
            ->where('organization_id', $role->organization_id)
            ->where('role_id', $role->getKey())
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->count();

        if ($members > 0 || $invitations > 0) {
            return back()->withErrors(['role' => self::inUseMessage($name, $members, $invitations)]);
        }

        DB::transaction(function () use ($role): void {
            // Convites já aceitos ou revogados não oferecem mais nada; só soltam a referência.
            DB::table('membership_invitations')
                ->where('organization_id', $role->organization_id)
                ->where('role_id', $role->getKey())
                ->update(['role_id' => null]);

            $role->delete();
        });

        PermissionsTrail::record($role->organization_id, AuditEventType::RoleDeleted, [
            'role' => $role->ulid,
            'name' => $name,
        ]);

        Permissions::forgetCounts($role->organization_id);

        return back()->with('success', "Função \"{$name}\" excluída.");
    }

    private static function inUseMessage(string $name, int $members, int $invitations): string
    {
        $steps = [];

        if ($members > 0) {
            $steps[] = 'mude a função de '.($members === 1 ? '1 usuário' : "{$members} usuários").' em Membros';
        }

        if ($invitations > 0) {
            $steps[] = 'revogue '.($invitations === 1 ? '1 convite pendente' : "{$invitations} convites pendentes").' com esta função';
        }

        return "Antes de excluir a função \"{$name}\", ".implode(' e ', $steps).'. '
            .'Ninguém passa para Operador automaticamente, para não receber permissões que esta função não dava.';
    }

    public function folders(SyncFolderAccessRequest $request, Role $role): RedirectResponse
    {
        Gate::authorize('manageFolders', $role);

        $actor = CurrentOrganization::instance()->membership();
        $grants = $request->grants();

        // Anti-escalada: quem tem esta função não amplia o próprio acesso.
        if ($actor !== null && $actor->effectiveRoleId() === (int) $role->getKey()
            && ! PermissionsFolderAccess::actorCovers($actor, $grants)) {
            return back()->withErrors(['folders' => 'Você não pode liberar para a sua própria função pastas que você ainda não acessa.']);
        }

        $changed = PermissionsFolderAccess::sync(
            $role->organization_id,
            PermissionsFolderAccess::SUBJECT_ROLE,
            (int) $role->getKey(),
            $grants,
            $request->user()->getKey(),
        );

        if ($changed) {
            PermissionsTrail::record($role->organization_id, AuditEventType::FolderAccessUpdated, [
                'subject' => PermissionsFolderAccess::SUBJECT_ROLE,
                'role' => $role->ulid,
                'folders' => PermissionsFolderAccess::folderUlids($role->organization_id, array_keys($grants)),
            ]);

            Permissions::forgetCounts($role->organization_id);
        }

        return back()->with('success', 'Pastas da função "'.$role->name.'" atualizadas.');
    }

    /**
     * Permissões delegáveis que o ator pode oferecer (para a UI).
     *
     * @return list<string>
     */
    public static function grantableBy(Membership $actor): array
    {
        return array_values(array_map(
            fn (Permission $p): string => $p->value,
            array_filter($actor->grantedPermissions(), fn (Permission $p): bool => $p->isGrantable()),
        ));
    }
}
