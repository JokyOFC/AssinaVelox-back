<?php

namespace App\Integrations\Sso\Oidc;

use App\Integrations\Sso\SsoHttpClient;
use App\Services\Sso\SsoFailure;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Discovery e JWKS do provedor OIDC, pela proteção contra SSRF (SsoHttpClient), com cache
 * (`sso.discovery_cache_minutes`) e acompanhamento da rotação de chaves: `kid` desconhecido
 * força UMA recarga do JWKS por janela (`sso.jwks_refresh_cooldown_seconds`), para que um
 * token forjado com `kid` aleatório não vire martelada no provedor.
 *
 * Só chaves de assinatura assimétricas entram no conjunto: `kty=oct` (simétrica) e `use=enc`
 * são descartadas, e a chave precisa servir ao algoritmo FIXADO pela conexão. Assim um token
 * com `alg=HS256` nunca encontra uma "chave" — nem a pública usada como segredo HMAC.
 */
final class OidcDiscovery
{
    public function __construct(private readonly SsoHttpClient $http) {}

    /**
     * @throws SsoFailure
     */
    public function metadata(string $issuer, bool $fresh = false): OidcProviderMetadata
    {
        $issuer = trim($issuer);
        $key = 'sso:oidc:discovery:'.hash('sha256', $issuer);

        if (! $fresh) {
            $cached = Cache::get($key);

            if (is_array($cached)) {
                return $this->fromArray($issuer, $cached);
            }
        }

        $allowed = KnownOidcProviders::allowedHostsFor($issuer);
        $document = $this->http->getJson(rtrim($issuer, '/').'/.well-known/openid-configuration', $allowed);
        $metadata = $this->fromArray($issuer, $document);

        // As URLs que o servidor vai chamar passam pela proteção já agora (e de novo a cada uso).
        $this->http->assertAllowed($metadata->tokenEndpoint, $allowed);
        $this->http->assertAllowed($metadata->jwksUri, $allowed);
        $this->assertBrowserUrl($metadata->authorizationEndpoint, $allowed);

        Cache::put($key, $metadata->toArray(), now()->addMinutes(max(1, (int) config('assinavelox.sso.discovery_cache_minutes', 60))));

        return $metadata;
    }

    /**
     * Chave para validar o id_token: pelo `kid` do cabeçalho (recarga única se não achar).
     *
     * @throws SsoFailure
     */
    public function keyFor(OidcProviderMetadata $metadata, string $algorithm, ?string $kid): Key
    {
        $keys = $this->keys($metadata, $algorithm, false);
        $found = $this->pick($keys, $kid);

        if ($found !== null) {
            return $found;
        }

        if ($this->mayRefresh($metadata->jwksUri)) {
            $found = $this->pick($this->keys($metadata, $algorithm, true), $kid);
        }

        if ($found === null) {
            throw new SsoFailure($kid === null ? 'jwks_kid_missing' : 'jwks_kid_unknown');
        }

        return $found;
    }

    /**
     * @return array<string, Key> kid => chave (chave sem kid recebe índice numérico em string)
     *
     * @throws SsoFailure
     */
    public function keys(OidcProviderMetadata $metadata, string $algorithm, bool $fresh): array
    {
        $cacheKey = 'sso:oidc:jwks:'.hash('sha256', $metadata->jwksUri);
        $document = $fresh ? null : Cache::get($cacheKey);

        if (! is_array($document)) {
            $document = $this->http->getJson($metadata->jwksUri, KnownOidcProviders::allowedHostsFor($metadata->issuer));
            Cache::put($cacheKey, $document, now()->addMinutes(max(1, (int) config('assinavelox.sso.discovery_cache_minutes', 60))));
        }

        $entries = $document['keys'] ?? null;

        if (! is_array($entries)) {
            throw new SsoFailure('jwks_invalid');
        }

        $keys = [];

        foreach (array_values($entries) as $index => $jwk) {
            if (! is_array($jwk) || count($keys) >= 50) {
                continue;
            }

            $kty = $jwk['kty'] ?? null;

            if (! in_array($kty, ['RSA', 'EC'], true)) {
                continue;
            }

            if (isset($jwk['use']) && $jwk['use'] !== 'sig') {
                continue;
            }

            if (isset($jwk['alg']) && $jwk['alg'] !== $algorithm) {
                continue;
            }

            if (($kty === 'RSA') !== (str_starts_with($algorithm, 'RS') || str_starts_with($algorithm, 'PS'))) {
                continue;
            }

            try {
                $parsed = JWK::parseKey([...$jwk, 'alg' => $algorithm], $algorithm);
            } catch (Throwable) {
                continue;
            }

            if ($parsed === null) {
                continue;
            }

            $kid = is_string($jwk['kid'] ?? null) && $jwk['kid'] !== '' ? $jwk['kid'] : '#'.$index;
            $keys[$kid] = $parsed;
        }

        return $keys;
    }

