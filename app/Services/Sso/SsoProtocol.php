<?php

namespace App\Services\Sso;

/**
 * Protocolo de uma conexão de login corporativo (roadmap §3.9, docs/fase-3/sso.md §3).
 */
enum SsoProtocol: string
{
    case Oidc = 'oidc';
    case Saml = 'saml';

    public function label(): string
    {
        return match ($this) {
            self::Oidc => 'OpenID Connect (OIDC)',
            self::Saml => 'SAML 2.0',
        };
    }

    /** Chave da flag em `assinavelox.features`. */
    public function flag(): string
    {
        return match ($this) {
            self::Oidc => SsoFeature::OIDC,
            self::Saml => SsoFeature::SAML,
        };
    }
}
