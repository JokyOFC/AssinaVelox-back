<?php

namespace App\Http\Requests\Integrations;

use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookEventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Cadastro de endpoint de webhook. Aqui só a forma; a política de rede (https, DNS, faixas
 * bloqueadas, porta) é do OutboundUrlGuard, chamado pelo WebhookEndpointManager.
 */
class StoreWebhookEndpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', WebhookEndpoint::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'string', 'distinct', Rule::in([WebhookEventType::ALL, ...WebhookEventType::subscribableValues()])],
            'description' => ['nullable', 'string', 'max:160'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['url' => 'URL', 'events' => 'eventos', 'events.*' => 'evento', 'description' => 'descrição'];
    }
}
