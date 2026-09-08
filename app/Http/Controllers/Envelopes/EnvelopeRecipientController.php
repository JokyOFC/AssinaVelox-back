<?php

namespace App\Http\Controllers\Envelopes;

use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Models\Recipient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Signatários do envelope — ROUTES §1.2 (sync, update, resend, resendAll).
 * // TODO(Wave B): sincronização completa, rotação de links, reenvio com throttle e auditoria.
 */
class EnvelopeRecipientController extends Controller
{
    public function sync(Request $request, Envelope $envelope): RedirectResponse
    {
        Gate::authorize('update', $envelope);

        $request->validate([
            'signing_order' => ['required', 'in:sequential,parallel'],
            'recipients' => ['required', 'array', 'min:1', 'max:20'],
            'recipients.*.id' => ['nullable', 'string', 'size:26'],
            'recipients.*.name' => ['required', 'string', 'min:2', 'max:120'],
            'recipients.*.email' => ['required', 'email:rfc', 'max:255', 'distinct:ignore_case'],
            'recipients.*.role' => ['nullable', 'string', 'max:40'],
            'recipients.*.order' => ['required', 'integer', 'min:1', 'max:20'],
        ], [], [
            'signing_order' => 'ordem de assinatura',
            'recipients' => 'signatários',
            'recipients.*.name' => 'nome',
            'recipients.*.email' => 'e-mail',
            'recipients.*.role' => 'papel',
            'recipients.*.order' => 'ordem',
        ]);

        // TODO(Wave B): persistir a lista (preservando ids), recomputar prontidão, auditoria recipients.updated.
        return back();
    }

    public function update(Request $request, Envelope $envelope, Recipient $recipient): RedirectResponse
    {
        Gate::authorize('update', $envelope);

        $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
        ], [], ['name' => 'nome', 'email' => 'e-mail']);

        // TODO(Wave B): só recipients pendentes; rotacionar token e reenviar.
        return back();
    }

    public function resend(Request $request, Envelope $envelope, Recipient $recipient): RedirectResponse
    {
        Gate::authorize('update', $envelope);

        // TODO(Wave B): novo recipient_access_link, revogar anterior, throttle 10 min, auditoria invitation.resent.
        return back()->with('info', 'Reenvio de convites estará disponível em breve (Wave B).');
    }

    public function resendAll(Request $request, Envelope $envelope): RedirectResponse
    {
        Gate::authorize('update', $envelope);

        // TODO(Wave B): reenviar para todos os pendentes respeitando o throttle.
        return back()->with('info', 'Reenvio de convites estará disponível em breve (Wave B).');
    }
}
