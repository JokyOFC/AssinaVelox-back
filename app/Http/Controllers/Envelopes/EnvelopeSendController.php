<?php

namespace App\Http\Controllers\Envelopes;

use App\Http\Controllers\Controller;
use App\Models\Envelope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Enviar para assinatura — ROUTES §1.2 envelopes.send.
 * // TODO(Wave B): transação com FOR UPDATE, congelar sent_document_version_id, verification_code,
 * expires_at (23:59:59 no fuso da org), reserva de consumo do plano, despacho dos convites.
 */
class EnvelopeSendController extends Controller
{
    public function store(Request $request, Envelope $envelope): RedirectResponse
    {
        Gate::authorize('send', $envelope);

        if (! $envelope->status->isDraftLike()) {
            return back()->with('error', 'Ação indisponível no status atual.');
        }

        return back()->with('info', 'O envio para assinatura estará disponível em breve (Wave B).');
    }
}
