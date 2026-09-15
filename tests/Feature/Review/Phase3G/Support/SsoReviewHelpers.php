<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial da onda G — SSO, contas e isolamento (helpers)
|--------------------------------------------------------------------------
| Incluído com require_once pelos testes de tests/Feature/Review/Phase3G. Não contém testes.
| Dois IdPs OIDC SIMULADOS (Http::fake, nenhuma rede): o legítimo (idp.example.com) e um
| segundo (rotated.example.com), que faz o papel do IdP para onde a conexão é reapontada.
*/

use App\Models\SsoConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../../Phase3/Sso/Support/SsoHelpers.php';

const REVIEW_G3_ROTATED_ISSUER = 'https://rotated.example.com';
const REVIEW_G3_ROTATED_CLIENT = 'cliente-do-admin';

if (! function_exists('reviewG3FakeProviders')) {
    /**
     * Claims de cada IdP em `$test->legitClaims` / `$test->rotatedClaims`; nonce em `$test->nonce`.
     */
    function reviewG3FakeProviders(object $test): void
    {
        $test->legitKey = ssoRsaKey('k1');
        $test->rotatedKey = ssoRsaKey('adm');
        $test->nonce = '';
        $test->legitClaims = [];
        $test->rotatedClaims = [];

        Http::preventStrayRequests();
        Http::fake([
            'idp.example.com/.well-known/openid-configuration' => fn () => Http::response(ssoDiscovery()),
            'idp.example.com/jwks' => fn () => Http::response(['keys' => [$test->legitKey['jwk']]]),
            'idp.example.com/token' => fn () => Http::response([
                'id_token' => ssoIdToken(ssoClaims($test->nonce, $test->legitClaims), $test->legitKey['private']),
            ]),
            'rotated.example.com/.well-known/openid-configuration' => fn () => Http::response(ssoDiscovery([
                'issuer' => REVIEW_G3_ROTATED_ISSUER,
                'authorization_endpoint' => REVIEW_G3_ROTATED_ISSUER.'/authorize',
                'token_endpoint' => REVIEW_G3_ROTATED_ISSUER.'/token',
                'jwks_uri' => REVIEW_G3_ROTATED_ISSUER.'/jwks',
            ])),
            'rotated.example.com/jwks' => fn () => Http::response(['keys' => [$test->rotatedKey['jwk']]]),
            'rotated.example.com/token' => fn () => Http::response([
                'id_token' => ssoIdToken(ssoClaims($test->nonce, [
                    'iss' => REVIEW_G3_ROTATED_ISSUER,
                    'aud' => REVIEW_G3_ROTATED_CLIENT,
                    ...$test->rotatedClaims,
                ]), $test->rotatedKey['private'], 'RS256', 'adm'),
            ]),
        ]);
    }
}

if (! function_exists('reviewG3OidcLogin')) {
    /**
     * Login pela tela de login (domínio do e-mail → conexão) até a volta do provedor.
     */
    function reviewG3OidcLogin(object $test, SsoConnection $connection, string $email): TestResponse
    {
        $params = ssoAuthorizeParams($test->post(route('sso.login.start'), ['email' => $email]));
        $test->nonce = $params['nonce'] ?? '';

        return $test->get(route('sso.oidc.callback', [
            'connection' => $connection->ulid,
            'state' => $params['state'] ?? 'sem-state',
            'code' => 'codigo-1',
        ]));
    }
}

if (! function_exists('reviewG3EnableTwoFactor')) {
    function reviewG3EnableTwoFactor(User $user): void
    {
        $user->forceFill(['two_factor_secret' => encrypt('SEGREDO-TOTP'), 'two_factor_confirmed_at' => now()])->save();
    }
}
