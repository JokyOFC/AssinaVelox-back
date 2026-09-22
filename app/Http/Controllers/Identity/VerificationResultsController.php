<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Services\Identity\IdentityFeatures;
use App\Services\Identity\VerificationEvidence;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Verificações faciais com documento no detalhe do envelope, para o REMETENTE (Fase 4 §4.1,
 * docs/fase-4/verificacao-facial.md) — `GET documentos/{envelope}/verificacoes-faciais`,
 * rota `envelopes.identity_verifications.index` (JSON): exigências por participante e cada
 * tentativa com o que o PROVEDOR informou (status, data, identificador, tipo do documento).
 * Nunca imagem, dado lido do documento ou pontuação.
 *
 * Quem vê: quem tem `view` no envelope — o mesmo público da página de evidências. Flag
 * `identity_verification` desligada: 404 antes de qualquer outra decisão.
 */
class VerificationResultsController extends Controller
{
    public function __construct(private readonly VerificationEvidence $evidence) {}

    public function index(Envelope $envelope): JsonResponse
    {
        abort_unless(IdentityFeatures::identityVerification(CurrentOrganization::instance()->get()), 404);

        Gate::authorize('view', $envelope);

        return response()
            ->json($this->evidence->forEnvelope($envelope))
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
