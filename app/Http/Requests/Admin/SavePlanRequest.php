<?php

namespace App\Http\Requests\Admin;

use App\Enums\PlanBillingPeriod;
use App\Models\Plan;
use App\Services\Plans\PlanFeatureCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /admin/planos e PATCH /admin/planos/{plan} (docs/cobranca.md §18).
 *
 * Preço em centavos (o front converte os reais digitados); cotas nulas = ilimitado;
 * armazenamento em GB (vira `features.storage_bytes`); `features` só aceita as chaves do
 * catálogo. O código só existe na criação — depois é a identidade do plano (rota, constantes
 * `Plan::CODE_*`, assinaturas) e não muda.
 *
 * O plano Grátis é o plano inicial de toda organização nova (`CreateOrganization`): não pode
 * ser desativado nem deixar de ser gratuito.
 */
class SavePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()->is_platform_admin ?? false) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'price_cents' => ['required', 'integer', 'min:0', 'max:99999999'],
            'billing_period' => ['required', Rule::enum(PlanBillingPeriod::class)],
            'envelope_quota' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'user_quota' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'storage_gb' => ['nullable', 'numeric', 'min:0.1', 'max:100000'],
            'features' => ['present', 'array'],
            'features.*' => ['boolean'],
            'is_active' => ['required', 'boolean'],
            'is_public' => ['required', 'boolean'],
            'is_sandbox' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:1000'],
        ];

        if ($this->plan() === null) {
            $rules['code'] = ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,31}$/', Rule::unique('plans', 'code')];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'O código usa letras minúsculas, números e sublinhado, começando por letra (ex.: profissional_anual).',
            'code.unique' => 'Já existe um plano com este código.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'código',
            'name' => 'nome',
            'description' => 'descrição',
            'price_cents' => 'preço',
            'billing_period' => 'período',
            'envelope_quota' => 'documentos por mês',
            'user_quota' => 'usuários',
            'storage_gb' => 'armazenamento',
            'features' => 'recursos',
            'sort_order' => 'ordem',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $unknown = array_diff(array_keys((array) $this->input('features', [])), PlanFeatureCatalog::keys());

            if ($unknown !== []) {
                $validator->errors()->add('features', 'Recurso desconhecido: '.implode(', ', $unknown).'.');
            }

            $code = $this->plan()->code ?? (string) $this->input('code');

            if ($code !== Plan::CODE_FREE) {
                return;
            }

            if (! $this->boolean('is_active')) {
                $validator->errors()->add('is_active', 'O plano Grátis é o plano inicial de toda organização nova e não pode ser desativado.');
            }

            if ((int) $this->input('price_cents') !== 0) {
                $validator->errors()->add('price_cents', 'O plano Grátis precisa continuar gratuito (R$ 0,00).');
            }
        });
    }

    private function plan(): ?Plan
    {
        $plan = $this->route('plan');

        return $plan instanceof Plan ? $plan : null;
    }
}
