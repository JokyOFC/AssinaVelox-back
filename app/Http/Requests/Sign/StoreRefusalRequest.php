<?php

namespace App\Http\Requests\Sign;

use App\Services\Signing\RecordRefusal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Recusa (ROUTES §3.3): motivo obrigatório de 10 a 500 caracteres.
 *
 * O mínimo de 10 caracteres é deliberado: "não" não diz nada ao remetente, e o motivo é
 * enviado a ele e gravado em `recipients.refusal_reason`. O máximo protege a coluna e a
 * mensagem.
 */
class StoreRefusalRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:'.RecordRefusal::MIN_REASON, 'max:'.RecordRefusal::MAX_REASON],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reason' => 'motivo'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Informe o motivo da recusa.',
            'reason.min' => 'Explique o motivo com pelo menos :min caracteres.',
            'reason.max' => 'O motivo deve ter no máximo :max caracteres.',
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->string('reason'));
    }
}
