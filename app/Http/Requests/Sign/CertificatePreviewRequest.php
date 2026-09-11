<?php

namespace App\Http\Requests\Sign;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * `POST assinar/{token}/certificado/conferir` (Fase 2 §2.12): PFX + senha para a PRÉVIA do
 * certificado. Nada é guardado: o arquivo vai para um diretório temporário exclusivo, é
 * inspecionado e apagado na mesma requisição.
 *
 * O campo chama-se `password` de propósito: o Laravel nunca devolve esse campo à sessão
 * (`dontFlash`) quando a validação falha. O formato real do arquivo é decidido pelo pdftool
 * sobre os bytes; extensão e `Content-Type` declarados são ignorados.
 */
class CertificatePreviewRequest extends FormRequest
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
            'certificate' => ['required', 'file', 'max:'.self::maxKilobytes()],
            'password' => ['required', 'string', 'max:256'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'certificate.required' => 'Escolha o arquivo do seu certificado (.pfx ou .p12).',
            'certificate.file' => 'Escolha o arquivo do seu certificado (.pfx ou .p12).',
            'certificate.uploaded' => 'O arquivo do certificado não pôde ser recebido. Tente de novo.',
            'certificate.max' => sprintf('O arquivo do certificado passa de %d KB; um certificado A1 costuma ter poucos KB.', self::maxKilobytes()),
            'password.required' => 'Informe a senha do certificado.',
            'password.max' => 'A senha informada é longa demais.',
        ];
    }

    public function certificateFile(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('certificate');

        return $file;
    }

    public function certificatePassword(): string
    {
        return (string) $this->input('password', '');
    }

    public static function maxKilobytes(): int
    {
        return max(8, (int) config('assinavelox.participant_a1.max_upload_kb', 64));
    }
}
