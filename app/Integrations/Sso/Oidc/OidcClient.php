<?php

namespace App\Integrations\Sso\Oidc;

use App\Integrations\Sso\SsoHttpClient;
use App\Services\Sso\SsoFailure;

/**
 * Authorization Code com PKCE (RFC 7636, S256) — adaptador próprio sobre o HTTP Client do
 * Laravel (o Socialite foi rejeitado: exige Guzzle 6/7 — viabilidade §7 item 1).
 *
 * O client secret só existe dentro de `exchangeCode()`: nunca em log, exceção ou resposta.
 */
final class OidcClient
{
    public function __construct(private readonly SsoHttpClient $http) {}

    public function authorizationUrl(
        OidcProviderMetadata $metadata,
        string $clientId,
        string $redirectUri,
        string $state,
        string $nonce,
        string $codeChallenge,
        ?string $loginHint = null,
    ): string {
        $query = [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        if ($loginHint !== null && $loginHint !== '') {
            $query['login_hint'] = $loginHint;
        }

        $endpoint = $metadata->authorizationEndpoint;
        $separator = str_contains($endpoint, '?') ? '&' : '?';

        return $endpoint.$separator.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Troca o código pelo id_token. Autenticação do cliente: `client_secret_basic` (padrão do
     * OIDC) ou `client_secret_post`, conforme o discovery.
     *
     * @return array{id_token: string, access_token: string|null}
     *
     * @throws SsoFailure
     */
    public function exchangeCode(
        OidcProviderMetadata $metadata,
        string $clientId,
        #[\SensitiveParameter] string $clientSecret,
        #[\SensitiveParameter] string $code,
        string $redirectUri,
        #[\SensitiveParameter] string $codeVerifier,
    ): array {
        $fields = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ];
        $basic = null;

        if (in_array('client_secret_basic', $metadata->tokenAuthMethods, true) || ! in_array('client_secret_post', $metadata->tokenAuthMethods, true)) {
            // RFC 6749 §2.3.1: id e segredo codificados como form-urlencoded antes do Basic.
            $basic = [urlencode($clientId), urlencode($clientSecret)];
        } else {
            $fields['client_id'] = $clientId;
            $fields['client_secret'] = $clientSecret;
        }

        $response = $this->http->postForm($metadata->tokenEndpoint, $fields, $basic, KnownOidcProviders::allowedHostsFor($metadata->issuer));

        $idToken = $response['id_token'] ?? null;

        if (! is_string($idToken) || $idToken === '') {
            throw new SsoFailure('token_response_without_id_token');
        }

        $tokenType = $response['token_type'] ?? null;

        if ($tokenType !== null && (! is_string($tokenType) || strtolower($tokenType) !== 'bearer')) {
            throw new SsoFailure('token_response_invalid');
        }

        $accessToken = $response['access_token'] ?? null;

        return [
            'id_token' => $idToken,
            'access_token' => is_string($accessToken) && $accessToken !== '' ? $accessToken : null,
        ];
    }
}
