<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Resources\VerificationResultResource;
use App\Models\Envelope;
use App\Services\Verification\PublicVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Verificação pública por código para o site institucional — docs/site-institucional.md.
 *
 * É a página `/verificar` do app em JSON: a mesma consulta uniforme e o mesmo resultado
 * publicável ({@see PublicVerification}, {@see VerificationResultResource}). Nenhuma decisão
 * sobre O QUE pode sair é tomada aqui — o contrato fechado de chaves e a resposta idêntica
 * para código inexistente, rascunho e registro revogado continuam onde sempre estiveram.
 *
 * `X-Robots-Tag: noindex` e `Referrer-Policy: no-referrer` vêm de SecurityHeaders, que cobre
 * `api/site/verificar/*` como cobre `/verificar/*`. `Cache-Control: no-store`: o estado de um
 * documento muda, e um proxy não pode guardar a resposta.
 */
class VerificationController extends Controller
{
    public function __construct(private readonly PublicVerification $verification) {}

    public function show(Request $request, string $code): JsonResponse
    {
        $normalized = $this->verification->normalize($code);
        $envelope = $this->verification->lookup($normalized);

        return $this->respond([
            'code' => $normalized,
            'found' => $envelope !== null,
            'result' => $envelope !== null
                ? VerificationResultResource::make($envelope)->resolve($request)
                : null,
        ]);
    }

    /**
     * Conferência por resumo, nunca por upload — as razões estão em
     * {@see \App\Http\Controllers\Public\VerificationController::checkFile()} e em
     * docs/verificacao-publica.md §2.2. O site calcula o SHA-256 no navegador do visitante e
     * compara localmente; esta rota atende quem colou um resumo calculado por conta própria.
     */
    public function checkFile(Request $request, string $code): JsonResponse
    {
        $validated = $request->validate([
            'sha256' => ['required', 'string', 'regex:/^[a-fA-F0-9]{64}$/'],
        ], [
            'sha256.regex' => 'Informe o resumo SHA-256 do arquivo: 64 caracteres hexadecimais.',
        ], ['sha256' => 'resumo do arquivo']);

        $normalized = $this->verification->normalize($code);
        $envelope = $this->verification->lookup($normalized);

        $fileCheck = $envelope instanceof Envelope
            ? $this->verification->checkHash($envelope, $validated['sha256'])
            // Sem documento publicável a resposta não muda de forma: "não confere".
            : ['matches' => 'none', 'checked_sha256' => strtolower($validated['sha256'])];

        return $this->respond([
            'code' => $normalized,
            'file_check' => $fileCheck,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function respond(array $payload): JsonResponse
    {
        return response()->json($payload)->header('Cache-Control', 'no-store');
    }
}
