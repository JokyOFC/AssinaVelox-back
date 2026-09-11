<?php

namespace App\Http\Requests\Envelopes;

use App\Enums\AuthMethod;
use App\Enums\DeliveryChannel;
use App\Enums\RecipientRole;
use App\Models\Envelope;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Fase 2 §2.4: papel de domínio (o `role` acima é o rótulo livre). A flag
            // `participant_roles` é conferida em RecipientSync.
            'recipients.*.participant_role' => ['nullable', 'string', Rule::in(RecipientRole::values())],
            'recipients.*.order' => ['nullable', 'integer', 'min:1', 'max:20'],
            // Fase 2 §2.9 (C-CAN): telefone, canal do convite, método de autenticação e PIN.
            // Disponibilidade do canal, formato E.164 e força do PIN são conferidos em
            // App\Services\Signing\Channels\RecipientChannels (com a flag e o provedor).
            'recipients.*.phone' => ['nullable', 'string', 'max:32'],
            'recipients.*.channel' => ['nullable', 'string', Rule::in(DeliveryChannel::values())],
            'recipients.*.auth_method' => ['nullable', 'string', Rule::in(AuthMethod::values())],
            'recipients.*.pin' => ['nullable', 'string', 'regex:/^\d*$/', 'max:8'],
            'recipients.*.remove_pin' => ['nullable', 'boolean'],
        ];
    }

    /**
     * O PIN sai da entrada logo depois da validação: nunca vai para `_old_input` num
     * redirect de erro (nem da validação, nem de RecipientSync).
     */
    protected function passedValidation(): void
    {
        $this->forgetPins();
    }

    protected function failedValidation(Validator $validator): void
    {
        $this->forgetPins();

        parent::failedValidation($validator);
    }

    private function forgetPins(): void
    {
        $targets = [$this];
        $original = app('request');

        if ($original !== $this) {
            $targets[] = $original;
        }

        foreach ($targets as $request) {
            foreach ([$request->getInputSource(), $request->request] as $bag) {
                $rows = $bag->all()['recipients'] ?? null;

                if (! is_array($rows)) {
                    continue;
                }

                foreach ($rows as $index => $row) {
                    if (is_array($row)) {
                        unset($rows[$index]['pin']);
                    }
                }

                $bag->set('recipients', $rows);
            }
        }
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
            'recipients.*.participant_role' => 'tipo de participante',
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
