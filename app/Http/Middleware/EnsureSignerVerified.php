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
 * ## O que responde quando não há sessão
 *
 * Todo GET protegido aqui entrega BYTES (`sign.document` transmite o PDF; `sign.page` é a
 * miniatura descontinuada). Para eles a resposta é **404**, não 403: a existência do arquivo
 * não é informação que uma requisição não autenticada deva obter.
 *
 * A exceção é a NAVEGAÇÃO de primeiro nível — a pessoa que volta depois dos 30 minutos com
 * a aba aberta no endereço do PDF, ou que clica no link de novo. Aí ela é levada de volta
 * para `sign.show` com "Sua sessão expirou. Confirme o código enviado por e-mail para
 * continuar." — o único caminho que ela tem, já que não há conta nem painel do outro lado.
 * A navegação é reconhecida pelos cabeçalhos `Sec-Fetch-Mode: navigate` + `Sec-Fetch-Dest:
 * document`, que o navegador envia e um cliente qualquer não: sem eles, continua valendo o
 * 404. As requisições de estado (POST) sempre voltam para `sign.show`, como antes.
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
        if ($request->isMethod('GET') && ! self::isTopLevelNavigation($request)) {
            abort(404, 'Documento indisponível.');
        }

        return redirect()
            ->route('sign.show', ['token' => $token])
            ->with('error', 'Sua sessão expirou. Confirme o código enviado por e-mail para continuar.');
    }

    /**
     * A requisição é uma navegação de primeiro nível do navegador?
     *
     * Só os cabeçalhos `Sec-Fetch-*` respondem isso com honestidade: `Accept` é forjável e
     * está presente em qualquer cliente. Um `fetch()` do visualizador manda
     * `Sec-Fetch-Dest: empty`; um `<iframe>`/`<embed>`, `iframe`/`embed`; a barra de
     * endereços, `document` + `navigate`. Navegador antigo que não mande nada continua
     * recebendo 404 — o comportamento seguro é o padrão.
     */
    protected static function isTopLevelNavigation(Request $request): bool
    {
        return strtolower((string) $request->headers->get('Sec-Fetch-Mode')) === 'navigate'
            && strtolower((string) $request->headers->get('Sec-Fetch-Dest')) === 'document';
    }
}
