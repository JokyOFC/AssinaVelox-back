<?php

namespace App\Http\Requests\Sign;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Envio do PDF assinado no portal gov.br (P3-GOV). A autorização é do serviço (sessão do
 * código ou janela de download deste participante); aqui só a forma: um arquivo, até o
 * limite configurado. O conteúdo (PDF de verdade, revisão reservada como prefixo, uma única
 * assinatura nova…) é conferido no serviço.
 */
class GovBrReturnUploadRequest extends FormRequest
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
        $maxKb = max(1, min(100, (int) config('assinavelox.govbr.max_upload_mb', 100))) * 1024;

        return [
            'file' => ['required', 'file', 'max:'.$maxKb],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Envie o arquivo PDF assinado no portal.',
            'file.file' => 'Envie o arquivo PDF assinado no portal.',
            'file.uploaded' => 'O arquivo não pôde ser recebido. Tente de novo.',
            'file.max' => 'O arquivo enviado é maior que o limite aceito.',
        ];
    }

    public function returnedFile(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return $file;
    }
}
