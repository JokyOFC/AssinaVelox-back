<?php

namespace App\Http\Requests\InPerson;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Abrir sessão presencial (`in_person.store`). Só a forma; flag, envelope e permissão são
 * conferidos no controller.
 */
class StartInPersonRequest extends FormRequest
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
            'envelope' => ['required', 'string', 'size:26', 'alpha_num'],
            'device_label' => ['required', 'string', 'min:2', 'max:80'],
            'keep_signed_in' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'envelope.required' => 'Escolha o documento da sessão presencial.',
            'envelope.size' => 'Escolha o documento da sessão presencial.',
            'envelope.alpha_num' => 'Escolha o documento da sessão presencial.',
            'device_label.required' => 'Dê um nome ao dispositivo (por exemplo, "Tablet do balcão").',
            'device_label.min' => 'O nome do dispositivo precisa de pelo menos 2 caracteres.',
            'device_label.max' => 'O nome do dispositivo aceita no máximo 80 caracteres.',
        ];
    }

    public function envelopeUlid(): string
    {
        return strtoupper((string) $this->string('envelope'));
    }

    public function deviceLabel(): string
    {
        return trim((string) $this->string('device_label'));
    }
}
