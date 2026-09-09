<?php

namespace App\Http\Middleware;

use App\Support\Correlation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Abre a unidade de trabalho da requisição com um identificador de correlação
 * (App\Support\Correlation) e o devolve no cabeçalho `X-Correlation-Id`.
 *
 * O identificador entra no `Context` do Laravel, então:
 *  - o processador de log o carimba em toda linha registrada durante a requisição;
 *  - todo job despachado aqui o leva no payload e continua usando o mesmo valor no worker.
 *
 * Um `X-Correlation-Id` recebido do balanceador é aproveitado, mas só depois de
 * higienizado (Correlation::sanitize): o cliente escolhe esse cabeçalho, e sem filtro ele
 * seria uma injeção de conteúdo arbitrário em cada linha de log.
 *
 * Roda como middleware GLOBAL para valer também nas rotas fora do grupo `web` (webhook) e
 * nas respostas de erro.
 */
class AssignCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) config('assinavelox.observability.correlation.header', 'X-Correlation-Id');

        $id = Correlation::start(Correlation::sanitize($request->headers->get($header)));

        $response = $next($request);

        if ((bool) config('assinavelox.observability.correlation.echo_header', true)) {
            $response->headers->set($header, $id);
        }

        return $response;
    }
}
