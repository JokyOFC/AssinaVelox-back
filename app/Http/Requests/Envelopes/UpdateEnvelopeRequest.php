<?php

namespace App\Http\Requests\Envelopes;

use App\Models\Envelope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH envelopes.update — autosave dos metadados do passo 1/4 do wizard (ROUTES §2.6).
 * Todos os campos são `sometimes`: o front manda só o que mudou (debounce de 800 ms).
 */
class UpdateEnvelopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $envelope = $this->route('envelope');

        return $envelope instanceof Envelope && $this->user()?->can('update', $envelope) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Envelope $envelope */
        $envelope = $this->route('envelope');

        return [
            'title' => ['sometimes', 'required', 'string', 'min:3', 'max:160'],
            'folder_id' => [
                'sometimes', 'nullable', 'string', 'size:26',
                Rule::exists('folders', 'ulid')->where('organization_id', $envelope->organization_id),
            ],
            'expires_in_days' => [
                'sometimes', 'integer',
                'min:'.(int) config('assinavelox.expiration_days.min', 1),
                'max:'.(int) config('assinavelox.expiration_days.max', 90),
            ],
            'message' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'signing_order' => ['sometimes', 'in:sequential,parallel'],
            'send_copy_to_all' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'título',
            'folder_id' => 'pasta',
            'expires_in_days' => 'prazo para assinatura',
            'message' => 'mensagem',
            'signing_order' => 'ordem de assinatura',
            'send_copy_to_all' => 'enviar cópia a todos',
        ];
    }
}
