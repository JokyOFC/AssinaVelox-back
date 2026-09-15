<?php

namespace App\Services\Sso;

use App\Models\Organization;
use App\Services\Envelopes\DomainFeatures;

/**
 * Flags `sso_oidc` e `sso_saml` (Fase 3 §3.9 — G-SSO, docs/fase-3/sso.md §1). Da ORGANIZAÇÃO:
 * interruptor global `assinavelox.features.sso_*` E `plans.features.sso_*` do plano vigente
 * (plano Empresarial), desligadas por padrão (T8).
 *
 * Desligadas, nada muda: rotas novas 404, a tela de login não mostra "Entrar com SSO", nenhuma
 * conexão autentica e a exigência de SSO não vale (a organização nunca fica trancada).
 */
final class SsoFeature
{
    public const OIDC = 'sso_oidc';

    public const SAML = 'sso_saml';

    public static function oidc(?Organization $organization): bool
    {
        return DomainFeatures::enabled(self::OIDC, $organization);
    }

    public static function saml(?Organization $organization): bool
    {
        return DomainFeatures::enabled(self::SAML, $organization);
    }

    public static function enabledFor(SsoProtocol $protocol, ?Organization $organization): bool
    {
        return DomainFeatures::enabled($protocol->flag(), $organization);
    }

    public static function anyFor(?Organization $organization): bool
    {
        return self::oidc($organization) || self::saml($organization);
    }

    public static function globalOidc(): bool
    {
        return (bool) config('assinavelox.features.'.self::OIDC, false);
    }

    public static function globalSaml(): bool
    {
        return (bool) config('assinavelox.features.'.self::SAML, false);
    }

    public static function anyGlobal(): bool
    {
        return self::globalOidc() || self::globalSaml();
    }

    /**
     * Chaves da prop compartilhada `features`. Sem organização (tela de login, cadastro) vale só
     * o interruptor global — como `cnpj_lookup` —, para a tela de login saber se oferece
     * "Entrar com SSO"; o plano é conferido quando o domínio do e-mail leva à organização.
     *
     * @return array{sso_oidc: bool, sso_saml: bool}
     */
    public static function forFeatures(?Organization $organization): array
    {
        if ($organization === null) {
            return [self::OIDC => self::globalOidc(), self::SAML => self::globalSaml()];
        }

        return [self::OIDC => self::oidc($organization), self::SAML => self::saml($organization)];
    }
}
