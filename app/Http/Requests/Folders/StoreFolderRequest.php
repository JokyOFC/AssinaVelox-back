<?php

namespace App\Http\Requests\Folders;

use App\Models\Folder;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /pastas (ROUTES §2.5): name required 2–60, único por organização (raiz).
 */
class StoreFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Folder::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $organizationId = CurrentOrganization::instance()->id();

        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:60',
                Rule::unique('folders', 'name')
                    ->where('organization_id', $organizationId)
                    ->whereNull('parent_id'),
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
