<?php

namespace App\Http\Controllers\Envelopes;

use App\Http\Controllers\Controller;
use App\Models\Envelope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Campos de assinatura do envelope — ROUTES §1.2 envelopes.fields.sync.
 * // TODO(Wave B): validação geométrica (x+w ≤ 1, y+h ≤ 1, página existe), rubrica automática, auditoria fields.updated.
 */
class EnvelopeFieldController extends Controller
{
    public function sync(Request $request, Envelope $envelope): RedirectResponse
    {
        Gate::authorize('update', $envelope);

        $request->validate([
            'initials_on_all_pages' => ['required', 'boolean'],
            'fields' => ['present', 'array', 'max:200'],
            'fields.*.type' => ['required', 'in:signature,initials,name,date,text,checkbox'],
            'fields.*.recipient_client_id' => ['required', 'string'],
            'fields.*.page' => ['required'],
            'fields.*.x' => ['required', 'numeric', 'min:0', 'max:1'],
            'fields.*.y' => ['required', 'numeric', 'min:0', 'max:1'],
            'fields.*.w' => ['required', 'numeric', 'gt:0', 'max:1'],
            'fields.*.h' => ['required', 'numeric', 'gt:0', 'max:1'],
            'fields.*.required' => ['required', 'boolean'],
            'fields.*.label' => ['nullable', 'string', 'max:120'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:60'],
        ], [], ['fields' => 'campos', 'initials_on_all_pages' => 'rubrica em todas as páginas']);

        // TODO(Wave B): persistir SigningField sobre a versão exibida do documento.
        return back();
    }
}
