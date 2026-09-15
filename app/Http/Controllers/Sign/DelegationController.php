<?php

namespace App\Http\Controllers\Sign;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveSignerToken;
use App\Services\Envelopes\Delegation\DelegationException;
use App\Services\Envelopes\Delegation\DelegationPolicy;
use App\Services\Envelopes\Delegation\DelegationService;
use App\Services\Envelopes\Steps\FlowFeatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Delegação pelo participante na página pública (Fase 3 §3.3, F-FLOW — rotas
 * `sign.delegation.*` sob `assinar/{token}/delegar`). Respostas JSON (a página usa `fetch`).
 *
 * - `GET`: estado do cartão "Delegar". 404 (sem distinguir motivos) com a flag `delegation`
 *   desligada, sem a sessão do código neste navegador ou sem nada a mostrar — o cartão não
 *   aparece e a página é a de antes, como no A1 do participante.
 * - `POST`: nome, e-mail e motivo de quem vai participar no lugar. A autenticação (sessão do
 *   código) e todas as proibições são conferidas no serviço, sob lock.
 */
class DelegationController extends Controller
{
    public function __construct(private readonly DelegationService $service) {}

    public function show(Request $request, string $token): JsonResponse
    {
        $context = ResolveSignerToken::context($request);
        $state = $this->service->state($context, $request);

        abort_if($state === null, 404);

        return response()->json($state);
    }

    public function store(Request $request, string $token): JsonResponse
    {
        $context = ResolveSignerToken::context($request);

        abort_unless(FlowFeatures::delegation($context->organization) && DelegationPolicy::allows($context->envelope), 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:'.DelegationService::NAME_MAX],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'reason' => ['required', 'string', 'min:'.DelegationPolicy::reasonMin(), 'max:'.DelegationPolicy::reasonMax()],
        ], [
            'reason.min' => 'Explique o motivo com pelo menos :min caracteres.',
        ], [
            'name' => 'nome',
            'email' => 'e-mail',
            'reason' => 'motivo',
        ]);

        try {
            $result = $this->service->request(
                $context,
                $request,
                (string) $validated['name'],
                (string) $validated['email'],
                (string) $validated['reason'],
            );
        } catch (DelegationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode,
                'errors' => [$exception->field => [$exception->getMessage()]],
            ], $exception->status);
        }

        return response()->json($result, 201);
    }
}
