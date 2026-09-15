<?php

namespace App\Integrations\Sso\Saml;

use App\Models\SsoConnection;
use OneLogin\Saml2\Constants;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * Configuração do onelogin/php-saml 4.3.2 para uma conexão (docs/fase-3/sso.md §7), seguindo o
 * README oficial do toolkit:
 *
 *  - `strict = true` (produção "MUST");
 *  - `wantAssertionsSigned = true`: a ASSERTION precisa vir assinada com o certificado
 *    configurado (Response assinada sozinha não basta — defesa contra wrapping);
 *  - `rejectUnsolicitedResponsesWithInResponseTo = true` (vem desligado no toolkit);
 *  - `destinationStrictlyMatches`, validação do XML pelo schema e algoritmos SHA-256 (a
 *    recusa de SHA-1 é nossa, SamlSignaturePolicy, porque o toolkit ainda aceita SHA-1).
 *
 * O SP não tem chave privada: AuthnRequest sai sem assinatura (opcional no perfil Web SSO) e
 * assertion cifrada não é suportada.
 */
final class SamlSettingsFactory
{
    public function spEntityId(SsoConnection $connection): string
    {
        return route('sso.saml.metadata', ['connection' => $connection->ulid]);
    }

    public function acsUrl(SsoConnection $connection): string
    {
        return route('sso.saml.acs', ['connection' => $connection->ulid]);
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(SsoConnection $connection): array
    {
        return [
            'strict' => true,
            'debug' => false,
            'baseurl' => null,
            'sp' => [
                'entityId' => $this->spEntityId($connection),
                'assertionConsumerService' => [
                    'url' => $this->acsUrl($connection),
                    'binding' => Constants::BINDING_HTTP_POST,
                ],
                'NameIDFormat' => Constants::NAMEID_UNSPECIFIED,
                'x509cert' => '',
                'privateKey' => '',
            ],
            'idp' => [
                'entityId' => (string) $connection->saml_idp_entity_id,
                'singleSignOnService' => [
                    'url' => (string) $connection->saml_idp_sso_url,
                    'binding' => Constants::BINDING_HTTP_REDIRECT,
                ],
                'x509certMulti' => ['signing' => $connection->idpCertificates()],
            ],
            'security' => [
                'authnRequestsSigned' => false,
                'logoutRequestSigned' => false,
                'logoutResponseSigned' => false,
                'signMetadata' => false,
                'wantMessagesSigned' => false,
                'wantAssertionsSigned' => true,
                'wantAssertionsEncrypted' => false,
                'wantNameId' => true,
                'wantNameIdEncrypted' => false,
                'wantXMLValidation' => true,
                'requestedAuthnContext' => false,
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
                'destinationStrictlyMatches' => true,
                'relaxDestinationValidation' => false,
                'allowRepeatAttributeName' => false,
                'lowercaseUrlencoding' => false,
                'signatureAlgorithm' => XMLSecurityKey::RSA_SHA256,
                'digestAlgorithm' => XMLSecurityDSig::SHA256,
            ],
        ];
    }
}
