<?php

namespace App\Http\Requests\Folders;

use App\Models\Folder;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /pastas/{folder}: renomear (único por organização, ignorando a própria pasta).
 */
class UpdateFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $folder = $this->route('folder');

        return $folder instanceof Folder && ($this->user()?->can('update', $folder) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Folder $folder */
        $folder = $this->route('folder');
        $organizationId = CurrentOrganization::instance()->id();

        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:60',
                Rule::unique('folders', 'name')
                    ->where('organization_id', $organizationId)
                    ->where('parent_id', $folder->parent_id)
                    ->ignore($folder->getKey()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'nome da pasta'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.unique' => 'Já existe uma pasta com este nome.'];
    }
}
