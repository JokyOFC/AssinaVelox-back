<?php

namespace App\Http\Requests\Sign;

use App\Models\PendingExternalSignature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST assinar/{token}/externa/assinatura` (Fase 3 §3.4).
 *
 * - modo `raw`: `signature` (Base64 da assinatura BRUTA sobre o digest entregue),
 *   `certificate` (Base64 DER ou PEM, o mesmo anunciado na preparação) e `chain[]`;
 * - modo `cms`: `cms` (Base64 ou PEM de um CMS/PKCS#7 SignedData destacado).
 *
 * O servidor confere tudo no pdftool; nada aqui é chave ou segredo.
 */
class ExternalSubmitRequest extends FormRequest
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
        $certificateChars = ExternalPrepareRequest::maxCertificateChars();
        $cmsChars = (int) ceil(max(8, (int) config('assinavelox.external_signing.max_cms_kb', 48)) * 1024 * 1.4) + 256;

        return [
            'pending_id' => ['required', 'string', 'size:26', 'alpha_num'],
            'mode' => ['required', 'string', Rule::in([PendingExternalSignature::MODE_RAW, PendingExternalSignature::MODE_CMS])],
            'signature' => ['required_if:mode,raw', 'nullable', 'string', 'max:4096'],
            'signature_algorithm' => ['nullable', 'string', 'max:64'],
            'certificate' => ['required_if:mode,raw', 'nullable', 'string', 'max:'.$certificateChars],
            'chain' => ['nullable', 'array', 'max:10'],
            'chain.*' => ['string', 'max:'.$certificateChars],
            'cms' => ['required_if:mode,cms', 'nullable', 'string', 'max:'.$cmsChars],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pending_id.required' => 'Informe a preparação que está sendo concluída.',
            'signature.required_if' => 'O componente local não devolveu a assinatura.',
            'certificate.required_if' => 'O componente local não devolveu o certificado que assinou.',
            'cms.required_if' => 'Envie o pacote de assinatura (CMS/PKCS#7).',
            'cms.max' => 'O pacote de assinatura é grande demais.',
        ];
    }

    /**
     * @return list<string>
     */
    public function chain(): array
    {
        return array_values(array_filter((array) $this->input('chain', []), 'is_string'));
    }

    public function optionalString(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
