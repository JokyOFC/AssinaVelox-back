<?php

namespace App\Http\Requests\Members;

use App\Enums\FolderAccessLevel;
use App\Http\Requests\Roles\Concerns\ResolvesAccessActor;
use App\Support\PermissionsFolderAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT …/pastas: `folders[] = {folder: ulid, level: view|manage}` (lista completa: o que
 * não vier é retirado). A autorização por sujeito (função, time, pessoa) fica no
 * controller; pastas de outra organização são descartadas em `grants()`.
 */
class SyncFolderAccessRequest extends FormRequest
{
    use ResolvesAccessActor;

    public function authorize(): bool
    {
        $this->ensureFeature();

        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'folders' => ['present', 'array', 'max:500'],
            'folders.*.folder' => ['required', 'string', 'size:26'],
            'folders.*.level' => ['required', 'string', Rule::enum(FolderAccessLevel::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['folders' => 'pastas', 'folders.*.folder' => 'pasta', 'folders.*.level' => 'nível de acesso'];
    }

    /**
     * @return array<int, FolderAccessLevel> folder_id → nível (só pastas da organização corrente)
     */
    public function grants(): array
    {
        $organization = $this->organization();

        return $organization === null ? [] : PermissionsFolderAccess::parse((int) $organization->getKey(), (array) $this->validated('folders', []));
    }
}
