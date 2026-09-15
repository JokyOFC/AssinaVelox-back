<?php

namespace App\Integrations\Sso\Saml;

use App\Models\SsoConnection;
use App\Services\Sso\SsoFailure;
use App\Services\Sso\VerifiedIdentity;
use DOMDocument;
use DOMElement;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\Response;
use OneLogin\Saml2\Settings;
use OneLogin\Saml2\Utils;
use OneLogin\Saml2\ValidationError;
use Throwable;

/**
 * Adaptador SAML 2.0 sobre onelogin/php-saml 4.3.2 (docs/fase-3/sso.md §7). Faz o que o toolkit
 * faz (assinatura com o certificado configurado, Audience, Recipient, InResponseTo,
 * NotBefore/NotOnOrAfter, schema) e acrescenta o que ele NÃO faz:
 *
 *  - Destination OBRIGATÓRIO e IGUAL ao ACS (o toolkit aceita prefixo);
 *  - recusa de SHA-1 e de algoritmo fora da lista (SamlSignaturePolicy);
 *  - teto de tamanho antes do parse; assertion cifrada recusada (o SP não tem chave privada);
 *  - a proteção contra replay (SamlReplayGuard) e a ligação do InResponseTo ao pedido pendente
 *    ficam no fluxo (App\Services\Sso\SamlLoginFlow).
 *
 * O toolkit calcula a URL corrente a partir de `$_SERVER`; aqui ela é fixada no ACS derivado
 * de APP_URL durante a validação (e restaurada depois), para não depender do Host recebido.
 */
final class SamlAdapter
{
    public function __construct(private readonly SamlSettingsFactory $settings) {}

    /**
     * URL de redirecionamento ao IdP (HTTP-Redirect) e o ID do AuthnRequest emitido.
     *
     * @return array{url: string, request_id: string}
     *
     * @throws SsoFailure
     */
    public function loginRequest(SsoConnection $connection): array
    {
        try {
            $auth = new Auth($this->settings->settings($connection));
            $url = $auth->login('assinavelox', [], false, false, true);
            $requestId = $auth->getLastRequestID();
        } catch (Throwable) {
            throw new SsoFailure('saml_configuration_invalid', 'A configuração SAML desta conexão está incompleta ou inválida.');
        }

        if ($url === '' || $requestId === '') {
            throw new SsoFailure('saml_configuration_invalid', 'A configuração SAML desta conexão está incompleta ou inválida.');
        }

        return ['url' => $url, 'request_id' => $requestId];
    }

    /**
     * Leitura prévia (só para achar o pedido pendente e recusar cedo): o XML ainda NÃO é
     * confiável — nada daqui é usado como identidade.
     *
     * @return array{in_response_to: string|null}
     *
     * @throws SsoFailure
     */
    public function inspect(SsoConnection $connection, string $encoded): array
    {
        $document = $this->load($encoded);
        $root = $document->documentElement;

        if (! $root instanceof DOMElement || $root->localName !== 'Response' || $root->namespaceURI !== Constants::NS_SAMLP) {
            throw new SsoFailure('saml_invalid');
        }

        if ($document->getElementsByTagNameNS(Constants::NS_SAML, 'EncryptedAssertion')->length > 0) {
            throw new SsoFailure('saml_encrypted_assertion_unsupported');
        }

        $destination = trim($root->getAttribute('Destination'));

        if ($destination === '' || $destination !== $this->settings->acsUrl($connection)) {
            throw new SsoFailure('saml_destination');
        }

        SamlSignaturePolicy::assertStrong($document);

        $inResponseTo = $root->hasAttribute('InResponseTo') ? trim($root->getAttribute('InResponseTo')) : null;

        return ['in_response_to' => $inResponseTo === '' ? null : $inResponseTo];
    }

