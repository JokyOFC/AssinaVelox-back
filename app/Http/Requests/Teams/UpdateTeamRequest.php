<?php

namespace App\Http\Requests\Teams;

use App\Enums\FolderAccessLevel;
use App\Models\Team;
use App\Support\PermissionsFolderAccess;
use Illuminate\Validation\Rule;

/**
 * PATCH /usuarios/times/{team}: campos opcionais; `members[]` e `folders[]`, quando
 * enviados, SUBSTITUEM a lista atual. Mesma regra anti-escalada da criação, sobre o
 * estado resultante.
 */
class UpdateTeamRequest extends StoreTeamRequest
{
    public function authorize(): bool
    {
        $this->ensureFeature();

        $team = $this->route('team');

        return $team instanceof Team && ($this->user()?->can('update', $team) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $team = $this->route('team');

        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:80', $this->uniqueName($team instanceof Team ? $team : null)],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'members' => ['sometimes', 'array', 'max:1000'],
            'members.*' => ['integer', 'distinct'],
            'folders' => ['sometimes', 'array', 'max:500'],
            'folders.*.folder' => ['required', 'string', 'size:26'],
            'folders.*.level' => ['required', 'string', Rule::enum(FolderAccessLevel::class)],
        ];
    }

    public function hasMembersInput(): bool
    {
        return array_key_exists('members', $this->validated());
    }

    /**
     * @return array{members: list<int>, folders: array<int, FolderAccessLevel>}
     */
    protected function resultingState(): array
    {
        $team = $this->route('team');

        if (! $team instanceof Team) {
            return parent::resultingState();
        }

        return [
            'members' => $this->hasMembersInput()
                ? $this->memberIds()
                : array_values($team->memberships()->pluck('memberships.id')->map(fn ($id): int => (int) $id)->all()),
            'folders' => $this->hasFoldersInput()
                ? $this->grants()
                : PermissionsFolderAccess::grantsFor($team->organization_id, PermissionsFolderAccess::SUBJECT_TEAM, (int) $team->getKey()),
        ];
    }
}
