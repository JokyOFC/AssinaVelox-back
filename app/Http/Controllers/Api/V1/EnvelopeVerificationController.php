<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Services\Api\ApiEnvelopeAccess;
use App\Services\Api\Exceptions\ApiProblemException;
use App\Services\Verification\PublicVerification;
use Illuminate\Http\JsonResponse;

/**
 * Registro de verificação de um documento DA PRÓPRIA organização — API v1.
 *
 * Devolve exatamente o que a página pública de verificação mostra
 * (App\Services\Verification\PublicVerification::result, a lista fechada do §4.2), pela mesma
 * regra de publicação: rascunho, não enviado ou registro revogado → 404. Nada que a
 * verificação pública esconde sai por aqui.
 */
class EnvelopeVerificationController extends Controller
{
    /**
     * Registro de verificação
     *
     * Código, situação com rótulo honesto, resumos SHA-256 publicáveis, situação da
     * assinatura criptográfica e participantes com nome mascarado. Ability: `envelopes:read`.
     */
    public function show(Envelope $envelope, PublicVerification $verification): JsonResponse
    {
        ApiEnvelopeAccess::ensureVisible($envelope);

        $code = $envelope->verification_code;
        $published = is_string($code) && $code !== '' ? $verification->lookup($code) : null;

        if ($published === null || $published->getKey() !== $envelope->getKey()) {
            throw new ApiProblemException(
                404,
                'verification-unavailable',
                'Registro de verificação indisponível',
                'Este documento ainda não tem registro de verificação publicável (rascunho, não enviado ou registro revogado).',
            );
        }

        return response()->json(['data' => $verification->result($published)], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
