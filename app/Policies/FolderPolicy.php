<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Folder;
use App\Models\User;
use App\Policies\Concerns\ResolvesMembership;

/**
 * Pastas: qualquer membro vê a lista; criar/renomear/excluir e definir quem acessa cada
 * pasta exigem `manage_folders` (owner/admin nos papéis de sistema).
 */
class FolderPolicy
{
    use ResolvesMembership;

    public function viewAny(User $user): bool
    {
        return $this->membershipFor($user) !== null;
    }

    public function view(User $user, Folder $folder): bool
    {
        return $this->membershipFor($user, $folder->organization_id) !== null;
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, Folder $folder): bool
    {
        return $this->manage($user, $folder);
    }

    public function delete(User $user, Folder $folder): bool
    {
        return $this->manage($user, $folder);
    }

    public function manage(User $user, ?Folder $folder = null): bool
    {
        return $this->allows($user, Permission::ManageFolders, $folder?->organization_id);
    }

    /**
     * Conceder/retirar acesso à pasta (para funções, times ou pessoas).
     */
    public function grantAccess(User $user, ?Folder $folder = null): bool
    {
        return $this->manage($user, $folder);
    }
}
