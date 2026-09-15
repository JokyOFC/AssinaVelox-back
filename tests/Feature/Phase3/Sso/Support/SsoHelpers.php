<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes da Fase 3 §3.9 — G-SSO (login corporativo OIDC/SAML)
|--------------------------------------------------------------------------
| Incluído com require_once pelos arquivos de teste. Não contém testes.
|
| O provedor de identidade é SIMULADO dentro do teste: chaves RSA/EC geradas aqui, JWKS e
| discovery por Http::fake, id_token assinado com firebase/php-jwt, certificado de IdP de teste
| e respostas SAML assinadas com xmlseclibs. Nenhum teste depende de rede ou DNS reais.
*/

use App\Enums\MembershipRole;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SsoConnection;
use App\Models\SsoDomain;
use App\Models\User;
use App\Services\Sso\Domains\TxtRecordResolver;
use App\Services\Sso\SsoConnectionStatus;
use App\Services\Sso\SsoProtocol;
use App\Support\Http\DnsResolver;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use Tests\Feature\Phase2\Webhooks\Support\FakeDnsResolver;

require_once __DIR__.'/../../../Support/OrganizationHelpers.php';

const SSO_ISSUER = 'https://idp.example.com';
const SSO_CLIENT_ID = 'assinavelox-client';
const SSO_CLIENT_SECRET = 'segredo-do-cliente-de-teste-123';
const SSO_DOMAIN = 'empresa.com.br';
const SSO_PUBLIC_IP = '93.184.216.34';
const SSO_SAML_IDP_ENTITY = 'https://idp.example.com/saml/metadata';
const SSO_SAML_IDP_SSO = 'https://idp.example.com/saml/sso';

if (! function_exists('ssoOpensslOptions')) {
    /**
     * No Windows o OpenSSL do PHP não gera chave sem openssl.cnf: usa o do pacote do PHP.
     *
     * @return array<string, string>
     */
    function ssoOpensslOptions(): array
    {
        static $options = null;

        if ($options !== null) {
            return $options;
        }

        $probe = @openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        if ($probe !== false) {
            return $options = [];
        }

        foreach ([getenv('OPENSSL_CONF') ?: null, dirname(PHP_BINARY).'/extras/ssl/openssl.cnf'] as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                return $options = ['config' => $candidate];
            }
        }

        return $options = [];
    }
}

