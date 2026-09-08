<?php

namespace App\Http\Controllers\Sign;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Código por e-mail do signatário (ROUTES §1.3 sign.otp.*).
 * // TODO(Wave B): AuthChallenge (HMAC do código), envio via EmailProvider, SigningSession após verificar.
 */
class OtpController extends Controller
{
    public function send(Request $request, string $token): RedirectResponse
    {
        return back()->with('info', 'Envio de código estará disponível em breve.');
    }

    public function verify(Request $request, string $token): RedirectResponse
    {
        $request->validate(['code' => ['required', 'digits:6']], [], ['code' => 'código']);

        return back()->withErrors(['code' => 'Código inválido.']);
    }
}
