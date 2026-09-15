<?php

namespace App\Integrations\Sso\Saml;

use App\Services\Sso\SsoFailure;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Algoritmos aceitos nas assinaturas XML da resposta SAML. O onelogin/php-saml 4.3.2 ainda
 * aceita RSA-SHA1 e digest SHA-1 ao validar; aqui eles são recusados ANTES da validação, junto
 * de qualquer algoritmo fora da lista (HMAC incluído: assinatura simétrica não identifica o IdP).
 */
final class SamlSignaturePolicy
{
    private const SIGNATURE_METHODS = [
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha384',
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512',
        'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256',
        'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha384',
        'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha512',
    ];

    private const DIGEST_METHODS = [
        'http://www.w3.org/2001/04/xmlenc#sha256',
        'http://www.w3.org/2001/04/xmldsig-more#sha384',
        'http://www.w3.org/2001/04/xmlenc#sha512',
    ];

    /**
     * @throws SsoFailure
     */
    public static function assertStrong(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        foreach ($xpath->query('//ds:SignatureMethod') ?: [] as $node) {
            if (! $node instanceof DOMElement || ! in_array($node->getAttribute('Algorithm'), self::SIGNATURE_METHODS, true)) {
                throw new SsoFailure('saml_weak_algorithm');
            }
        }

        foreach ($xpath->query('//ds:DigestMethod') ?: [] as $node) {
            if (! $node instanceof DOMElement || ! in_array($node->getAttribute('Algorithm'), self::DIGEST_METHODS, true)) {
                throw new SsoFailure('saml_weak_algorithm');
            }
        }
    }
}
