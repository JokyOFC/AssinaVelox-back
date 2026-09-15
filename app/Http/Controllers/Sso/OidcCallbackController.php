<?php

namespace App\Http\Controllers\Sso;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Sso\OidcLoginFlow;
use App\Services\Sso\SsoFailure;
use App\Services\Sso\SsoLoginCompleter;
use App\Services\Sso\SsoProtocol;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Volta do provedor OIDC (`redirect_uri` por conexão — mitiga o "mix-up" entre provedores).
 * `state` de uso único desta sessão → troca do código com PKCE → id_token validado → regra de
 * negócio (SsoLoginCompleter). Em modo "Testar conexão", só grava o resultado.
 */
class OidcCallbackController extends Controller
{
    use ResolvesSsoConnection;

    public function __construct(
        private readonly OidcLoginFlow $flow,
        private readonly SsoLoginCompleter $completer,
    ) {}

    public function __invoke(Request $request, string $connection): RedirectResponse
    {
        $sso = $this->resolveConnection($connection, SsoProtocol::Oidc);

        try {
            $flow = $this->flow->consumeState($request, $sso);
        } catch (SsoFailure $failure) {
            return $this->completer->loginFailed($sso, $failure);
        }

        if ($flow['mode'] === OidcLoginFlow::MODE_TEST) {
            $actor = $request->user();

            if (! $actor instanceof User || (int) $actor->getKey() !== (int) $flow['user_id']) {
                return $this->completer->loginFailed($sso, new SsoFailure('state_invalid'));
            }

            try {
                $this->completer->recordTest($sso, $this->flow->finish($sso, $flow, $request), $actor);
            } catch (SsoFailure $failure) {
                $this->completer->recordTestFailure($sso, $failure, $actor);
            }

            return redirect()->route('settings.sso');
        }

        if (! $sso->isActive()) {
            return $this->completer->loginFailed($sso, new SsoFailure('connection_unavailable'));
        }

        try {
            return $this->completer->login($sso, $this->flow->finish($sso, $flow, $request), $request);
        } catch (SsoFailure $failure) {
            return $this->completer->loginFailed($sso, $failure);
        }
    }
}
