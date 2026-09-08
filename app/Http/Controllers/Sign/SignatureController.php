<?php

namespace App\Http\Controllers\Sign;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Aceite explícito do signatário (ROUTES §1.3 sign.complete / arquitetura §4.5).
 * // TODO(Wave B): sessão + token de autorização, revalidação sob lock, normalização da imagem,
 * SignatureAcceptance com snapshot, avanço da ordem ou finalização.
 */
class SignatureController extends Controller
{
    public function store(Request $request, string $token): RedirectResponse
    {
        return back()->with('error', 'A assinatura estará disponível em breve.');
    }
}
