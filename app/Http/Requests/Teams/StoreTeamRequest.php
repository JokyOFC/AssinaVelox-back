<?php

namespace App\Http\Requests\Teams;

use App\Enums\FolderAccessLevel;
use App\Enums\Permission;
use App\Http\Requests\Roles\Concerns\ResolvesAccessActor;
use App\Models\Membership;
use App\Models\Team;
use App\Support\PermissionsFolderAccess;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /usuarios/times: `name`, `description?`, `members[]` (ids de membership da
 * organização corrente) e `folders[]` opcional ({folder, level}; exige `manage_folders`).
 *
 * Anti-escalada: se o próprio ator estiver entre os participantes, o time não pode dar
 * acesso a uma pasta (ou nível) que ele ainda não tem.
 */
class StoreTeamRequest extends FormRequest
{
    use ResolvesAccessActor;

    public function authorize(): bool
    {
        $this->ensureFeature();

        return $this->user()?->can('create', Team::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:80', $this->uniqueName()],
            'description' => ['nullable', 'string', 'max:255'],
            'members' => ['sometimes', 'array', 'max:1000'],
            'members.*' => ['integer', 'distinct'],
            'folders' => ['sometimes', 'array', 'max:500'],
            'folders.*.folder' => ['required', 'string', 'size:26'],
            'folders.*.level' => ['required', 'string', Rule::enum(FolderAccessLevel::class)],
        ];
    }

    /**
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkEscalation($validator)];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'nome', 'description' => 'descrição', 'members' => 'participantes', 'folders' => 'pastas'];
    }

    /**
     * Ids das memberships informadas que pertencem à organização corrente.
     *
     * @return list<int>
     */
    public function memberIds(): array
    {
        $organization = $this->organization();
        $ids = array_map(intval(...), (array) $this->validated('members', []));

        if ($organization === null || $ids === []) {
            return [];
        }

        return array_values(Membership::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all());
    }

    public function hasFoldersInput(): bool
    {
        return array_key_exists('folders', $this->validated());
    }

    /**
     * @return array<int, FolderAccessLevel>
     */
    public function grants(): array
    {
        $organization = $this->organization();

        return $organization === null ? [] : PermissionsFolderAccess::parse((int) $organization->getKey(), (array) $this->validated('folders', []));
    }

    /**
     * Participantes e pastas que o time TERÁ depois desta requisição.
     *
     * @return array{members: list<int>, folders: array<int, FolderAccessLevel>}
     */
    protected function resultingState(): array
    {
        return [
            'members' => $this->memberIds(),
            'folders' => $this->grants(),
        ];
    }

    protected function checkEscalation(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty() || ($actor = $this->actor()) === null) {
            return;
        }

        if ($this->hasFoldersInput() && $this->grants() !== [] && ! $actor->hasPermission(Permission::ManageFolders)) {
            $validator->errors()->add('folders', 'Você não pode definir as pastas de um time.');

            return;
        }

        $state = $this->resultingState();

        if (in_array((int) $actor->getKey(), $state['members'], true)
            && ! PermissionsFolderAccess::actorCovers($actor, $state['folders'])) {
            $validator->errors()->add('members', 'Você não pode se incluir num time que dá acesso a pastas que você ainda não tem.');
        }
    }

    protected function uniqueName(?Team $ignore = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignore): void {
            $organization = $this->organization();

            if ($organization === null || ! is_string($value)) {
                return;
            }

            $taken = Team::forOrganization($organization)
                ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore->getKey()))
                ->get(['id', 'name'])
                ->contains(fn (Team $team): bool => Str::lower($team->name) === Str::lower($value));

            if ($taken) {
                $fail('Já existe um time com esse nome.');
            }
        };
    }
}