    /**
     * Validação completa. `$requestId` nulo = resposta iniciada pelo IdP (só com a conexão
     * permitindo); com InResponseTo presente e `$requestId` nulo o toolkit recusa.
     *
     * @return array{identity: VerifiedIdentity, assertion_id: string, not_on_or_after: int|null}
     *
     * @throws SsoFailure
     */
    public function consume(SsoConnection $connection, string $encoded, ?string $requestId): array
    {
        $this->inspect($connection, $encoded);

        try {
            $response = $this->withRequestContext($connection, function () use ($connection, $encoded, $requestId): Response {
                $response = new Response(new Settings($this->settings->settings($connection)), $encoded);

                if (! $response->isValid($requestId)) {
                    $error = $response->getErrorException();

                    throw new SsoFailure(self::reasonFor($error));
                }

                return $response;
            });
        } catch (SsoFailure $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new SsoFailure('saml_invalid');
        }

        try {
            $assertionId = (string) $response->getAssertionId();
            // O prazo da guarda contra replay é o MAIOR entre SubjectConfirmationData@NotOnOrAfter
            // e Conditions@NotOnOrAfter: o toolkit aceita SCD sem o atributo e confere o tempo só
            // pelas Conditions (revisão G — replay depois do `sso:prune`).
            $notOnOrAfter = max((int) $response->getAssertionNotOnOrAfter(), (int) $this->conditionsNotOnOrAfter($response));
            $nameId = trim((string) $response->getNameId());
            $nameIdFormat = (string) $response->getNameIdFormat();
            $attributes = $response->getAttributes();
        } catch (Throwable) {
            throw new SsoFailure('saml_invalid');
        }

        if ($assertionId === '') {
            throw new SsoFailure('saml_invalid');
        }

        $email = $this->emailFrom($attributes, $nameId, $nameIdFormat);

        if ($email === null) {
            throw new SsoFailure('email_missing', 'O provedor de identidade não enviou o e-mail do usuário.');
        }

        // NameID transitório muda a cada login: o e-mail (de domínio verificado) vira o sujeito.
        $subject = ($nameId === '' || $nameIdFormat === Constants::NAMEID_TRANSIENT) ? 'email:'.$email : $nameId;

        return [
            'identity' => new VerifiedIdentity(
                subject: mb_substr($subject, 0, 255),
                email: $email,
                name: $this->nameFrom($attributes),
                // SAML não tem `email_verified`: o IdP afirma, e o domínio verificado por TXT
                // (SsoLoginCompleter) é o que liga o e-mail à organização.
                emailVerified: true,
            ),
            'assertion_id' => $assertionId,
            'not_on_or_after' => $notOnOrAfter > 0 ? $notOnOrAfter : null,
        ];
    }

