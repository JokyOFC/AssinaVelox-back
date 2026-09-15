<?php

namespace App\Services\BulkGeneration;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Executa um trecho do job "como" quem confirmou o lote, para que a trilha (EnvelopeAudit,
 * TemplateAudit) registre o usuário que PEDIU a geração — e não "sistema". Restaura o usuário
 * anterior ao terminar (fila `sync` dentro de uma requisição, ou worker de fila).
 */
final class ActingUser
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(User $user, callable $callback): mixed
    {
        $guard = Auth::guard();
        $previous = $guard->user();

        $guard->setUser($user);

        try {
            return $callback();
        } finally {
            if ($previous !== null) {
                $guard->setUser($previous);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
        }
    }
}
