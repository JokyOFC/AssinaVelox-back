<?php

namespace App\Http\Controllers\Sso;

use App\Http\Controllers\Controller;
use App\Integrations\Sso\Saml\SamlSettingsFactory;
use App\Models\User;
use App\Services\Sso\OidcLoginFlow;
use App\Services\Sso\SamlLoginFlow;
use App\Services\Sso\SsoFailure;
use App\Services\Sso\SsoLoginCompleter;
use App\Services\Sso\SsoProtocol;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OneLogin\Saml2\Settings;
use Throwable;

/**
 * SAML 2.0 do lado do SP (docs/fase-3/sso.md §7):
 *
 *  - `sso.saml.metadata` (GET): metadata do SP publicado (entityID = esta URL, ACS HTTP-POST);
 *  - `sso.saml.acs` (POST, sem CSRF — o POST vem do IdP): pedido pendente + navegador →
 *    validação completa → replay → regra de negócio.
 */
class SamlController extends Controller
{
    use ResolvesSsoConnection;

    public function __construct(
        private readonly SamlLoginFlow $flow,
        private readonly SsoLoginCompleter $completer,
        private readonly SamlSettingsFactory $settings,
    ) {}

    public function metadata(string $connection): Response
    {
        $sso = $this->resolveConnection($connection, SsoProtocol::Saml);

        try {
            $xml = (new Settings($this->settings->settings($sso), true))->getSPMetadata();
        } catch (Throwable) {
            abort(404);
        }

        return response($xml, 200, [
            'Content-Type' => 'application/samlmetadata+xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function acs(Request $request, string $connection): RedirectResponse
    {
        $sso = $this->resolveConnection($connection, SsoProtocol::Saml);
        $encoded = $request->input('SAMLResponse');
        $cookie = (string) config('assinavelox.sso.saml.binding_cookie', 'av_sso_saml');

        if (! is_string($encoded) || $encoded === '') {
            return $this->completer->loginFailed($sso, new SsoFailure('saml_invalid'))->withoutCookie($cookie, '/sso/saml/');
        }

        try {
            $pending = $this->flow->claim($sso, $request, $encoded);
        } catch (SsoFailure $failure) {
            return $this->completer->loginFailed($sso, $failure)->withoutCookie($cookie, '/sso/saml/');
        }

        if ($pending !== null && $pending->mode === OidcLoginFlow::MODE_TEST) {
            $actor = $pending->initiated_by_user_id !== null ? User::query()->find($pending->initiated_by_user_id) : null;

            try {
                $this->completer->recordTest($sso, $this->flow->consume($sso, $encoded, $pending), $actor);
            } catch (SsoFailure $failure) {
                $this->completer->recordTestFailure($sso, $failure, $actor);
            }

            return redirect()->route('settings.sso')->withoutCookie($cookie, '/sso/saml/');
        }

        if (! $sso->isActive()) {
            return $this->completer->loginFailed($sso, new SsoFailure('connection_unavailable'))->withoutCookie($cookie, '/sso/saml/');
        }

        try {
            $identity = $this->flow->consume($sso, $encoded, $pending);

            return $this->completer->login($sso, $identity, $request)->withoutCookie($cookie, '/sso/saml/');
        } catch (SsoFailure $failure) {
            return $this->completer->loginFailed($sso, $failure)->withoutCookie($cookie, '/sso/saml/');
        }
    }
}
