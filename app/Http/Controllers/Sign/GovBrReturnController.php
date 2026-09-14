<?php

namespace App\Http\Controllers\Sign;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveSignerToken;
use App\Http\Requests\Sign\GovBrReturnUploadRequest;
use App\Services\Signing\GovBr\Exceptions\GovBrReturnException;
use App\Services\Signing\GovBr\GovBrReturnService;
use App\Services\Signing\SignerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assinar no portal gov.br e DEVOLVER o PDF (Fase 3 §3.5, P3-GOV) — rotas `sign.govbr.*` sob
 * `assinar/{token}/gov-br`. Respostas JSON, exceto o download da revisão reservada.
 *
 * - 404 (sem distinguir motivos) com a flag `govbr_return` desligada, papel sem assinatura
 *   (aprovador, visualizador) ou convite recusado/expirado/cancelado.
 * - Toda ação exige autenticação DESTE participante neste navegador.
 * - O controller é fino: reserva, conferência, decisão e gravação moram em
 *   {@see GovBrReturnService}. Contrato em docs/fase-3/gov-br.md §6.
 */
class GovBrReturnController extends Controller
{
    public function __construct(private readonly GovBrReturnService $service) {}

    public function show(Request $request, string $token): JsonResponse
    {
        return response()->json($this->service->state($this->context($request), $request));
    }

    public function intent(Request $request, string $token): JsonResponse
    {
        $context = $this->context($request);

        try {
            $this->service->requestIntent($context, $request);
        } catch (GovBrReturnException $exception) {
            return $this->error($exception);
        }

        return response()->json($this->service->state($context->refreshed(), $request), 201);
    }

    public function withdraw(Request $request, string $token): JsonResponse
    {
        $context = $this->context($request);

        try {
            $this->service->withdraw($context, $request);
        } catch (GovBrReturnException $exception) {
            return $this->error($exception);
        }

        return response()->json($this->service->state($context->refreshed(), $request));
    }

    public function reserve(Request $request, string $token): JsonResponse
    {
        $context = $this->context($request);

        try {
            $this->service->reserve($context, $request);
        } catch (GovBrReturnException $exception) {
            return $this->error($exception);
        }

        return response()->json($this->service->state($context->refreshed(), $request));
    }

    public function download(Request $request, string $token, string $pedido): Response
    {
        $context = $this->context($request);

        try {
            return $this->service->download($context, $request, $pedido);
        } catch (GovBrReturnException $exception) {
            return $this->error($exception);
        }
    }

    public function upload(GovBrReturnUploadRequest $request, string $token, string $pedido): JsonResponse
    {
        $context = $this->context($request);

        try {
            $this->service->submit($context, $request, $pedido, $request->returnedFile());
        } catch (GovBrReturnException $exception) {
            return $this->error($exception);
        }

        return response()->json($this->service->state($context->refreshed(), $request), 201);
    }

    private function context(Request $request): SignerContext
    {
        $context = ResolveSignerToken::context($request);

        abort_unless($this->service->offeredTo($context), 404);

        return $context;
    }

    private function error(GovBrReturnException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->errorCode,
            'errors' => [$exception->field => [$exception->getMessage()]],
        ], $exception->status);
    }
}
