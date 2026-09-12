<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UploadDocumentRequest;
use App\Http\Resources\Api\V1\DocumentResource;
use App\Models\Envelope;
use App\Services\Api\Exceptions\ApiProblemException;
use App\Services\Documents\DocumentIntake;
use App\Services\Documents\Exceptions\UploadRejectedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Arquivo do documento — API v1. O MESMO pipeline da interface (App\Services\Documents\
 * DocumentIntake): inspeção do conteúdo, limite de tamanho, conversão, trilha.
 */
class EnvelopeDocumentController extends Controller
{
    /**
     * Enviar arquivo
     *
     * Upload multipart (`file`): PDF, DOCX ou imagem (PNG, JPEG, WebP), até o limite da
     * interface. O conteúdo é conferido pelos bytes, não pela extensão. Com a flag
     * `multi_document` desligada, um segundo arquivo SUBSTITUI o anterior (e apaga os campos).
     * O processamento pode continuar em segundo plano: acompanhe `processing_status` no
     * detalhe do documento. Exige `Idempotency-Key`. Ability: `envelopes:write`.
     */
    public function store(UploadDocumentRequest $request, Envelope $envelope, DocumentIntake $intake): JsonResponse
    {
        // Visibilidade (404) e Policy `update` (403) já conferidas em UploadDocumentRequest::authorize().
        Gate::authorize('update', $envelope);

        if (! $intake->acceptsUpload($envelope)) {
            throw ApiProblemException::conflict('invalid-status', 'Este documento não está mais em rascunho e não aceita novos arquivos.', [
                'envelope_status' => $envelope->status->value,
            ]);
        }

        try {
            $document = $intake->store($envelope, $request->document(), $request->user(), $request);
        } catch (UploadRejectedException $exception) {
            if ($exception->errorCode === 'envelope_not_editable') {
                throw ApiProblemException::conflict('invalid-status', $exception->getMessage());
            }

            throw new ApiProblemException(422, 'upload-rejected', 'Arquivo recusado', $exception->getMessage(), [
                'code' => $exception->errorCode,
                'errors' => ['file' => [$exception->getMessage()]],
            ]);
        }

        return (new DocumentResource($document->fresh() ?? $document))
            ->response()
            ->setStatusCode(201)
            ->header('Location', route('api.v1.envelopes.show', ['envelope' => $envelope->ulid]));
    }
}
