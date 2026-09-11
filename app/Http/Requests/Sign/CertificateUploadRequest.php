<?php

namespace App\Http\Requests\Sign;

use App\Services\Signing\Certificates\ParticipantCertificateConsent;
use Illuminate\Validation\Rule;

/**
 * `POST assinar/{token}/certificado` (Fase 2 §2.12): PFX + senha + consentimento específico.
 *
 * `consent` precisa ser aceito e `consent_version` precisa ser a versão vigente do texto de
 * autorização ({@see ParticipantCertificateConsent::VERSION}): uma tela antiga não autoriza
 * com um texto que a pessoa não viu. `fingerprint` (opcional) é a impressão digital mostrada
 * na prévia: se vier, o certificado enviado agora precisa ser o mesmo.
 */
class CertificateUploadRequest extends CertificatePreviewRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'consent' => ['accepted'],
            'consent_version' => ['required', 'string', Rule::in([ParticipantCertificateConsent::VERSION])],
            'fingerprint' => ['nullable', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return parent::messages() + [
            'consent.accepted' => 'Para assinar com o seu certificado, marque a autorização de uso do certificado.',
            'consent_version.required' => 'A autorização exibida está desatualizada. Recarregue a página.',
            'consent_version.in' => 'A autorização exibida está desatualizada. Recarregue a página.',
            'fingerprint.regex' => 'Identificação do certificado inválida. Confira o certificado de novo.',
        ];
    }

    public function expectedFingerprint(): ?string
    {
        $value = $this->input('fingerprint');

        return is_string($value) && $value !== '' ? strtolower($value) : null;
    }
}
