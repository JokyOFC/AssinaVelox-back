<?php

namespace App\Services\CloudImport\OAuth;

use Illuminate\Http\Request;

/**
 * Lê os parâmetros do retorno OAuth (`state`, `code`, `error`) e APAGA a query da requisição.
 *
 * Motivo: o StartSession do Laravel grava a URL completa de todo GET em `_previous.url` da
 * sessão DEPOIS do controller — e a URL do retorno traz o código de autorização. Sem a query,
 * o código (de uso único, já trocado) não fica guardado na sessão nem por um instante
 * (docs/fase-3/conectores.md §7).
 */
final class CallbackQuery
{
    /**
     * @param  list<string>  $keys
     * @return array<string, string|null>
     */
    public static function take(Request $request, array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            $value = $request->query($key);
            $values[$key] = is_string($value) ? $value : null;
        }

        $request->query->replace([]);
        $request->server->set('QUERY_STRING', '');

        return $values;
    }
}
