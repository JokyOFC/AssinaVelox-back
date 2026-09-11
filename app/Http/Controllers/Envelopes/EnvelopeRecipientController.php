<?php

namespace App\Http\Controllers\Envelopes;

use App\Enums\AuthMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Envelopes\SyncRecipientsRequest;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Envelopes\RecipientSync;
use App\Services\Envelopes\Sending\Exceptions\SendingException;
use App\Services\Envelopes\Sending\ResendInvitations;
use App\Services\Signing\Channels\RecipientChannels;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        private readonly RecipientChannels $channels,
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
     * PATCH: edita um signatário ainda pendente depois do envio — nome e e-mail e, na Fase 2
     * (onda B), o celular, o método do código e um PIN novo (docs/fase-2/canais-e-pin.md).
     * Trocar o e-mail revoga os links ativos; o reenvio depende do agente de envio. Trocar o
     * celular ou o método encerra as sessões e os códigos vivos; o PIN novo desbloqueia.
     * Sem `phone`/`auth_method`/`pin` no corpo, o comportamento é o da Fase 1.
     */
    public function update(Request $request, Envelope $envelope, Recipient $recipient): RedirectResponse
    {
        Gate::authorize('update', $envelope);

        abort_unless($recipient->envelope_id === $envelope->getKey(), 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'auth_method' => ['nullable', 'string', Rule::in(AuthMethod::values())],
            'pin' => ['nullable', 'string', 'regex:/^\d*$/', 'max:8'],
        ], [], ['name' => 'nome', 'email' => 'e-mail', 'phone' => 'celular', 'auth_method' => 'método do código', 'pin' => 'PIN']);

        if ($envelope->status->isTerminal()) {
            return back()->with('error', 'Ação indisponível no status atual.');
        }

        $recipient->setRelation('envelope', $envelope);

        $channelInput = array_filter(
            array_intersect_key($validated, array_flip(['phone', 'auth_method', 'pin'])),
            fn ($value): bool => is_string($value) && trim($value) !== '',
        );

        if ($channelInput !== [] && ! $recipient->status->isPendingSignature()) {
            throw ValidationException::withMessages([
                'name' => 'Este signatário já concluiu a etapa e não pode mais ser editado.',
            ]);
        }

        // Valida canal/PIN ANTES de gravar qualquer coisa: um erro aqui não deixa meia edição.
        $channels = $channelInput === [] ? null : $this->channels->resolveSent($envelope, $recipient, $channelInput);

        $result = $this->recipients->updatePending($recipient, $validated['name'], $validated['email']);

        $applied = $channels === null
            ? ['channel_changed' => false, 'pin_set' => false]
            : $this->channels->applySent($envelope, $recipient, $channels);

        $extra = trim(implode(' ', array_filter([
            $applied['channel_changed'] ? 'Os códigos e as sessões abertas antes da troca foram encerrados; o participante pede um código novo ao abrir o link.' : null,
            $applied['pin_set'] ? 'PIN novo definido — combine-o com o participante por fora do sistema.' : null,
        ])));

        if (! $result['email_changed']) {
            return back()->with('success', trim('Signatário atualizado. '.$extra));
        }

        return $result['rotated']
            ? back()->with('success', trim('Signatário atualizado e novo convite enviado. '.$extra))
            : back()->with('warning', trim('Signatário atualizado. O link anterior foi revogado — reenvie o convite para o novo e-mail. '.$extra));
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
