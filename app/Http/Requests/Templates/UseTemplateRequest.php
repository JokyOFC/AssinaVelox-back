<?php

namespace App\Http\Requests\Templates;

use App\Models\Template;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST templates.use — valores das variáveis e participantes por papel. A validação por
 * tipo (CPF, data, número, lista…) e por papel é do servidor, em
 * CreateEnvelopeFromTemplate / VariableValues.
 */
class UseTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $template = $this->route('template');

        return $template instanceof Template && $this->user()?->can('use', $template) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:160'],
            'values' => ['nullable', 'array', 'max:100'],
            'participants' => ['nullable', 'array', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'título',
            'values' => 'valores',
            'participants' => 'participantes',
        ];
    }
}
