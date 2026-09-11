<?php

namespace App\Http\Controllers\Templates\Concerns;

use App\Http\Controllers\Templates\Middleware\EnsureTemplatesFeature;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Middleware de controller da flag `templates` (ver {@see EnsureTemplatesFeature}).
 * Referenciado pela CLASSE, e não por Closure: ferramentas que inspecionam a lista de
 * middleware da rota (ex.: o teste de fumaça de rotas GET) esperam strings.
 */
trait RequiresTemplatesFeature
{
    /**
     * @param  list<string>  $except
     */
    protected static function templatesFeatureMiddleware(array $except = []): Middleware
    {
        return new Middleware(EnsureTemplatesFeature::class, except: $except === [] ? null : $except);
    }
}
