<?php

namespace App\Http\Requests\Settings;

use App\Services\Branding\BrandingLimits;
use App\Services\Branding\ColorContrast;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * PATCH /configuracoes/marca — nome de exibição, cores, Reply-To e remetente desejado.
 *
 * As cores passam por {@see ColorContrast}: combinação ilegível é recusada aqui, no
 * servidor, com a razão calculada na mensagem (a tela mostra a mesma conta, mas não decide).
 */
class BrandingUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = CurrentOrganization::instance()->get();

        return $organization !== null && ($this->user()?->can('updateSettings', $organization) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $color = function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== null && $value !== '' && ColorContrast::normalize(is_string($value) ? $value : null) === null) {
                $fail('Use uma cor no formato #RRGGBB (ex.: #1257C9).');
            }
        };

        $noLineBreak = function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && preg_match('/[\r\n]/', $value) === 1) {
                $fail('Informe um único endereço de e-mail.');
            }
        };

        return [
            'display_name' => ['sometimes', 'nullable', 'string', 'max:'.BrandingLimits::MAX_DISPLAY_NAME],
            'primary_color' => ['sometimes', 'nullable', 'string', 'max:7', $color],
            'accent_color' => ['sometimes', 'nullable', 'string', 'max:7', $color],
            'reply_to_email' => ['sometimes', 'nullable', 'string', 'max:'.BrandingLimits::MAX_EMAIL, $noLineBreak, 'email:rfc,strict'],
            'sender_email' => ['sometimes', 'nullable', 'string', 'max:'.BrandingLimits::MAX_EMAIL, $noLineBreak, 'email:rfc,strict'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $primary = ColorContrast::normalize($this->string('primary_color')->toString() ?: null);
            $accent = ColorContrast::normalize($this->string('accent_color')->toString() ?: null);

            if ($primary !== null && ($problem = ColorContrast::primaryProblem($primary)) !== null) {
                $validator->errors()->add('primary_color', $problem);
            }

            if ($accent !== null && ($problem = ColorContrast::accentProblem($accent)) !== null) {
                $validator->errors()->add('accent_color', $problem);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'display_name' => 'nome de exibição',
            'primary_color' => 'cor primária',
            'accent_color' => 'cor de destaque',
            'reply_to_email' => 'e-mail para respostas',
            'sender_email' => 'remetente próprio',
        ];
    }
}
