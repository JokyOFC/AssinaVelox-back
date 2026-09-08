<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /notificacoes/ler: `ids: string[]` (uuids) ou vazio = todas as não lidas.
 */
class MarkNotificationsReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'ids' => ['nullable', 'array', 'max:100'],
            'ids.*' => ['string', 'uuid'],
        ];
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_values((array) ($this->validated('ids') ?? []));
    }
}
