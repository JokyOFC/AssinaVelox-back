<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Integrations\HubSpot\HubSpotSignature;
use App\Models\HubSpotConnection;
use App\Models\Organization;
use App\Services\HubSpot\HubSpotActionHandler;
use App\Services\HubSpot\HubSpotFeature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Ação de workflow "Enviar para assinatura" chamada pelo HubSpot (Fase 3 §3.9, G-CONN,
 * docs/fase-3/conectores.md §5.2). Sem sessão de usuário e sem CSRF: autenticada SÓ pela
 * assinatura v3 (HMAC-SHA256 com o client secret do app, janela de 5 minutos).
 *
 * Ordem: flag global (404) → app configurado (503) → tamanho → assinatura (401, resposta
 * única para qualquer motivo) → JSON → portal conectado a uma organização com a flag ligada
 * (404) → HubSpotActionHandler (idempotente por `callbackId`).
 */
final class HubSpotActionController extends Controller
{
    private const MAX_BODY_BYTES = 65536;

    public function __invoke(Request $request, HubSpotSignature $signature, HubSpotActionHandler $handler): JsonResponse
    {
        abort_unless(HubSpotFeature::globallyEnabled(), 404);

        $secret = (string) config('services.hubspot.client_secret', '');

        if ($secret === '') {
            return response()->json(['message' => 'Integração com o HubSpot ainda não disponível.'], 503);
        }

        $body = (string) $request->getContent();

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return response()->json(['message' => 'Requisição grande demais.'], 413);
        }

        $reason = $signature->verify(
            $request->getMethod(),
            $request->getSchemeAndHttpHost().$request->getRequestUri(),
            $body,
            $request->header('X-HubSpot-Signature-v3'),
            $request->header('X-HubSpot-Request-Timestamp'),
            $secret,
            (int) config('assinavelox.hubspot.signature_tolerance_seconds', 300),
        );

        if ($reason !== null) {
            // Só o código do motivo; nunca a assinatura, o corpo ou o segredo.
            Log::warning('hubspot.action_signature_rejected', ['reason' => $reason]);

            return response()->json(['message' => 'Assinatura inválida.'], 401);
        }

        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return response()->json(['message' => 'Corpo inválido.'], 400);
        }

        $portal = $payload['origin']['portalId'] ?? null;

        if (! (is_int($portal) || (is_string($portal) && ctype_digit($portal))) || (int) $portal <= 0) {
            return response()->json(['message' => 'Portal não informado.'], 400);
        }

        /** @var HubSpotConnection|null $connection */
        $connection = HubSpotConnection::withoutOrganizationScope()->where('portal_id', (int) $portal)->first();
        $organization = $connection === null ? null : Organization::query()->find($connection->organization_id);

        if ($connection === null || $organization === null || ! HubSpotFeature::enabled($organization)) {
            return response()->json(['message' => 'Conta do HubSpot não conectada.'], 404);
        }

        $result = $handler->handle($connection, $organization, $payload);

        return response()->json($result['body'], $result['status']);
    }
}
