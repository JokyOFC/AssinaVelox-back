<?php

namespace App\Http\Requests\Sign;

use App\Enums\LocalSignerComponent;
use App\Models\PendingExternalSignature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST assinar/{token}/externa/preparar` (Fase 3 §3.4): o certificado que o componente local
 * devolveu (Base64 DER ou PEM) + cadeia, o componente e o modo. NENHUMA chave: o servidor
 * nunca recebe material privado.
 */
class ExternalPrepareRequest extends FormRequest
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
        $maxChars = self::maxCertificateChars();

        return [
            'document_id' => ['required', 'string', 'size:26', 'alpha_num'],
            'component' => ['required', 'string', Rule::in(LocalSignerComponent::values())],
            'mode' => ['required', 'string', Rule::in([PendingExternalSignature::MODE_RAW, PendingExternalSignature::MODE_CMS])],
            'certificate' => ['required', 'string', 'max:'.$maxChars],
            'chain' => ['nullable', 'array', 'max:10'],
            'chain.*' => ['string', 'max:'.$maxChars],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document_id.required' => 'Informe o documento a assinar.',
            'component.in' => 'Componente de assinatura desconhecido.',
            'mode.in' => 'Modo de assinatura desconhecido.',
            'certificate.required' => 'O componente local não devolveu o certificado.',
            'certificate.max' => 'O certificado enviado é grande demais.',
            'chain.max' => 'A cadeia enviada tem certificados demais.',
        ];
    }

    /**
     * @return list<string>
     */
    public function chain(): array
    {
        return array_values(array_filter((array) $this->input('chain', []), 'is_string'));
    }

    public static function maxCertificateChars(): int
    {
        return (int) ceil(max(4, (int) config('assinavelox.external_signing.max_certificate_kb', 32)) * 1024 * 1.4) + 256;
    }
}
