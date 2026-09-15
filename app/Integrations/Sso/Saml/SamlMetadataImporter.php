<?php

namespace App\Integrations\Sso\Saml;

use App\Integrations\Sso\SsoHttpClient;
use App\Services\Sso\SsoFailure;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\IdPMetadataParser;
use Throwable;

/**
 * Importa entityID, URL de SSO (HTTP-Redirect) e certificados de assinatura do metadata do IdP.
 * Por URL, a busca passa pela proteção contra SSRF (SsoHttpClient) — o `parseRemoteXML` do
 * toolkit NÃO é usado (segue redirecionamentos e não valida o destino). O XML é lido pelo
 * `Utils::loadXML` do toolkit, que recusa DOCTYPE/entidades (XXE).
 */
final class SamlMetadataImporter
{
    public function __construct(private readonly SsoHttpClient $http) {}

    /**
     * @return array{entity_id: string, sso_url: string, certificates: list<string>}
     *
     * @throws SsoFailure
     */
    public function fromUrl(string $url): array
    {
        return $this->fromXml($this->http->getText($url));
    }

    /**
     * @return array{entity_id: string, sso_url: string, certificates: list<string>}
     *
     * @throws SsoFailure
     */
    public function fromXml(string $xml): array
    {
        if (strlen($xml) > 512 * 1024 || trim($xml) === '') {
            throw new SsoFailure('saml_metadata_invalid', 'Metadata inválido ou grande demais.');
        }

        try {
            $info = IdPMetadataParser::parseXML($xml, null, null, Constants::BINDING_HTTP_REDIRECT);
        } catch (Throwable) {
            throw new SsoFailure('saml_metadata_invalid', 'Não foi possível ler o metadata do provedor.');
        }

        $idp = is_array($info['idp'] ?? null) ? $info['idp'] : [];
        $entityId = is_string($idp['entityId'] ?? null) ? trim($idp['entityId']) : '';
        $ssoUrl = is_array($idp['singleSignOnService'] ?? null) && is_string($idp['singleSignOnService']['url'] ?? null)
            ? trim($idp['singleSignOnService']['url'])
            : '';

        $raw = [];

        if (isset($idp['x509certMulti']['signing']) && is_array($idp['x509certMulti']['signing'])) {
            $raw = array_filter($idp['x509certMulti']['signing'], 'is_string');
        } elseif (is_string($idp['x509cert'] ?? null)) {
            $raw = [$idp['x509cert']];
        }

        if ($entityId === '' || $ssoUrl === '' || $raw === []) {
            throw new SsoFailure('saml_metadata_incomplete', 'O metadata não traz entityID, URL de login (HTTP-Redirect) e certificado de assinatura.');
        }

        $certificates = [];

        foreach ($raw as $certificate) {
            $certificates = [...$certificates, ...SamlCertificates::normalize($certificate)];
        }

        return [
            'entity_id' => $entityId,
            'sso_url' => $ssoUrl,
            'certificates' => array_values(array_unique(array_slice($certificates, 0, SamlCertificates::MAX_CERTIFICATES))),
        ];
    }
}
