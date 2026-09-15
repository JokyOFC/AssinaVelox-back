<?php

namespace App\Integrations\Sso\Oidc;

/**
 * Provedores OIDC conhecidos e os hosts em que publicam discovery, JWKS e token. Para eles, as
 * URLs vindas do discovery só são aceitas dentro desta lista (defesa em profundidade além da
 * proteção contra SSRF). Para provedor desconhecido (Keycloak próprio, ADFS...) vale só a
 * proteção contra SSRF: endereço público, https, sem redirecionamento, IP pinado.
 */
final class KnownOidcProviders
{
    /** @var array<string, list<string>> host do issuer (sufixo) => sufixos de host permitidos */
    private const PROVIDERS = [
        'accounts.google.com' => ['accounts.google.com', 'googleapis.com'],
        'login.microsoftonline.com' => ['login.microsoftonline.com'],
        'okta.com' => ['okta.com'],
        'oktapreview.com' => ['oktapreview.com'],
        'auth0.com' => ['auth0.com'],
    ];

    /**
     * @return list<string>|null
     */
    public static function allowedHostsFor(string $issuer): ?array
    {
        $host = strtolower((string) parse_url($issuer, PHP_URL_HOST));

        if ($host === '') {
            return null;
        }

        foreach (self::PROVIDERS as $suffix => $hosts) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                // Tenant próprio (ex.: empresa.okta.com): o host do próprio issuer também vale.
                return array_values(array_unique([$host, ...$hosts]));
            }
        }

        return null;
    }
}
