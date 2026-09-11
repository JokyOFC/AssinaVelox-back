<?php

namespace App\Http\Requests\Sign;

use App\Services\Signing\Channels\SenderPins;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Forma do PIN do remetente: só dígitos, 4 a 8. Se é o PIN certo, quem decide é
 * {@see SenderPins}, com password_verify.
 *
 * O PIN é retirado da entrada logo depois da validação (com ou sem sucesso): assim ele não
 * vai para `_old_input` na sessão num redirect de erro.
 */
class VerifyPinRequest extends FormRequest
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
            'pin' => ['required', 'string', 'digits_between:'.SenderPins::minLength().','.SenderPins::maxLength()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['pin' => 'PIN'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pin.required' => 'Informe o PIN combinado com quem enviou o documento.',
            'pin.digits_between' => 'O PIN tem de :min a :max dígitos.',
        ];
    }

    public function pin(): string
    {
        return (string) $this->validated('pin');
    }

    protected function passedValidation(): void
    {
        $this->forgetPin();
    }

    protected function failedValidation(Validator $validator): void
    {
        $this->forgetPin();

        parent::failedValidation($validator);
    }

    private function forgetPin(): void
    {
        $targets = [$this];
        $original = app('request');

        if ($original !== $this) {
            $targets[] = $original;
        }

        foreach ($targets as $request) {
            $request->getInputSource()->remove('pin');
            $request->request->remove('pin');
        }
    }
}
