<?php

namespace App\Services\Api;

use App\Models\Envelope;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Visibilidade do envelope na API v1: o que o criador do token NÃO VÊ na interface
 * (EnvelopePolicy::view → App\Services\Organizations\EnvelopeVisibility) responde 404, como um
 * envelope de outra organização — a API não confirma a existência do que o token não enxerga.
 * Visível mas sem permissão para a AÇÃO → 403 (Policy), e status incompatível → 409.
 */
final class ApiEnvelopeAccess
{
    public static function ensureVisible(Envelope $envelope): void
    {
        if (! Gate::allows('view', $envelope)) {
            throw new NotFoundHttpException;
        }
    }
}
