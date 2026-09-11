<?php

namespace App\Http\Requests\PublicForms;

use App\Models\PublicForm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Criar um formulário (rascunho) a partir de um modelo da organização corrente.
 */
class StorePublicFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', PublicForm::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'template' => ['required', 'string', 'size:26'],
            'title' => ['nullable', 'string', 'max:160'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['template' => 'modelo', 'title' => 'título'];
    }
}