if (! function_exists('ssoB64u')) {
    function ssoB64u(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}

if (! function_exists('ssoRsaKey')) {
    /**
     * @return array{private: string, public: string, jwk: array<string, string>}
     */
    function ssoRsaKey(string $kid = 'k1'): array
    {
        $key = openssl_pkey_new(ssoOpensslOptions() + ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($key, $private, null, ssoOpensslOptions());
        $details = openssl_pkey_get_details($key);

        return [
            'private' => $private,
            'public' => $details['key'],
            'jwk' => ['kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'alg' => 'RS256', 'n' => ssoB64u($details['rsa']['n']), 'e' => ssoB64u($details['rsa']['e'])],
        ];
    }
}

if (! function_exists('ssoEcKey')) {
    /**
     * @return array{private: string, public: string, jwk: array<string, string>}
     */
    function ssoEcKey(string $kid = 'ec1'): array
    {
        $key = openssl_pkey_new(ssoOpensslOptions() + ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $private, null, ssoOpensslOptions());
        $details = openssl_pkey_get_details($key);

        return [
            'private' => $private,
            'public' => $details['key'],
            'jwk' => ['kty' => 'EC', 'kid' => $kid, 'use' => 'sig', 'alg' => 'ES256', 'crv' => 'P-256', 'x' => ssoB64u($details['ec']['x']), 'y' => ssoB64u($details['ec']['y'])],
        ];
    }
}

if (! function_exists('ssoCertificate')) {
    /**
     * Certificado autoassinado de TESTE do IdP SAML.
     *
     * @return array{private: string, certificate: string}
     */
    function ssoCertificate(string $cn = 'IdP de teste'): array
    {
        $options = ssoOpensslOptions();
        $key = openssl_pkey_new($options + ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => $cn], $key, $options + ['digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $key, 30, $options + ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        openssl_x509_export($x509, $certificate);
        openssl_pkey_export($key, $private, null, $options);

        return ['private' => $private, 'certificate' => $certificate];
    }
}

if (! function_exists('ssoEnable')) {
    function ssoEnable(Organization $organization, bool $oidc = true, bool $saml = true): void
    {
        config()->set('assinavelox.features.sso_oidc', $oidc);
        config()->set('assinavelox.features.sso_saml', $saml);

        /** @var Plan|null $plan */
        $plan = $organization->currentSubscription()->with('plan')->first()?->plan;

        if ($plan !== null) {
            $features = (array) ($plan->features ?? []);
            $features['sso_oidc'] = $oidc;
            $features['sso_saml'] = $saml;
            // Plano Empresarial: assentos suficientes para o provisionamento JIT dos testes.
            $plan->forceFill(['features' => $features, 'user_quota' => 50])->save();
        }
    }
}

if (! function_exists('ssoFakeDns')) {
    function ssoFakeDns(): FakeDnsResolver
    {
        $dns = (new FakeDnsResolver)
            ->set('idp.example.com', [SSO_PUBLIC_IP])
            ->set('rotated.example.com', [SSO_PUBLIC_IP])
            ->set('interno.example.com', ['10.0.0.5'])
            ->set('metadados.example.com', ['169.254.169.254']);

        app()->instance(DnsResolver::class, $dns);

        return $dns;
    }
}

if (! function_exists('ssoFakeTxt')) {
    /**
     * @param  array<string, list<string>>  $records
     */
    function ssoFakeTxt(array $records): void
    {
        app()->instance(TxtRecordResolver::class, new class($records) implements TxtRecordResolver
        {
            /** @param array<string, list<string>> $records */
            public function __construct(private array $records) {}

            public function txt(string $name): array
            {
                return $this->records[strtolower($name)] ?? [];
            }
        });
    }
}

if (! function_exists('ssoWorkspace')) {
    /**
     * Organização com owner, flags ligadas e domínio verificado.
     *
     * @return array{organization: Organization, owner: User}
     */
    function ssoWorkspace(bool $oidc = true, bool $saml = true, string $domain = SSO_DOMAIN): array
    {
        ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Empresa Exemplo']);
        ssoEnable($organization, $oidc, $saml);
        ssoFakeDns();

        if ($domain !== '') {
            ssoVerifiedDomain($organization, $domain);
        }

        return ['organization' => $organization, 'owner' => $owner];
    }
}

if (! function_exists('ssoVerifiedDomain')) {
    function ssoVerifiedDomain(Organization $organization, string $domain = SSO_DOMAIN): SsoDomain
    {
        return SsoDomain::withoutOrganizationScope()->create([
            'organization_id' => $organization->getKey(),
            'domain' => $domain,
            'verified_domain' => $domain,
            'verification_token' => str_repeat('a', 40),
            'verified_at' => Carbon::now(),
        ]);
    }
}

if (! function_exists('ssoOidcConnection')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function ssoOidcConnection(Organization $organization, array $overrides = []): SsoConnection
    {
        return SsoConnection::withoutOrganizationScope()->create([
            'organization_id' => $organization->getKey(),
            'protocol' => SsoProtocol::Oidc,
            'name' => 'Login da empresa',
            'status' => SsoConnectionStatus::Active,
            'oidc_issuer' => SSO_ISSUER,
            'oidc_client_id' => SSO_CLIENT_ID,
            'oidc_client_secret' => SSO_CLIENT_SECRET,
            'oidc_id_token_alg' => 'RS256',
            'last_test_status' => SsoConnection::TEST_OK,
            'last_tested_at' => Carbon::now(),
            ...$overrides,
        ]);
    }
}

if (! function_exists('ssoSamlConnection')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function ssoSamlConnection(Organization $organization, string $certificate, array $overrides = []): SsoConnection
    {
        return SsoConnection::withoutOrganizationScope()->create([
            'organization_id' => $organization->getKey(),
            'protocol' => SsoProtocol::Saml,
            'name' => 'SAML da empresa',
            'status' => SsoConnectionStatus::Active,
            'saml_idp_entity_id' => SSO_SAML_IDP_ENTITY,
            'saml_idp_sso_url' => SSO_SAML_IDP_SSO,
            'saml_idp_certificates' => [$certificate],
            'last_test_status' => SsoConnection::TEST_OK,
            'last_tested_at' => Carbon::now(),
            ...$overrides,
        ]);
    }
}

if (! function_exists('ssoMember')) {
    function ssoMember(Organization $organization, string $email = 'maria@'.SSO_DOMAIN, MembershipRole $role = MembershipRole::Member): User
    {
        $user = User::factory()->create(['email' => $email, 'name' => 'Maria Souza']);

        return attachMember($organization, $role, user: $user);
    }
}

// -- OIDC -------------------------------------------------------------------------------------

if (! function_exists('ssoDiscovery')) {
    /**
     * @return array<string, mixed>
     */
    function ssoDiscovery(array $overrides = []): array
    {
        return [
            'issuer' => SSO_ISSUER,
            'authorization_endpoint' => SSO_ISSUER.'/authorize',
            'token_endpoint' => SSO_ISSUER.'/token',
            'jwks_uri' => SSO_ISSUER.'/jwks',
            'response_types_supported' => ['code'],
            'code_challenge_methods_supported' => ['S256'],
            'id_token_signing_alg_values_supported' => ['RS256', 'ES256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            ...$overrides,
        ];
    }
}

if (! function_exists('ssoIdToken')) {
    /**
     * @param  array<string, mixed>  $claims
     */
    function ssoIdToken(array $claims, string $privateKey, string $alg = 'RS256', ?string $kid = 'k1'): string
    {
        return JWT::encode($claims, $privateKey, $alg, $kid);
    }
}

if (! function_exists('ssoClaims')) {
    /**
     * @return array<string, mixed>
     */
    function ssoClaims(string $nonce, array $overrides = []): array
    {
        $now = Carbon::now()->getTimestamp();

        return [
            'iss' => SSO_ISSUER,
            'aud' => SSO_CLIENT_ID,
            'sub' => 'sujeito-123',
            'email' => 'maria@'.SSO_DOMAIN,
            'email_verified' => true,
            'name' => 'Maria Souza',
            'nonce' => $nonce,
            'iat' => $now,
            'exp' => $now + 300,
            ...$overrides,
        ];
    }
}

if (! function_exists('ssoFakeOidcProvider')) {
    /**
     * IdP OIDC simulado. `$tokens` recebe o pedido ao token endpoint e devolve o id_token (e o
     * access_token) — assim o teste monta o token com o nonce que o fluxo acabou de gerar.
     *
     * @param  list<array<string, string>>|callable(): list<array<string, string>>  $jwks
     * @param  callable(HttpRequest): array<string, mixed>  $tokens
     * @param  array<string, mixed>|callable(): array<string, mixed>  $discovery
     */
    function ssoFakeOidcProvider(array|callable $jwks, callable $tokens, array|callable $discovery = []): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'idp.example.com/.well-known/openid-configuration' => fn () => Http::response(ssoDiscovery(is_callable($discovery) ? $discovery() : $discovery)),
            'idp.example.com/jwks' => fn () => Http::response(['keys' => is_callable($jwks) ? $jwks() : $jwks]),
            'idp.example.com/token' => fn (HttpRequest $request) => Http::response($tokens($request)),
        ]);
    }
}

if (! function_exists('ssoAuthorizeParams')) {
    /**
     * Parâmetros da URL de autorização devolvida (Location do 302 ou X-Inertia-Location).
     *
     * @return array<string, string>
     */
    function ssoAuthorizeParams(TestResponse $response): array
    {
        $location = $response->headers->get('X-Inertia-Location') ?? $response->headers->get('Location');
        expect($location)->toBeString();
        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

        return array_map('strval', $query);
    }
}

if (! function_exists('ssoStartOidc')) {
    /**
     * Começa o login pela tela de login e devolve os parâmetros enviados ao provedor.
     *
     * @return array<string, string>
     */
    function ssoStartOidc(object $test, string $email = 'maria@'.SSO_DOMAIN): array
    {
        $response = $test->post(route('sso.login.start'), ['email' => $email]);
        $response->assertRedirect();

        return ssoAuthorizeParams($response);
    }
}

if (! function_exists('ssoLastAudit')) {
    /**
     * Payload do último evento do tipo informado na organização.
     *
     * @return array<string, mixed>|null
     */
    function ssoLastAudit(Organization $organization, string $type): ?array
    {
        $event = AuditEvent::withoutOrganizationScope()
            ->where('organization_id', $organization->getKey())
            ->where('event_type', $type)
            ->latest('id')
            ->first();

        return $event === null ? null : (array) ($event->payload ?? []);
    }
}

// -- SAML -------------------------------------------------------------------------------------

if (! function_exists('ssoSamlTime')) {
    function ssoSamlTime(int $offsetSeconds = 0): string
    {
        // O toolkit usa time() (não o relógio do Laravel).
        return gmdate('Y-m-d\TH:i:s\Z', time() + $offsetSeconds);
    }
}

if (! function_exists('ssoSamlXml')) {
    /**
     * Resposta SAML (sem assinatura). Opções: destination, in_response_to (null = omitido),
     * audience, recipient, issuer, email, name_id, name_id_format, assertion_id, not_on_or_after.
     *
     * @param  array<string, mixed>  $o
     */
    function ssoSamlXml(SsoConnection $connection, array $o = []): string
    {
        $acs = route('sso.saml.acs', ['connection' => $connection->ulid]);
        $sp = route('sso.saml.metadata', ['connection' => $connection->ulid]);
        $destination = $o['destination'] ?? $acs;
        $inResponseTo = array_key_exists('in_response_to', $o) ? $o['in_response_to'] : null;
        $irt = $inResponseTo === null ? '' : ' InResponseTo="'.htmlspecialchars((string) $inResponseTo).'"';
        $issuer = $o['issuer'] ?? SSO_SAML_IDP_ENTITY;
        $email = $o['email'] ?? 'maria@'.SSO_DOMAIN;
        $nameId = $o['name_id'] ?? $email;
        $format = $o['name_id_format'] ?? 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress';
        $assertionId = $o['assertion_id'] ?? '_a'.bin2hex(random_bytes(16));
        $notOnOrAfter = ssoSamlTime((int) ($o['not_on_or_after'] ?? 300));
        $audience = $o['audience'] ?? $sp;
        $recipient = $o['recipient'] ?? $acs;
        $now = ssoSamlTime();
        $notBefore = ssoSamlTime(-60);

        return '<?xml version="1.0"?>'
            .'<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_r'.bin2hex(random_bytes(16)).'" Version="2.0" IssueInstant="'.$now.'" Destination="'.htmlspecialchars((string) $destination).'"'.$irt.'>'
            .'<saml:Issuer>'.htmlspecialchars((string) $issuer).'</saml:Issuer>'
            .'<samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>'
            .'<saml:Assertion ID="'.$assertionId.'" Version="2.0" IssueInstant="'.$now.'">'
            .'<saml:Issuer>'.htmlspecialchars((string) $issuer).'</saml:Issuer>'
            .'<saml:Subject>'
            .'<saml:NameID Format="'.$format.'">'.htmlspecialchars((string) $nameId).'</saml:NameID>'
            .'<saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer">'
            .'<saml:SubjectConfirmationData NotOnOrAfter="'.$notOnOrAfter.'" Recipient="'.htmlspecialchars((string) $recipient).'"'.$irt.'/>'
            .'</saml:SubjectConfirmation>'
            .'</saml:Subject>'
            .'<saml:Conditions NotBefore="'.$notBefore.'" NotOnOrAfter="'.$notOnOrAfter.'">'
            .'<saml:AudienceRestriction><saml:Audience>'.htmlspecialchars((string) $audience).'</saml:Audience></saml:AudienceRestriction>'
            .'</saml:Conditions>'
            .'<saml:AuthnStatement AuthnInstant="'.$now.'" SessionIndex="_s1">'
            .'<saml:AuthnContext><saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport</saml:AuthnContextClassRef></saml:AuthnContext>'
            .'</saml:AuthnStatement>'
            .'<saml:AttributeStatement>'
            .'<saml:Attribute Name="email"><saml:AttributeValue>'.htmlspecialchars((string) $email).'</saml:AttributeValue></saml:Attribute>'
            .'<saml:Attribute Name="displayName"><saml:AttributeValue>Maria Souza</saml:AttributeValue></saml:Attribute>'
            .'</saml:AttributeStatement>'
            .'</saml:Assertion>'
            .'</samlp:Response>';
    }
}

if (! function_exists('ssoSamlSign')) {
    /**
     * Assina com xmlseclibs a Assertion (`assertion`) e/ou a Response (`response`). `sha1`
     * troca assinatura e digest para SHA-1.
     */
    function ssoSamlSign(string $xml, array $idp, bool $assertion = true, bool $response = false, bool $sha1 = false): string
    {
        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $ns = 'urn:oasis:names:tc:SAML:2.0:assertion';

        $sign = function (DOMElement $node) use ($idp, $sha1, $ns): void {
            $dsig = new XMLSecurityDSig;
            $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
            $dsig->addReference(
                $node,
                $sha1 ? XMLSecurityDSig::SHA1 : XMLSecurityDSig::SHA256,
                ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::EXC_C14N],
                ['id_name' => 'ID', 'overwrite' => false],
            );
            $key = new XMLSecurityKey($sha1 ? XMLSecurityKey::RSA_SHA1 : XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
            $key->loadKey($idp['private']);
            $dsig->sign($key);
            $dsig->add509Cert($idp['certificate']);

            $issuer = null;

            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement && $child->localName === 'Issuer' && $child->namespaceURI === $ns) {
                    $issuer = $child;
                    break;
                }
            }

            $dsig->insertSignature($node, $issuer?->nextSibling);
        };

        if ($assertion) {
            $sign($dom->getElementsByTagNameNS($ns, 'Assertion')->item(0));
        }

        if ($response) {
            $sign($dom->documentElement);
        }

        return $dom->saveXML();
    }
}

