<?php

namespace App\Policies;

use App\Models\Folder;
use App\Models\User;
use App\Policies\Concerns\ResolvesMembership;

/**
 * Pastas: qualquer membro vê; criar/renomear/excluir só owner/admin (manage_folders).
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
        return $this->isAdmin($user, $folder?->organization_id);
    }
}
