<?php

namespace App\Http\Middleware;

use App\Services\Signing\SignerContext;
use App\Services\Signing\SignerLinkResolver;
use App\Services\Signing\SignerPageProps;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve `/assinar/{token}` e injeta o {@see SignerContext} na requisição.
 *
 * Todo o grupo `sign.*` passa por aqui. Nenhum controller do fluxo público consulta
 * `recipient_access_links`: quem entrega o contexto é este middleware, e ele é a única
 * porta por onde o token bruto entra na aplicação.
 *
 * **404 genérico e idêntico** para token desconhecido, revogado, vencido, de envelope em
 * rascunho e fora da vez no sequencial. Motivos diferentes com respostas diferentes
 * transformariam a página em um oráculo: bastaria comparar as respostas para descobrir se
 * um convite existe. A página `sign/show` é renderizada com `screen: 'invalid'` **e status
 * 404**; as demais rotas do grupo (stream do PDF, POSTs) abortam com 404 seco.
 */
class ResolveSignerToken
{
    /** Chave em `$request->attributes` onde o contexto resolvido é guardado. */
    public const ATTRIBUTE = 'signer.context';

    public function __construct(private readonly SignerLinkResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->route('token');

        $context = $this->resolver->resolve($token);

        if ($context === null) {
            return $this->invalid($request);
        }

        $request->attributes->set(self::ATTRIBUTE, $context);

        return $next($request);
    }

    /**
     * Contexto resolvido da requisição corrente.
     */
    public static function context(Request $request): SignerContext
    {
        $context = $request->attributes->get(self::ATTRIBUTE);

        abort_unless($context instanceof SignerContext, 404, 'Link inválido.');

        return $context;
    }

    protected function invalid(Request $request): Response
    {
        if ($request->route()?->getName() !== 'sign.show') {
            abort(404, 'Este link não existe ou foi substituído. Verifique o e-mail mais recente.');
        }

        return Inertia::render('sign/show', SignerPageProps::invalid())
            ->toResponse($request)
            ->setStatusCode(404);
    }
}
