<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aplica limitadores nomeados a rotas identificadas pelo NOME, segundo o mapa
 * `assinavelox.rate_limit_routes` (docs/seguranca-operacional.md §2).
 *
 * Por que existe, em vez de `->middleware('throttle:...')` em cada rota: as rotas mais
 * atacadas de qualquer SaaS — cadastro, "esqueci minha senha" e redefinição de senha —
 * são registradas pelo pacote Fortify, que não expõe ponto de extensão por rota e não
 * traz limitador nenhum nelas. Pendurar o limite aqui mantém a regra em um lugar só,
 * versionada em config, e não exige tocar em vendor/.
 *
 * Uma rota pode ter os dois limites: o declarado em routes/web.php e o daqui. Os baldes
 * são independentes (prefixos diferentes), então o efeito é o do MAIS restritivo.
 */
class ThrottleSensitiveRoutes
{
    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $name = $request->route()?->getName();

        if ($name === null) {
            return $next($request);
        }

        /** @var array<string, string> $map */
        $map = (array) config('assinavelox.rate_limit_routes', []);
        $limiterName = $map[$name] ?? null;

        if (! is_string($limiterName) || $this->limiter->limiter($limiterName) === null) {
            return $next($request);
        }

        /*
         * ThrottleRequests só entende um limitador NOMEADO quando recebe exatamente três
         * argumentos (ele testa `func_num_args() === 3`). Passar o `$prefix` opcional aqui
         * faria o Laravel interpretar "download" como número de tentativas.
         */
        return app(ThrottleRequests::class)->handle($request, $next, $limiterName);
    }
}
