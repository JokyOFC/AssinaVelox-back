<?php

namespace App\Http\Controllers\Sign;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveSignerToken;
use App\Http\Requests\Sign\ExternalPrepareRequest;
use App\Http\Requests\Sign\ExternalSubmitRequest;
use App\Services\Signing\External\Exceptions\ExternalSignatureException;
use App\Services\Signing\External\ExternalSignatureService;
use App\Services\Signing\SignerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Assinatura do participante por componente local / externo (Fase 3 §3.4) — rotas
 * `sign.external.*` sob `assinar/{token}/externa`. JSON (o front fala com o componente local
 * e com estas rotas por `fetch`). Contrato: docs/fase-3/assinatura-externa-a3.md §7.
 *
 * 404 sem distinguir motivo com a flag `a3_signing` desligada, papel sem assinatura ou
 * envelope que não recebe mais assinatura. Toda ação exige autenticação DESTE participante
 * neste navegador (sessão do código ou janela de download depois do aceite).
 */
class ExternalSignatureController extends Controller
{
    public function __construct(private readonly ExternalSignatureService $service) {}

    public function show(Request $request, string $token): JsonResponse
    {
        return response()->json($this->service->state($this->context($request), $request));
    }

    public function intent(Request $request, string $token): JsonResponse
    {
        $context = $this->context($request);

        try {
            $this->service->requestIntent($context, $request, is_string($request->input('component')) ? $request->input('component') : null);
        } catch (ExternalSignatureException $exception) {
            return $this->error($exception);
        }

        return response()->json($this->service->state($context->refreshed(), $request), 201);
    }

    public function withdraw(Request $request, string $token): JsonResponse
    {
        $context = $this->context($request);

        try {
            $this->service->withdraw($context, $request);
        } catch (ExternalSignatureException $exception) {
            return $this->error($exception);
        }

        return response()->json($this->service->state($context->refreshed(), $request));
    }

    public function prepare(ExternalPrepareRequest $request, string $token): JsonResponse
    {
        $context = $this->context($request);

        try {
            $prepared = $this->service->prepare(
                $context,
                $request,
                (string) $request->input('document_id'),
                (string) $request->input('component'),
                (string) $request->input('mode'),
                (string) $request->input('certificate'),
                $request->chain(),
            );
        } catch (ExternalSignatureException $exception) {
            return $this->error($exception);
        }

        return response()->json($prepared + ['state' => $this->service->state($context->refreshed(), $request)], 201);
    }

    public function submit(ExternalSubmitRequest $request, string $token): JsonResponse
    {
        $context = $this->context($request);

        try {
            $this->service->submit(
                $context,
                $request,
                (string) $request->input('pending_id'),
                (string) $request->input('mode'),
                $request->optionalString('signature'),
                $request->optionalString('certificate'),
                $request->chain(),
                $request->optionalString('cms'),
            );
        } catch (ExternalSignatureException $exception) {
            return $this->error($exception);
        }

        return response()->json($this->service->state($context->refreshed(), $request));
    }

    private function context(Request $request): SignerContext
    {
        $context = ResolveSignerToken::context($request);

        abort_unless($this->service->offeredTo($context), 404);

        return $context;
    }

    private function error(ExternalSignatureException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->errorCode,
            'errors' => [$exception->field => [$exception->getMessage()]],
        ] + $exception->extra, $exception->status);
    }
}
