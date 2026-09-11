<?php

namespace App\Http\Requests\Sign;

use App\Http\Middleware\ResolveSignerToken;
use App\Services\Signing\RecordAcceptance;
use App\Services\Signing\SignerContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Forma do aceite (ROUTES §2.18 `sign.complete`).
 *
 * Aqui só a **forma**: presença, tipo e tamanho. As decisões que dependem do domínio ficam
 * em {@see RecordAcceptance} e nos serviços de imagem, porque dependem de estado que um
 * FormRequest não deve consultar (a versão do documento, os campos daquele destinatário, o
 * snapshot da tela, o lock do envelope).
 *
 * Duas regras merecem nota:
 *
 * - `consent` é `accepted`: a caixa nunca vem marcada e um POST sem ela é recusado. A
 *   manifestação tem de ser ativa (docs/juridico/declaracao-de-aceite.md §2).
 * - `fields.*` aceita string ou booleano e **não** limita o conteúdo por campo: os limites
 *   por tipo (texto ≤ 500, nome ≤ 160, checkbox obrigatório) são aplicados pelo serviço, que
 *   sabe qual campo é de quem e qual é obrigatório. Campos `date` que venham no payload são
 *   descartados: a data é do servidor.
 */
class StoreAcceptanceRequest extends FormRequest
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
        // Base64 cresce 4/3 e a mensagem inteira precisa caber em memória: o teto aqui é
        // grosseiro de propósito; o teto fino é do SignatureImages, sobre os bytes decodificados.
        $maxBase64 = (int) ceil(((int) config('assinavelox.signing_session.signature_image.max_decoded_kb', 3072) * 1024) * 4 / 3) + 1024;

        // Aprovador (Fase 2 §2.4) registra aprovação SEM representação visual: a assinatura
        // deixa de ser obrigatória na forma (e é ignorada pelo serviço se vier).
        $visual = $this->requiresVisualSignature();

        return [
            'authorization' => ['required', 'string', 'min:20', 'max:128'],
            'consent' => ['required', 'accepted'],

            'signature' => [$visual ? 'required' : 'nullable', 'array'],
            'signature.method' => [$visual ? 'required' : 'nullable', 'string', Rule::in(['draw', 'type', 'upload'])],
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
            'authorization.required' => 'A tela expirou. Recarregue a página antes de assinar.',
            'consent.required' => 'Marque a declaração de aceite para assinar.',
            'consent.accepted' => 'Marque a declaração de aceite para assinar.',
            'signature.required' => 'Escolha como quer assinar: desenhar, digitar ou enviar uma imagem.',
            'signature.method.required' => 'Escolha como quer assinar: desenhar, digitar ou enviar uma imagem.',
            'signature.method.in' => 'Escolha como quer assinar: desenhar, digitar ou enviar uma imagem.',
            'signature.image_base64.max' => 'A imagem da assinatura é grande demais.',
            'signature.text.max' => 'O nome digitado deve ter no máximo 80 caracteres.',
        ];
    }

    /**
     * O participante deste link precisa de representação visual? Sem contexto resolvido
     * (não deveria acontecer: o middleware `signer` roda antes), vale a regra da Fase 1.
     */
    private function requiresVisualSignature(): bool
    {
        $context = $this->attributes->get(ResolveSignerToken::ATTRIBUTE);

        if (! $context instanceof SignerContext) {
            return true;
        }

        return $context->action()?->requiresVisualSignature() ?? true;
    }

    /**
     * Payload já no formato que o serviço espera.
     *
     * @return array{signature: array<string, mixed>, initials: array<string, mixed>|null, fields: array<string, mixed>, authorization: string}
     */
    public function payload(): array
    {
        $signature = $this->input('signature', []);
        $signature = is_array($signature) ? $signature : [];
        /** @var array<string, mixed>|null $initials */
        $initials = $this->input('initials');
        /** @var array<string, mixed> $fields */
        $fields = $this->input('fields', []);

        return [
            'signature' => $signature,
            'initials' => is_array($initials) ? $initials : null,
            'fields' => $fields,
            'authorization' => (string) $this->string('authorization'),
        ];
    }
}
