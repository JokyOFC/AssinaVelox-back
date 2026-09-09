<?php

namespace App\Http\Requests\Sign;

use App\Services\Signing\Challenges;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Forma do código: 6 dígitos. Se ele é o código certo, quem decide é
 * {@see Challenges}, comparando HMAC em tempo constante.
 *
 * A autorização é do middleware `ResolveSignerToken`: chegar aqui já significa que o link
 * resolveu.
 */
class VerifyOtpRequest extends FormRequest
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
            'code' => ['required', 'string', 'digits:'.(int) config('assinavelox.otp.code_length', 6)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['code' => 'código'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Informe o código enviado para o seu e-mail.',
            'code.digits' => 'O código tem :digits dígitos.',
        ];
    }

    public function code(): string
    {
        return (string) $this->string('code');
    }
}
