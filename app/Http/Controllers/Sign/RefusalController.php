<?php

namespace App\Http\Controllers\Sign;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Recusa do signatário (ROUTES §1.3 sign.refuse / arquitetura §4.6): motivo obrigatório.
 * // TODO(Wave B): recipient → refused, envelope → refused (política padrão), notificar remetente.
 */
class RefusalController extends Controller
{
    public function store(Request $request, string $token): RedirectResponse
    {
        $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], [], ['reason' => 'motivo']);

        return back()->with('error', 'A recusa estará disponível em breve.');
    }
}
