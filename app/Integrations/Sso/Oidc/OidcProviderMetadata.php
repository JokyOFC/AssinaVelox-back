<?php

namespace App\Integrations\Sso\Oidc;

/**
 * Metadados do provedor lidos do discovery (OpenID Connect Discovery 1.0 §3), já conferidos:
 * o `issuer` é EXATAMENTE o configurado na conexão (§4.3) e os endpoints usados existem.
 */
final class OidcProviderMetadata
{
    /**
     * @param  list<string>  $tokenAuthMethods
     * @param  list<string>|null  $codeChallengeMethods
     * @param  list<string>|null  $idTokenAlgorithms
     * @param  list<string>|null  $responseTypes
     */
    public function __construct(
        public readonly string $issuer,
        public readonly string $authorizationEndpoint,
        public readonly string $tokenEndpoint,
        public readonly string $jwksUri,
        public readonly array $tokenAuthMethods,
        public readonly ?array $codeChallengeMethods,
        public readonly ?array $idTokenAlgorithms,
        public readonly ?array $responseTypes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'issuer' => $this->issuer,
            'authorization_endpoint' => $this->authorizationEndpoint,
            'token_endpoint' => $this->tokenEndpoint,
            'jwks_uri' => $this->jwksUri,
            'token_endpoint_auth_methods_supported' => $this->tokenAuthMethods,
            'code_challenge_methods_supported' => $this->codeChallengeMethods,
            'id_token_signing_alg_values_supported' => $this->idTokenAlgorithms,
            'response_types_supported' => $this->responseTypes,
        ];
    }
}
