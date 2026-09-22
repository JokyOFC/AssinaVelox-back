<?php

namespace App\Http\Controllers\Sign;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureSignerVerified;
use App\Http\Middleware\ResolveSignerToken;
use App\Models\SigningSession;
use App\Services\Identity\Exceptions\CaptureRejectedException;
use App\Services\Identity\IdentityVerifications;
use App\Services\Identity\VerificationStep;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Verificação facial com documento na página pública (Fase 4 §4.1,
 * docs/fase-4/verificacao-facial.md) — rotas `sign.identity_verification.*` sob
 * `assinar/{token}/verificacao-facial`, com sessão autenticada (`signer.verified`). Respostas
 * JSON `{identity_verification: <bloco de VerificationStep>}` (a página usa `fetch`).
 *
 * - `POST`: `document_type` (rg | cnh | passaporte) e `consent` (obrigatório). As três fotos já
 *   precisam estar nesta sessão (rotas da captura). Abre a tentativa e enfileira o envio; o
 *   bloco volta `queued` e a página passa a consultar o `GET`.
 * - `GET`: estado atual; com a tentativa pendente, consulta o provedor (no máximo a cada 10 s)
 *   e aplica o prazo de espera.
 *
 * 404 (sem distinguir motivos) com a flag `identity_verification` desligada, papel sem aceite
 * ou verificação não exigida desta pessoa — a mesma regra da captura: não se coleta o que
 * ninguém pediu.
 */
class IdentityVerificationController extends Controller
{
    public function __construct(
        private readonly IdentityVerifications $verifications,
        private readonly VerificationStep $step,
    ) {}

    public function show(Request $request, string $token): JsonResponse
    {
        $context = ResolveSignerToken::context($request);

        abort_if(! $context->isActive() || ! $this->verifications->requiredFor($context), 404);

        /** @var SigningSession $session */
        $session = $request->attributes->get(EnsureSignerVerified::ATTRIBUTE);

        $latest = $this->verifications->latestFor($context->recipient);

        if ($latest !== null) {
            $this->verifications->refresh($latest);
        }

        return response()
            ->json(['identity_verification' => $this->step->props($context, $session)])
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function store(Request $request, string $token): JsonResponse
    {
        $context = ResolveSignerToken::context($request);

        abort_if(! $context->isActive() || ! $this->verifications->requiredFor($context), 404);

        /** @var SigningSession $session */
        $session = $request->attributes->get(EnsureSignerVerified::ATTRIBUTE);

        try {
            $this->verifications->submit(
                $context,
                $session,
                $request->string('document_type')->toString(),
                $request,
            );
        } catch (CaptureRejectedException $exception) {
            // As recusas são conferidas na ordem do serviço (exigência, tipo, fotos, andamento,
            // tentativas, consentimento); o campo do erro só orienta a tela.
            $field = match ($exception->errorCode) {
                'invalid_document_type' => 'document_type',
                'consent_required' => 'consent',
                default => 'verification',
            };

            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => [$field => [$exception->getMessage()]],
                'code' => $exception->errorCode,
                'identity_verification' => $this->step->props($context, $session),
            ], $exception->status);
        }

        return response()->json(['identity_verification' => $this->step->props($context, $session)], 201);
    }
}
