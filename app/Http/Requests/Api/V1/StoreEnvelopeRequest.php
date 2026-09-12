<?php

namespace App\Http\Requests\Api\V1;

use App\Support\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/envelopes — rascunho novo, com as mesmas regras de formato do passo 1 do
 * wizard (App\Http\Requests\Envelopes\UpdateEnvelopeRequest).
 */
class StoreEnvelopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // `create_envelopes` é conferida pela Policy no controller.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'message' => ['nullable', 'string', 'max:1000'],
            'signing_order' => ['nullable', 'in:sequential,parallel'],
            'expires_in_days' => [
                'nullable', 'integer',
                'min:'.(int) config('assinavelox.expiration_days.min', 1),
                'max:'.(int) config('assinavelox.expiration_days.max', 90),
            ],
            'folder_id' => [
                'nullable', 'string', 'size:26',
                Rule::exists('folders', 'ulid')->where('organization_id', CurrentOrganization::instance()->id()),
            ],
            'send_copy_to_all' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'título',
            'message' => 'mensagem',
            'signing_order' => 'ordem de assinatura',
            'expires_in_days' => 'prazo para assinatura',
            'folder_id' => 'pasta',
            'send_copy_to_all' => 'enviar cópia a todos',
        ];
    }

    /**
     * @return array{title: string, message?: string|null, signing_order?: string|null, expires_in_days?: int|string|null, folder_id?: string|null, send_copy_to_all?: bool|null}
     */
    public function payload(): array
    {
        /** @var array{title: string, message?: string|null, signing_order?: string|null, expires_in_days?: int|string|null, folder_id?: string|null, send_copy_to_all?: bool|null} $validated */
        $validated = $this->validated();

        return $validated;
    }
}
