<?php

namespace App\Services\Embed;

use App\Models\EmbeddedSigningSession;
use App\Models\Recipient;
use App\Services\Signing\Channels\SenderPins;
use App\Services\Signing\SignerTokens;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Str;

/**
 * Sessão isolada do widget embutido (docs/fase-3/widget-embutido.md §3.3).
 *
 * No fluxo por e-mail, o token da `signing_sessions` e o portão do PIN moram na sessão Laravel
 * do navegador (cookie primário). No iframe de outro site esse cookie é de terceiro — o Safari o
 * bloqueia sempre e o Chrome, se a pessoa quiser — e SameSite=None na sessão do app inteiro
 * enfraqueceria o CSRF do painel. Então as rotas `/embed/*` rodam SEM a sessão do app, e cada
 * requisição autenticada pelo token de execução recebe uma sessão Laravel NOVA, em memória,
 * reidratada com APENAS as duas chaves deste participante, guardadas cifradas na linha da
 * sessão embutida. Assim os serviços do fluxo público (SignerSessions, SenderPins,
 * RecordAcceptance) funcionam sem nenhuma alteração, e nada vaza entre o painel e o widget:
 * o cookie da sessão do app nunca é lido nem escrito aqui.
 */
final class EmbedSessionStore
{
    public function attach(Request $request, EmbeddedSigningSession $embedded, Recipient $recipient): Store
    {
        $store = new Store('assinavelox_embed', new ArraySessionHandler(1), Str::random(40));
        $store->start();

        $state = $embedded->session_state ?? [];

        if (is_string($state['signer_session'] ?? null) && $state['signer_session'] !== '') {
            $store->put(SignerTokens::sessionKey($recipient->ulid), $state['signer_session']);
        }

        if (is_string($state['pin_gate'] ?? null) && $state['pin_gate'] !== '') {
            $store->put(SenderPins::GATE_SESSION_PREFIX.$recipient->ulid, $state['pin_gate']);
        }

        $request->setLaravelSession($store);

        return $store;
    }

    public function persist(Store $store, EmbeddedSigningSession $embedded, Recipient $recipient): void
    {
        $state = array_filter([
            'signer_session' => $store->get(SignerTokens::sessionKey($recipient->ulid)),
            'pin_gate' => $store->get(SenderPins::GATE_SESSION_PREFIX.$recipient->ulid),
        ], static fn ($value): bool => is_string($value) && $value !== '');

        $current = $embedded->session_state ?? [];

        if ($state == $current) {
            return;
        }

        $embedded->forceFill(['session_state' => $state === [] ? null : $state])->save();
    }
}
