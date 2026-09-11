<?php

namespace App\Http\Controllers\Sign;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveSignerToken;
use App\Http\Requests\Sign\CertificatePreviewRequest;
use App\Http\Requests\Sign\CertificateUploadRequest;
use App\Services\Signing\Certificates\Exceptions\ParticipantCertificateException;
use App\Services\Signing\Certificates\ParticipantCertificateService;
use App\Services\Signing\SignerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Assinatura com o certificado A1 do PRÓPRIO participante (Fase 2 §2.12) — rotas
 * `sign.certificate.*` sob `assinar/{token}/certificado`. Respostas JSON (a página pública
 * usa `fetch`/XHR).
 *
 * - 404 (sem distinguir motivos) com a flag `participant_a1` desligada, papel sem assinatura
 *   (aprovador, visualizador) ou envelope que não está mais recebendo assinatura.
 * - Toda ação exige autenticação DESTE participante neste navegador: a sessão do código
 *   (antes do aceite) ou a janela de download aberta pelo aceite/pelo código (depois dele).
 * - O controller é fino: decisão, conferência do certificado, cifra temporária e fila moram
 *   em {@see ParticipantCertificateService}.
 */
class CertificateController extends Controller
{
    public function __construct(private readonly ParticipantCertificateService $service) {}

    public function show(Request $request, string $token): JsonResponse
    {
        $context = $this->context($request);

        return response()->json($this->service->state($context, $request));
    }

    public function intent(Request $request, string $token): JsonResponse
    {
        $context = $this->context($request);

        try {
            $this->service->requestIntent($context, $request);
        } catch (ParticipantCertificateException $exception) {
            return $this->error($exception);
        }

        return response()->json($this->service->state($context->refreshed(), $request), 201);
    }

    public function withdraw(Request $request, string $token): JsonResponse
    {
        $context = $this->context($request);

        try {
            $this->service->withdraw($context, $request);
        } catch (ParticipantCertificateException $exception) {
            return $this->error($exception);
        }

        return response()->json($this->service->state($context->refreshed(), $request));
    }

    public function inspect(CertificatePreviewRequest $request, string $token): JsonResponse
    {
        $context = $this->context($request);

        try {
            $preview = $this->service->preview($context, $request->certificateFile(), $request->certificatePassword(), $request);
        } catch (ParticipantCertificateException $exception) {
            return $this->error($exception);
        }

        return response()->json($preview);
    }

    public function store(CertificateUploadRequest $request, string $token): JsonResponse
    {
        $context = $this->context($request);

        try {
            $this->service->submit(
                $context,
                $request->certificateFile(),
                $request->certificatePassword(),
                $request->expectedFingerprint(),
                $request,
            );
        } catch (ParticipantCertificateException $exception) {
            return $this->error($exception);
        }

        return response()->json($this->service->state($context->refreshed(), $request), 202);
    }

    private function context(Request $request): SignerContext
    {
        $context = ResolveSignerToken::context($request);

        abort_unless($this->service->offeredTo($context), 404);

        return $context;
    }

    private function error(ParticipantCertificateException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->errorCode,
            'errors' => [$exception->field => [$exception->getMessage()]],
        ], $exception->status);
    }
}
