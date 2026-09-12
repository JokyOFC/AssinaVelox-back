<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Documents\StoreEnvelopeDocumentRequest;
use App\Models\Envelope;
use App\Services\Api\ApiEnvelopeAccess;

/**
 * POST /api/v1/envelopes/{envelope}/documents — multipart com o campo `file`.
 *
 * As MESMAS regras da interface: aqui só o tamanho máximo (`assinavelox.upload.max_mb`); o
 * conteúdo (MIME real, assinatura de bytes, ZIP do DOCX, dimensões de imagem) é inspecionado
 * por App\Services\Documents\UploadInspector dentro de DocumentIntake. Upload por URL não
 * existe (evita SSRF — roadmap §2.15).
 *
 * A autorização roda ANTES da validação: envelope invisível → 404; visível sem `update` → 403.
 */
class UploadDocumentRequest extends StoreEnvelopeDocumentRequest
{
    public function authorize(): bool
    {
        $envelope = $this->route('envelope');

        if (! $envelope instanceof Envelope) {
            return false;
        }

        ApiEnvelopeAccess::ensureVisible($envelope);

        return $this->user()?->can('update', $envelope) === true;
    }
}
