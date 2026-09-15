<?php

namespace App\Http\Responses;

use App\Models\User;
use App\Support\LandingRoute;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

/**
 * Igual à resposta padrão do Fortify, exceto pelo destino: {@see LandingRoute}. O `intended`
 * continua valendo — quem tentou abrir uma página antes de entrar volta para ela.
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
        }

        /** @var User $user */
        $user = $request->user();

        return redirect()->intended(LandingRoute::for($user));
    }
}
