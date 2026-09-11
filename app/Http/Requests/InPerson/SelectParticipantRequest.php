<?php

namespace App\Http\Requests\InPerson;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Chamar um participante na fila do dispositivo (`in_person.kiosk.participant`).
 */
class SelectParticipantRequest extends FormRequest
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
            'recipient' => ['required', 'string', 'size:26', 'alpha_num'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recipient.required' => 'Escolha quem vai usar o dispositivo agora.',
            'recipient.size' => 'Escolha quem vai usar o dispositivo agora.',
            'recipient.alpha_num' => 'Escolha quem vai usar o dispositivo agora.',
        ];
    }

    public function recipientUlid(): string
    {
        return strtoupper((string) $this->string('recipient'));
    }
}
