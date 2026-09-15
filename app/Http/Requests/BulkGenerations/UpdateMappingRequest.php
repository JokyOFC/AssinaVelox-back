<?php

namespace App\Http\Requests\BulkGenerations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mapeamento `coluna => destino` (destino vazio = ignorar a coluna). O conteúdo é conferido
 * contra o cabeçalho e a versão fixada do modelo em ColumnMapping::validate().
 */
class UpdateMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mapping' => ['present', 'array', 'max:200'],
            'mapping.*' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mapping.present' => 'Escolha as colunas da planilha.',
            'mapping.array' => 'O mapeamento enviado é inválido. Recarregue a página e tente de novo.',
            'mapping.max' => 'O mapeamento enviado é inválido. Recarregue a página e tente de novo.',
            'mapping.*.string' => 'O mapeamento enviado é inválido. Recarregue a página e tente de novo.',
            'mapping.*.max' => 'O mapeamento enviado é inválido. Recarregue a página e tente de novo.',
        ];
    }
}
