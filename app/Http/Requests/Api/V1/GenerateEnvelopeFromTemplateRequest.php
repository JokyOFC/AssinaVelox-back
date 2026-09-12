<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/templates/{template}/envelopes — o mesmo corpo de "Usar modelo" na interface:
 *
 *  - `title` (opcional; padrão: nome do modelo);
 *  - `values`: `{chave_da_variavel: valor}`, validados por tipo no servidor
 *    (App\Services\Templates\VariableValues);
 *  - `participants`: `{ulid_do_papel: {name, email}}`, um por papel do modelo.
 *
 * A validação por tipo e por papel é de App\Services\Templates\CreateEnvelopeFromTemplate
 * (erros em `values.*`, `participants.*` e `template`).
 */
class GenerateEnvelopeFromTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Modelo utilizável e Policy `use` são conferidos no controller (409 / 403).
        return true;
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

    /**
     * @return array{title?: string|null, values?: array<string, mixed>|null, participants?: array<string, mixed>|null}
     */
    public function payload(): array
    {
        /** @var array{title?: string|null, values?: array<string, mixed>|null, participants?: array<string, mixed>|null} $validated */
        $validated = $this->validated();

        return $validated;
    }
}
