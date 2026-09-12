<?php

namespace App\Http\Requests\Api;

use App\Enums\ApiAbility;
use App\Enums\Permission;
use App\Services\Api\ApiTokenManager;
use App\Support\CurrentOrganization;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Criação de token pela TELA de Integrações → Chaves (sessão web, grupo `app`; a rota é do
 * agente das telas). Valida o formato; a anti-escalada, o limite de chaves ativas e a flag
 * são conferidos de novo em App\Services\Api\ApiTokenManager::issue().
 */
class StoreApiTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return CurrentOrganization::instance()->membership()?->hasPermission(Permission::ManageIntegrations) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:'.ApiTokenManager::NAME_MAX],
            'abilities' => ['required', 'array', 'min:1', 'max:'.count(ApiAbility::cases())],
            'abilities.*' => ['required', 'string', 'distinct', Rule::in(ApiAbility::values())],
            'expires_at' => [
                'nullable', 'date', 'after:now',
                'before_or_equal:'.Carbon::now()->addDays(ApiTokenManager::maxExpirationDays())->toDateTimeString(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'abilities' => 'permissões da chave',
            'abilities.*' => 'permissão da chave',
            'expires_at' => 'validade',
        ];
    }

    /**
     * @return list<string>
     */
    public function abilities(): array
    {
        return array_values(array_map('strval', (array) $this->validated('abilities')));
    }

    public function expiresAt(): ?CarbonInterface
    {
        $value = $this->validated('expires_at');

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }
}
