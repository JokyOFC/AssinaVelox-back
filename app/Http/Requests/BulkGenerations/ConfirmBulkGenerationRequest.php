<?php

namespace App\Http\Requests\BulkGenerations;

use App\Services\BulkGeneration\BulkGenerationManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Confirmação do lote: o que fazer com cada documento gerado.
 *  - `review`: nasce para revisão (pronto ou rascunho), nada é enviado;
 *  - `send`: enviado assim que nasce pronto;
 *  - `schedule`: agendado para `scheduled_for` (`Y-m-d\TH:i`, fuso da organização).
 */
class ConfirmBulkGenerationRequest extends FormRequest
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
            'mode' => ['required', 'string', Rule::in(BulkGenerationManager::MODES)],
            'scheduled_for' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mode.required' => 'Escolha o que fazer com os documentos gerados.',
            'mode.in' => 'Escolha o que fazer com os documentos gerados.',
        ];
    }
}
