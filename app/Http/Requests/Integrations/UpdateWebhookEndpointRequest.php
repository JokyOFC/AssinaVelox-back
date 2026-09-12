<?php

namespace App\Http\Requests\Integrations;

use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookEventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Alteração parcial de endpoint (URL, eventos, descrição). Mudar a URL revalida a rede.
 */
class UpdateWebhookEndpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        $endpoint = $this->route('webhookEndpoint');

        if (! $endpoint instanceof WebhookEndpoint || ! Gate::allows('update', $endpoint)) {
            return false;
        }

        // Trocar a URL redireciona as entregas: exige a policy `redirect` (responsável ou quem vê tudo).
        if ($this->has('url') && trim((string) $this->input('url')) !== $endpoint->url) {
            return Gate::allows('redirect', $endpoint);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['sometimes', 'required', 'string', 'max:2048'],
            'events' => ['sometimes', 'required', 'array', 'min:1'],
            'events.*' => ['required', 'string', 'distinct', Rule::in([WebhookEventType::ALL, ...WebhookEventType::subscribableValues()])],
            'description' => ['sometimes', 'nullable', 'string', 'max:160'],
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
