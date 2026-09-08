<?php

namespace App\Http\Requests\Settings;

use App\Enums\SigningOrder;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /configuracoes/assinatura (ROUTES §2.13).
 */
class UpdateSigningSettingsRequest extends FormRequest
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
        return [
            'expires_in_days' => [
                'required',
                'integer',
                'min:'.(int) config('assinavelox.expiration_days.min', 1),
                'max:'.(int) config('assinavelox.expiration_days.max', 90),
            ],
            'signing_order' => ['required', 'string', Rule::enum(SigningOrder::class)],
            'initials_on_all_pages' => ['required', 'boolean'],
            'allow_typed_signature' => ['required', 'boolean'],
            'allow_uploaded_signature' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'expires_in_days' => 'prazo para assinatura',
            'signing_order' => 'ordem de assinatura',
            'initials_on_all_pages' => 'rubrica automática em todas as páginas',
            'allow_typed_signature' => 'permitir assinatura digitada',
            'allow_uploaded_signature' => 'permitir imagem de assinatura',
        ];
    }
}
