<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Embed\EmbeddedSessionIssuer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Corpo de `POST /api/v1/envelopes/{envelope}/recipients/{recipient}/embedded-sessions`
 * (Fase 3 §3.9, docs/fase-3/widget-embutido.md §2). Só a forma; a origem é normalizada e
 * conferida contra a lista da organização no serviço.
 */
class StoreEmbeddedSigningSessionRequest extends FormRequest
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
            /** Origem exata do site que hospeda o widget, ex.: `https://app.cliente.com.br`. */
            'origin' => ['required', 'string', 'max:255'],
            /** Validade da URL de uso único, em segundos (60 a 900; padrão 300). */
            'expires_in' => ['nullable', 'integer', 'min:60', 'max:'.EmbeddedSessionIssuer::maxTtlSeconds()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'origin' => 'origem',
            'expires_in' => 'validade',
        ];
    }

    public function origin(): string
    {
        return trim((string) $this->input('origin'));
    }

    public function expiresIn(): ?int
    {
        $value = $this->input('expires_in');

        return $value === null || $value === '' ? null : (int) $value;
    }
}
