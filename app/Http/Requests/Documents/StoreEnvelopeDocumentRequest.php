<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Upload do documento do envelope (ROUTES §1.2 `envelopes.document.store`).
 *
 * Aqui ficam apenas as regras baratas de forma (um arquivo, presente, dentro do limite de
 * tamanho). A validação de verdade — MIME real por `finfo`, assinatura de bytes, ZIP do
 * DOCX, dimensões da imagem — é feita por App\Services\Documents\UploadInspector sobre o
 * CONTEÚDO. Regras `mimes:` do Laravel não são suficientes: elas confiam em extensão e
 * em um mapa de MIME, e não protegem contra zip bomb nem contra imagem gigante.
 */
class StoreEnvelopeDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A policy do envelope é aplicada no controller (Gate::authorize('update', ...)).
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKilobytes = max(1, (int) config('assinavelox.upload.max_mb', 25)) * 1024;

        return [
            'file' => ['required', 'file', 'max:'.$maxKilobytes],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Selecione um arquivo para enviar.',
            'file.file' => 'O envio do arquivo não foi concluído. Tente novamente.',
            'file.max' => sprintf('O arquivo excede o limite de %d MB.', max(1, (int) config('assinavelox.upload.max_mb', 25))),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['file' => 'arquivo'];
    }

    public function document(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return $file;
    }
}
