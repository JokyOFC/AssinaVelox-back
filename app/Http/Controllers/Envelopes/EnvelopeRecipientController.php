<?php

namespace App\Http\Controllers\Envelopes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Envelopes\SyncRecipientsRequest;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Envelopes\RecipientSync;
use App\Services\Envelopes\Sending\Exceptions\SendingException;
use App\Services\Envelopes\Sending\ResendInvitations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Signatários do envelope — ROUTES §1.2 (sync, update, resend, resendAll).
 *
 * `sync`/`update` são do preparo (B-FIELDS); `resend`/`resendAll` são do envio (B-SEND) e
 * delegam a `App\Services\Envelopes\Sending\ResendInvitations`.
 */
class EnvelopeRecipientController extends Controller
{
    public function __construct(
        private readonly RecipientSync $recipients,
        private readonly ResendInvitations $resends,
    ) {}

    /**
     * PUT: substitui a lista inteira, preservando os `id` enviados. Só em envelope
     * `draft/preparing/ready` — depois do envio a lista está congelada.
     */
    public function sync(SyncRecipientsRequest $request, Envelope $envelope): RedirectResponse
    {
        if (! $envelope->status->isDraftLike()) {
            return back()->with('error', 'Ação indisponível no status atual.');
        }

        $this->recipients->handle($envelope, $request->payload());

        return back();
    }

    /**
     * PATCH: edita nome/e-mail de um signatário ainda pendente depois do envio.
     * Trocar o e-mail revoga os links ativos; o reenvio depende do agente de envio.
     */
    public function update(Request $request, Envelope $envelope, Recipient $recipient): RedirectResponse
    {
        Gate::authorize('update', $envelope);

        abort_unless($recipient->envelope_id === $envelope->getKey(), 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ], [], ['name' => 'nome', 'email' => 'e-mail']);

        if ($envelope->status->isTerminal()) {
            return back()->with('error', 'Ação indisponível no status atual.');
        }

        $recipient->setRelation('envelope', $envelope);

        $result = $this->recipients->updatePending($recipient, $validated['name'], $validated['email']);

        if (! $result['email_changed']) {
            return back()->with('success', 'Signatário atualizado.');
        }

        return $result['rotated']
            ? back()->with('success', 'Signatário atualizado e novo convite enviado.')
            : back()->with('warning', 'Signatário atualizado. O link anterior foi revogado — reenvie o convite para o novo e-mail.');
    }

    /**
     * Reenvio manual do convite (RECONCILIACAO Q11): novo `recipient_access_link`, revogação
     * do anterior, throttle de 10 min por destinatário e evento `invitation.resent`.
     */
    public function resend(Request $request, Envelope $envelope, Recipient $recipient): RedirectResponse
    {
        Gate::authorize('update', $envelope);

        abort_unless($recipient->envelope_id === $envelope->getKey(), 404);

        $recipient->setRelation('envelope', $envelope);

        try {
            $this->resends->one($envelope, $recipient);
        } catch (SendingException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Convite reenviado para '.$recipient->name.'.');
    }

    /**
     * "Lembrar pendentes": reenvia para todos os signatários elegíveis do envelope. Quem
     * está no intervalo de 10 minutos ou já atingiu o máximo de reenvios é ignorado e
     * contado no flash.
     */
    public function resendAll(Request $request, Envelope $envelope): RedirectResponse
    {
        Gate::authorize('update', $envelope);

        try {
            $result = $this->resends->all($envelope);
        } catch (SendingException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        if ($result['sent'] === 0) {
            return back()->with('info', 'Nenhum convite foi reenviado agora. '.$result['skipped'].' signatário(s) ainda no intervalo de espera ou no limite de reenvios.');
        }

        $message = $result['sent'].' convite(s) reenviado(s).';

        if ($result['skipped'] > 0) {
            $message .= ' '.$result['skipped'].' ignorado(s) por limite ou intervalo de espera.';
        }

        return back()->with('success', $message);
    }
}
