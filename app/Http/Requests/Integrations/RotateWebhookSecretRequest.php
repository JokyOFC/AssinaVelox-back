<?php

namespace App\Http\Requests\Integrations;

use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Rotação do segredo. `overlap_hours` (opcional): quanto tempo o segredo anterior continua
 * assinando; 0 encerra na hora. Sem o campo vale `webhooks.secret_rotation_overlap_hours`.
 */
class RotateWebhookSecretRequest extends FormRequest
{
    public function authorize(): bool
    {
        $endpoint = $this->route('webhookEndpoint');

        // O segredo novo aparece para quem rotaciona: só o responsável ou quem vê tudo (`redirect`).
        return $endpoint instanceof WebhookEndpoint && Gate::allows('update', $endpoint) && Gate::allows('redirect', $endpoint);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'overlap_hours' => ['nullable', 'integer', 'min:0', 'max:'.(int) config('assinavelox.webhooks.max_rotation_overlap_hours', 168)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['overlap_hours' => 'período de convivência'];
    }
}