    /**
     * Conditions@NotOnOrAfter da assertion (no documento já decifrado, quando ela vem cifrada);
     * 0 sem o atributo. Só é lido depois de `isValid()`: a assinatura já conferiu.
     */
    private function conditionsNotOnOrAfter(Response $response): int
    {
        try {
            $xpath = new \DOMXPath($response->getXMLDocument());
            $xpath->registerNamespace('saml', Constants::NS_SAML);
            $nodes = $xpath->query('//saml:Assertion/saml:Conditions/@NotOnOrAfter');
            $latest = 0;

            foreach ($nodes === false ? [] : $nodes as $attribute) {
                $latest = max($latest, (int) Utils::parseSAML2Time(trim((string) $attribute->nodeValue)));
            }

            return $latest;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @throws SsoFailure
     */
    private function load(string $encoded): DOMDocument
    {
        $maxBytes = max(1024, (int) config('assinavelox.sso.saml.max_response_kb', 256) * 1024);

        if ($encoded === '' || strlen($encoded) > $maxBytes) {
            throw new SsoFailure('saml_invalid');
        }

        $xml = base64_decode($encoded, true);

        if (! is_string($xml) || $xml === '') {
            throw new SsoFailure('saml_invalid');
        }

        try {
            // Utils::loadXML recusa DOCTYPE/entidades (XXE) e não carrega rede.
            $document = Utils::loadXML(new DOMDocument, $xml);
        } catch (Throwable) {
            throw new SsoFailure('saml_invalid');
        }

        if (! $document instanceof DOMDocument) {
            throw new SsoFailure('saml_invalid');
        }

        return $document;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withRequestContext(SsoConnection $connection, callable $callback): mixed
    {
        $acs = $this->settings->acsUrl($connection);
        $parts = parse_url($acs);
        $keys = ['REQUEST_URI', 'QUERY_STRING', 'SCRIPT_NAME', 'PATH_INFO'];
        $saved = [];

        foreach ($keys as $key) {
            $saved[$key] = array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $path = (string) ($parts['path'] ?? '/');

        Utils::setBaseURL('');
        Utils::setProxyVars(false);
        Utils::setSelfProtocol($scheme);
        Utils::setSelfHost((string) ($parts['host'] ?? ''));
        Utils::setSelfPort(isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80));
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['SCRIPT_NAME'] = $path;
        unset($_SERVER['QUERY_STRING'], $_SERVER['PATH_INFO']);

        try {
            return $callback();
        } finally {
            Utils::setBaseURL('');

            foreach ($saved as $key => $value) {
                if ($value === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $value;
                }
            }
        }
    }

    private static function reasonFor(?Throwable $error): string
    {
        if (! $error instanceof ValidationError) {
            // O xmlseclibs lança Exception comum quando o digest da referência não confere
            // (conteúdo alterado depois da assinatura).
            return $error !== null && str_contains($error->getMessage(), 'Reference validation failed')
                ? 'saml_signature_invalid'
                : 'saml_invalid';
        }

        return match ($error->getCode()) {
            ValidationError::INVALID_SIGNATURE => 'saml_signature_invalid',
            ValidationError::NO_SIGNED_ASSERTION, ValidationError::NO_SIGNATURE_FOUND => 'saml_unsigned_assertion',
            ValidationError::WRONG_DESTINATION, ValidationError::EMPTY_DESTINATION => 'saml_destination',
            ValidationError::WRONG_AUDIENCE => 'saml_audience',
            ValidationError::WRONG_ISSUER, ValidationError::ISSUER_MULTIPLE_IN_RESPONSE, ValidationError::ISSUER_NOT_FOUND_IN_ASSERTION => 'saml_issuer',
            ValidationError::WRONG_INRESPONSETO => 'saml_in_response_to',
            ValidationError::ASSERTION_EXPIRED, ValidationError::SESSION_EXPIRED, ValidationError::RESPONSE_EXPIRED => 'saml_expired',
            ValidationError::ASSERTION_TOO_EARLY => 'saml_not_yet_valid',
            ValidationError::WRONG_SUBJECTCONFIRMATION => 'saml_subject_confirmation',
            ValidationError::WRONG_NUMBER_OF_ASSERTIONS, ValidationError::WRONG_SIGNED_ELEMENT, ValidationError::UNEXPECTED_SIGNED_ELEMENTS,
            ValidationError::ID_NOT_FOUND_IN_SIGNED_ELEMENT, ValidationError::DUPLICATED_ID_IN_SIGNED_ELEMENTS,
            ValidationError::DUPLICATED_REFERENCE_IN_SIGNED_ELEMENTS, ValidationError::WRONG_NUMBER_OF_SIGNATURES_IN_RESPONSE,
            ValidationError::WRONG_NUMBER_OF_SIGNATURES_IN_ASSERTION, ValidationError::WRONG_NUMBER_OF_SIGNATURES,
            ValidationError::UNEXPECTED_REFERENCE => 'saml_signature_structure',
            ValidationError::STATUS_CODE_IS_NOT_SUCCESS => 'provider_error',
            default => 'saml_invalid',
        };
    }

    /**
     * @param  array<string, list<string>>  $attributes
     */
    private function emailFrom(array $attributes, string $nameId, string $nameIdFormat): ?string
    {
        $candidates = [];

        foreach (['email', 'mail', 'emailaddress', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress', 'urn:oid:0.9.2342.19200300.100.1.3'] as $name) {
            foreach ($attributes as $key => $values) {
                if (strcasecmp((string) $key, $name) === 0) {
                    $candidates = [...$candidates, ...array_filter($values, 'is_string')];
                }
            }
        }

        if ($nameIdFormat === Constants::NAMEID_EMAIL_ADDRESS || str_contains($nameId, '@')) {
            $candidates[] = $nameId;
        }

        foreach ($candidates as $candidate) {
            $candidate = mb_strtolower(trim($candidate));

            if ($candidate !== '' && strlen($candidate) <= 254 && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, list<string>>  $attributes
     */
    private function nameFrom(array $attributes): ?string
    {
        $pick = static function (array $names) use ($attributes): ?string {
            foreach ($names as $name) {
                foreach ($attributes as $key => $values) {
                    if (strcasecmp((string) $key, $name) === 0 && is_string($values[0] ?? null) && trim($values[0]) !== '') {
                        return trim($values[0]);
                    }
                }
            }

            return null;
        };

        $display = $pick(['displayName', 'name', 'http://schemas.microsoft.com/identity/claims/displayname', 'urn:oid:2.16.840.1.113730.3.1.241']);

        if ($display !== null) {
            return mb_substr($display, 0, 120);
        }

        $given = $pick(['givenName', 'firstName', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname', 'urn:oid:2.5.4.42']);
        $family = $pick(['sn', 'surname', 'lastName', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname', 'urn:oid:2.5.4.4']);
        $full = trim(($given ?? '').' '.($family ?? ''));

        return $full === '' ? null : mb_substr($full, 0, 120);
    }
}
