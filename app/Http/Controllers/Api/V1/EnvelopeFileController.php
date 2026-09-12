<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Envelopes\EnvelopeDownloadController;
use App\Models\Envelope;
use App\Services\Api\ApiEnvelopeAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Download autorizado — API v1. Delega ao MESMO controller da interface
 * (App\Http\Controllers\Envelopes\EnvelopeDownloadController): Policy `download`, `signed` e
 * `evidence` só após a conclusão, `?document={ulid}` para escolher o arquivo, evento
 * `envelope.downloaded` na trilha, stream do disco privado (nunca URL pública ou assinada).
 */
class EnvelopeFileController extends Controller
{
    /**
     * Baixar arquivo
     *
     * `type`: `original` (como foi enviado, em qualquer situação), `signed` (arquivo final) ou
     * `evidence` (página de evidências) — os dois últimos só com o documento concluído.
     * `?document={ulid}` escolhe o arquivo em documentos com vários. Ability: `documents:read`.
     */
    public function show(Request $request, Envelope $envelope, string $type): Response
    {
        ApiEnvelopeAccess::ensureVisible($envelope);

        return app(EnvelopeDownloadController::class)->show($request, $envelope, $type);
    }
}
