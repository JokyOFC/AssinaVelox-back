<?php

namespace App\Http\Controllers\Sign;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveSignerToken;
use App\Http\Requests\Sign\ExternalSimulatorSignRequest;
use App\Integrations\LocalSigner\FakeLocalSigner;
use App\Services\Signing\External\Exceptions\ExternalSignatureException;
use App\Services\Signing\External\ExternalSignatureService;
use App\Services\Signing\SignerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SIMULADOR de componente local (Fase 3 §3.4) — `sign.external.simulator.*`. Só existe
 * (senão 404) com a flag `a3_signing` ligada E o {@see FakeLocalSigner}
 * disponível: ambiente `local`/`testing`, habilitado e com o PKCS#12 de TESTE configurado.
 * Tudo o que ele assina é rotulado "simulado — nenhum token foi usado".
 */
class ExternalSimulatorController extends Controller
{
    public function __construct(private readonly ExternalSignatureService $service) {}

    public function certificate(Request $request, string $token): JsonResponse
    {
        $context = $this->context($request);

        if (! $this->service->authenticated($context, $request)) {
            return response()->json(['message' => 'Sua sessão expirou. Confirme o código enviado para você para continuar.', 'code' => 'not_authenticated'], 403);
        }

        try {
            return response()->json($this->service->simulatorCertificate());
        } catch (ExternalSignatureException $exception) {
            return $this->error($exception);
        }
    }

    public function sign(ExternalSimulatorSignRequest $request, string $token): JsonResponse
    {
        $context = $this->context($request);

        try {
            $this->service->simulateSignature($context, $request, (string) $request->input('pending_id'));
        } catch (ExternalSignatureException $exception) {
            return $this->error($exception);
        }

        return response()->json($this->service->state($context->refreshed(), $request));
    }

    private function context(Request $request): SignerContext
    {
        $context = ResolveSignerToken::context($request);

        abort_unless($this->service->offeredTo($context) && $this->service->simulatorAvailable(), 404);

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
