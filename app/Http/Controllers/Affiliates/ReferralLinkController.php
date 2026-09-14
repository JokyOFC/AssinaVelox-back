<?php

namespace App\Http\Controllers\Affiliates;

use App\Http\Controllers\Controller;
use App\Services\Affiliates\AffiliatesFeature;
use App\Services\Affiliates\Attribution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Link de indicação `/indicacao/{código}` (Fase 3 §3.10). Público, com limite por IP.
 *
 * Resposta IGUAL para código válido, desconhecido, suspenso ou recusado (redireciona para o
 * cadastro): a URL não serve para descobrir códigos. Só o código válido grava o cookie.
 * Flag desligada: 404.
 */
class ReferralLinkController extends Controller
{
    public function show(Request $request, string $code, Attribution $attribution): RedirectResponse
    {
        abort_unless(AffiliatesFeature::enabled(), 404);

        $response = redirect()->route('register');
        $cookie = $attribution->cookieForClick($request, $code);

        return $cookie !== null ? $response->withCookie($cookie) : $response;
    }
}
