<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Resources\VerificationResultResource;
use App\Models\Envelope;
use App\Services\Verification\PublicVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Verificação pública por código (arquitetura §6, ROUTES §1.4 e §4).
 *
 * Rota sem autenticação: `throttle:public` (60/min por IP) mais `throttle:20,1` na consulta e
 * `throttle:10,1` na conferência de resumo. Os cabeçalhos `X-Robots-Tag: noindex` e
 * `Referrer-Policy: no-referrer` vêm do middleware {@see SecurityHeaders},
 * que já cobre `/verificar` e `/verificar/*` — inclusive nas respostas de "não encontrado",
 * que são as que um buscador encontraria.
 *
 * Código inexistente e código de rascunho recebem a **mesma** resposta (`found = false`); a
 * justificativa está em {@see PublicVerification}.
 */
class VerificationController extends Controller
{
    public function __construct(private readonly PublicVerification $verification) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate(['code' => ['nullable', 'string', 'max:24']]);

        return Inertia::render('verify/index', [
            'code' => $validated['code'] ?? null,
            'error' => $request->session()->get('error'),
        ]);
    }

    public function show(Request $request, string $code): Response
    {
        $normalized = $this->verification->normalize($code);
        $envelope = $this->verification->lookup($normalized);

        return Inertia::render('verify/show', [
            'code' => $normalized,
            'found' => $envelope !== null,
            'result' => $envelope !== null
                ? VerificationResultResource::make($envelope)->resolve($request)
                : null,
            'file_check' => $request->session()->get('file_check'),
        ]);
    }

    /**
     * Conferência de arquivo — **por resumo, nunca por upload**.
     *
     * A comparação padrão acontece no navegador (WebCrypto), como manda a arquitetura §6 e
     * como a política de privacidade §11 afirma por escrito: "o hash é calculado no seu
     * navegador e o arquivo não é enviado". Esta rota existe para quem não pode contar com o
     * WebCrypto (contexto não seguro, navegador antigo, script) e calculou o resumo por conta
     * própria — `certutil -hashfile arquivo.pdf SHA256`, `sha256sum`, `Get-FileHash`.
     *
     * Aceitar o arquivo em si foi avaliado e **recusado**: tornaria falsa uma afirmação já
     * publicada na política de privacidade e criaria, numa rota anônima, um caminho de upload
     * de 25 MB sem dono e sem cota. O que se ganharia — a comodidade de arrastar o arquivo — é
     * exatamente o que o cálculo no navegador já entrega. Ver docs/verificacao-publica.md.
     *
     * A resposta compara apenas com resumos que a própria página já publica: não revela nada
     * que a consulta não tenha revelado.
     */
    public function checkFile(Request $request, string $code): RedirectResponse
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

        return back()->with('file_check', $fileCheck);
    }
}
