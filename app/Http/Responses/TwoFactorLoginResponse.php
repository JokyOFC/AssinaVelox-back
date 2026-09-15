<?php

namespace App\Http\Responses;

use App\Models\User;
use App\Support\LandingRoute;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;

/**
 * Depois do desafio de 2FA, o mesmo destino do login comum ({@see LoginResponse}).
 */
class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        /** @var User $user */
        $user = $request->user();

        return redirect()->intended(LandingRoute::for($user));
    }
}
