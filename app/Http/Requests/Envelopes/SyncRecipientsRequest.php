<?php

namespace App\Http\Requests\Envelopes;

use App\Models\Envelope;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT envelopes.recipients.sync (ROUTES §2.6 passo 2). A autorização (`can:update`) e a
 * regra de status ficam no controller; aqui só o formato.
 */
class SyncRecipientsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $envelope = $this->route('envelope');

        return $envelope instanceof Envelope && $this->user()?->can('update', $envelope) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'signing_order' => ['required', 'in:sequential,parallel'],
            'recipients' => ['required', 'array', 'min:1', 'max:20'],
            'recipients.*.id' => ['nullable', 'string', 'size:26'],
            'recipients.*.name' => ['required', 'string', 'min:2', 'max:120'],
            'recipients.*.email' => ['required', 'string', 'email:rfc', 'max:255', 'distinct:ignore_case'],
            'recipients.*.role' => ['nullable', 'string', 'max:40'],
            'recipients.*.order' => ['nullable', 'integer', 'min:1', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'signing_order' => 'ordem de assinatura',
            'recipients' => 'signatários',
            'recipients.*.name' => 'nome',
            'recipients.*.email' => 'e-mail',
            'recipients.*.role' => 'papel',
            'recipients.*.order' => 'ordem',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recipients.required' => 'Adicione pelo menos um signatário.',
            'recipients.min' => 'Adicione pelo menos um signatário.',
            'recipients.max' => 'Um documento aceita no máximo 20 signatários na Fase 1.',
            'recipients.*.email.distinct' => 'Este e-mail já está em outro signatário deste documento.',
        ];
    }

    /**
     * @return array{signing_order: string, recipients: list<array<string, mixed>>}
     */
    public function payload(): array
    {
        /** @var array{signing_order: string, recipients: list<array<string, mixed>>} $validated */
        $validated = $this->validated();

        return $validated;
    }
}
