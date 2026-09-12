<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Envelope;
use App\Services\Api\ApiEnvelopeAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/envelopes/{envelope}/cancel — motivo opcional (o mesmo campo da interface).
 */
class CancelEnvelopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Invisível → 404 antes de validar o corpo; a Policy `cancel` (403) fica no controller.
        $envelope = $this->route('envelope');

        if ($envelope instanceof Envelope) {
            ApiEnvelopeAccess::ensureVisible($envelope);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reason' => 'motivo'];
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }
}
