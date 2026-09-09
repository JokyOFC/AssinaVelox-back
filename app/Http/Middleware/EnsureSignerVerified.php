<?php

namespace App\Http\Middleware;

use App\Services\Signing\SignerSessions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige sessão de assinatura autenticada (código por e-mail já confirmado) nas rotas que
 * mostram ou alteram o documento: `sign.document`, `sign.page`, `sign.complete`,
 * `sign.refuse`, `sign.download`.
 *
 * Roda sempre depois de {@see ResolveSignerToken}. O que ele garante, além de "tem sessão":
 *
 * - a sessão é **deste** destinatário (outra pessoa no mesmo navegador não serve);
 * - a sessão aponta para a **mesma versão** do documento que o envelope congelou no envio;
 * - a sessão não expirou (30 min) nem foi consumida por um aceite anterior.
 *
 * Sem sessão, o PDF responde **404** (não 403): a existência do arquivo não é informação
 * que uma requisição não autenticada deva obter. Requisições de navegação voltam para
 * `sign.show`, onde a pessoa recebe a etapa "Confirmar identidade".
 */
class EnsureSignerVerified
{
    /** Chave em `$request->attributes` com a SigningSession autenticada. */
    public const ATTRIBUTE = 'signer.session';

    public function __construct(private readonly SignerSessions $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $context = ResolveSignerToken::context($request);

        $session = $this->sessions->current($context, $request);

        if ($session === null) {
            return $this->unverified($request, $context->token);
        }

        $request->attributes->set(self::ATTRIBUTE, $session);

        return $next($request);
    }

    protected function unverified(Request $request, string $token): Response
    {
        if ($request->isMethod('GET')) {
            abort(404, 'Documento indisponível.');
        }

        return redirect()
            ->route('sign.show', ['token' => $token])
            ->with('error', 'Sua sessão expirou. Confirme o código enviado por e-mail para continuar.');
    }
}
