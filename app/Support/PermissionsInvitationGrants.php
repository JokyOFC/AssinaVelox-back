<?php

namespace App\Support;

use App\Enums\FolderAccessLevel;
use App\Enums\MembershipRole;
use App\Models\Membership;
use App\Models\MembershipInvitation;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Função personalizada e "Pastas com acesso" oferecidas num convite. Ficam no próprio
 * convite (`membership_invitations.role_id` + `folder_access`) e são aplicadas UMA vez,
 * quando o aceite cria a membership (InvitationAcceptController).
 *
 * Revalida tudo no aceite: função apagada ou pasta removida no meio do caminho
 * simplesmente não se aplica (a pessoa entra como Operador, sem pastas extras).
 */
final class PermissionsInvitationGrants
{
    /**
     * @param  array<int, FolderAccessLevel>  $folders  folder_id → nível
     */
    public static function store(MembershipInvitation $invitation, ?Role $role, array $folders): void
    {
        $payload = [];

        foreach ($folders as $folderId => $level) {
            $payload[] = ['folder_id' => (int) $folderId, 'level' => $level->value];
        }

        DB::table('membership_invitations')
            ->where('id', $invitation->getKey())
            ->update([
                'role_id' => $role?->getKey(),
                'folder_access' => $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            ]);
    }

    public static function roleFor(MembershipInvitation $invitation): ?Role
    {
        $roleId = DB::table('membership_invitations')->where('id', $invitation->getKey())->value('role_id');

        if ($roleId === null) {
            return null;
        }

        $role = Role::forOrganization($invitation->organization_id)->whereKey((int) $roleId)->first();

        return $role !== null && ! $role->is_system ? $role : null;
    }

    /**
     * @return array<int, FolderAccessLevel>
     */
    public static function foldersFor(MembershipInvitation $invitation): array
    {
        $raw = DB::table('membership_invitations')->where('id', $invitation->getKey())->value('folder_access');
        $items = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($items)) {
            return [];
        }

        $folders = [];

        foreach ($items as $item) {
            $level = is_array($item) ? FolderAccessLevel::tryFrom((string) ($item['level'] ?? '')) : null;

            if ($level !== null && is_numeric($item['folder_id'] ?? null)) {
                $folders[(int) $item['folder_id']] = $level;
            }
        }

        return $folders;
    }

    /**
     * Aplica o que o convite oferecia a uma membership recém-criada pelo aceite.
     */
    public static function apply(MembershipInvitation $invitation, Membership $membership): void
    {
        if ($membership->organization_id !== $invitation->organization_id || $membership->isOwner()) {
            return;
        }

        $role = self::roleFor($invitation);

        if ($role !== null) {
            $membership->forceFill(['role' => MembershipRole::Member, 'role_id' => $role->getKey()])->save();
        }

        $folders = self::foldersFor($invitation);

        if ($folders !== []) {
            PermissionsFolderAccess::sync(
                $membership->organization_id,
                PermissionsFolderAccess::SUBJECT_MEMBERSHIP,
                (int) $membership->getKey(),
                $folders,
                $invitation->invited_by_user_id,
            );
        }

        $membership->forgetPermissions();
    }

    /**
     * Rótulo da função oferecida (nome da função personalizada, se houver).
     */
    public static function roleLabel(MembershipInvitation $invitation): string
    {
        return self::roleFor($invitation)->name ?? $invitation->role->label();
    }
}