if (! function_exists('ssoStartSaml')) {
    /**
     * Começa o login SAML pela tela de login: devolve o ID do AuthnRequest e o valor do cookie
     * que liga o pedido ao navegador.
     *
     * @return array{request_id: string, binding: string}
     */
    function ssoStartSaml(object $test, string $email = 'maria@'.SSO_DOMAIN): array
    {
        $response = $test->post(route('sso.login.start'), ['email' => $email]);
        $response->assertRedirect();

        $params = ssoAuthorizeParams($response);
        $xml = gzinflate((string) base64_decode($params['SAMLRequest']));
        preg_match('/ID="([^"]+)"/', (string) $xml, $match);
        $cookie = $response->getCookie((string) config('assinavelox.sso.saml.binding_cookie'));

        return ['request_id' => $match[1] ?? '', 'binding' => (string) $cookie?->getValue()];
    }
}

if (! function_exists('ssoPostAcs')) {
    function ssoPostAcs(object $test, SsoConnection $connection, string $xml, ?string $binding): TestResponse
    {
        if ($binding !== null) {
            $test->withCookie((string) config('assinavelox.sso.saml.binding_cookie'), $binding);
        }

        return $test->post(route('sso.saml.acs', ['connection' => $connection->ulid]), ['SAMLResponse' => base64_encode($xml)]);
    }
}