    /**
     * @param  array<string, Key>  $keys
     */
    private function pick(array $keys, ?string $kid): ?Key
    {
        if ($kid !== null) {
            return $keys[$kid] ?? null;
        }

        // Sem `kid` no cabeçalho: só aceitável com UMA chave elegível.
        return count($keys) === 1 ? array_values($keys)[0] : null;
    }

    private function mayRefresh(string $jwksUri): bool
    {
        $cooldown = max(1, (int) config('assinavelox.sso.jwks_refresh_cooldown_seconds', 60));

        return Cache::add('sso:oidc:jwks-refresh:'.hash('sha256', $jwksUri), 1, $cooldown);
    }

    /**
     * @param  array<string, mixed>  $document
     *
     * @throws SsoFailure
     */
    private function fromArray(string $issuer, array $document): OidcProviderMetadata
    {
        if (! is_string($document['issuer'] ?? null) || $document['issuer'] !== $issuer) {
            throw new SsoFailure('discovery_issuer_mismatch', 'O emissor informado pelo provedor não confere com o configurado.');
        }

        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $field) {
            if (! is_string($document[$field] ?? null) || $document[$field] === '') {
                throw new SsoFailure('discovery_incomplete', 'O provedor não publicou os endereços obrigatórios do OpenID Connect.');
            }
        }

        $list = static function (mixed $value): ?array {
            if (! is_array($value)) {
                return null;
            }

            return array_values(array_filter($value, 'is_string'));
        };

        return new OidcProviderMetadata(
            issuer: $issuer,
            authorizationEndpoint: $document['authorization_endpoint'],
            tokenEndpoint: $document['token_endpoint'],
            jwksUri: $document['jwks_uri'],
            tokenAuthMethods: $list($document['token_endpoint_auth_methods_supported'] ?? null) ?? ['client_secret_basic'],
            codeChallengeMethods: $list($document['code_challenge_methods_supported'] ?? null),
            idTokenAlgorithms: $list($document['id_token_signing_alg_values_supported'] ?? null),
            responseTypes: $list($document['response_types_supported'] ?? null),
        );
    }

    /**
     * O endpoint de autorização só é aberto pelo NAVEGADOR (redirect), então não há chamada do
     * servidor a proteger; ainda assim ele precisa ser https (http só fora de produção) e, com
     * provedor conhecido, estar nos hosts dele.
     *
     * @param  list<string>|null  $allowed
     *
     * @throws SsoFailure
     */
    private function assertBrowserUrl(string $url, ?array $allowed): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $httpAllowed = ! app()->environment('production') && (bool) config('assinavelox.webhooks.allow_http', false);

        if ($host === '' || ($scheme !== 'https' && ! ($scheme === 'http' && $httpAllowed)) || parse_url($url, PHP_URL_USER) !== null) {
            throw new SsoFailure('discovery_authorization_endpoint_invalid', 'O endereço de autorização do provedor é inválido.');
        }

        if ($allowed !== null) {
            foreach ($allowed as $suffix) {
                if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                    return;
                }
            }

            throw new SsoFailure('url_blocked:host_not_in_provider_allowlist', 'Este endereço não pertence ao provedor de identidade configurado.');
        }
    }
}
