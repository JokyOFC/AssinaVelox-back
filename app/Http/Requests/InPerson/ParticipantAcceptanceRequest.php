<?php

namespace App\Http\Requests\InPerson;

use App\Services\Signing\RecordAcceptance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Forma do aceite no presencial e no lote. Mesmas regras de `Sign\StoreAcceptanceRequest`,
 * com uma diferença: a assinatura visual é `nullable` na forma, porque aqui não há o contexto
 * do link resolvido por middleware antes da validação. Quem decide se ela é exigida é o
 * {@see RecordAcceptance} (papel do participante): sem método de assinatura, o aprovador
 * passa e o signatário recebe `invalid_signature_method`.
 *
 * `consent` continua `accepted`: a manifestação é sempre ativa, item a item.
 */
class ParticipantAcceptanceRequest extends FormRequest
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
        $maxBase64 = (int) ceil(((int) config('assinavelox.signing_session.signature_image.max_decoded_kb', 3072) * 1024) * 4 / 3) + 1024;

        return [
            'authorization' => ['required', 'string', 'min:20', 'max:128'],
            'consent' => ['required', 'accepted'],

            'signature' => ['nullable', 'array'],
            'signature.method' => ['nullable', 'string', Rule::in(['draw', 'type', 'upload'])],
            'signature.image_base64' => ['nullable', 'string', 'max:'.$maxBase64],
            'signature.text' => ['nullable', 'string', 'max:80'],
            'signature.font' => ['nullable', 'string', Rule::in(RecordAcceptance::FONTS)],

            'initials' => ['nullable', 'array'],
            'initials.method' => ['nullable', 'string', Rule::in(['draw', 'type', 'upload'])],
            'initials.image_base64' => ['nullable', 'string', 'max:'.$maxBase64],
            'initials.text' => ['nullable', 'string', 'max:80'],

            'fields' => ['nullable', 'array'],
            'fields.*' => ['nullable'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'authorization.required' => 'A tela expirou. Recarregue a página antes de registrar o aceite.',
            'consent.required' => 'Marque a declaração de aceite para continuar.',
            'consent.accepted' => 'Marque a declaração de aceite para continuar.',
            'signature.method.in' => 'Escolha como quer assinar: desenhar, digitar ou enviar uma imagem.',
            'signature.image_base64.max' => 'A imagem da assinatura é grande demais.',
            'signature.text.max' => 'O nome digitado deve ter no máximo 80 caracteres.',
        ];
    }

    /**
     * @return array{signature: array<string, mixed>, initials: array<string, mixed>|null, fields: array<string, mixed>, authorization: string}
     */
    public function payload(): array
    {
        $signature = $this->input('signature', []);
        $initials = $this->input('initials');
        $fields = $this->input('fields', []);

        return [
            'signature' => is_array($signature) ? $signature : [],
            'initials' => is_array($initials) ? $initials : null,
            'fields' => is_array($fields) ? $fields : [],
            'authorization' => (string) $this->string('authorization'),
        ];
    }
}
