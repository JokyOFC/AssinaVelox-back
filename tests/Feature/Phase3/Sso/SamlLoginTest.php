<?php

use App\Models\SsoConsumedAssertion;
use App\Models\SsoIdentity;
use App\Models\SsoSamlRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/Support/SsoHelpers.php';

/*
|--------------------------------------------------------------------------
| G-SSO — SAML 2.0 com onelogin/php-saml 4.3.2 (docs/fase-3/sso.md §7)
|--------------------------------------------------------------------------
| IdP SIMULADO: certificado de teste gerado aqui e respostas assinadas com xmlseclibs.
| Negativos obrigatórios: assertion sem assinatura, assinatura de outro certificado,
| assinatura só na Response com a assertion trocada (wrapping), replay, InResponseTo
| desconhecido, Destination errado, SHA-1, IdP-initiated desligado.
*/

beforeEach(function () {
    ['organization' => $this->org, 'owner' => $this->owner] = ssoWorkspace();
    $this->idp = ssoCertificate();
    $this->connection = ssoSamlConnection($this->org, $this->idp['certificate']);
    $this->member = ssoMember($this->org);
    Http::preventStrayRequests();
});

/**
 * Login SP-initiated completo: tela de login → AuthnRequest → resposta assinada no ACS.
 *
 * @param  array<string, mixed>  $options
 */
function samlLogin(object $test, array $options = [], bool $signAssertion = true, bool $signResponse = false, bool $sha1 = false, ?array $idp = null, ?callable $mutate = null, bool $withBinding = true): TestResponse
{
    $start = ssoStartSaml($test);
    $xml = ssoSamlXml($test->connection, ['in_response_to' => $start['request_id'], ...$options]);
    $xml = ssoSamlSign($xml, $idp ?? $test->idp, $signAssertion, $signResponse, $sha1);

    if ($mutate !== null) {
        $xml = $mutate($xml);
    }

    return ssoPostAcs($test, $test->connection, $xml, $withBinding ? $start['binding'] : null);
}

/**
 * @param  string|list<string>  $reason
 */
function samlAssertRefused(object $test, TestResponse $response, string|array $reason): void
{
    $response->assertRedirect(route('login'))->assertSessionHasErrors('email');
    $test->assertGuest();

    $actual = ssoLastAudit($test->org, 'sso.login_failed')['reason'] ?? null;
    expect($actual)->toBeIn((array) $reason);
}

it('entra por SAML SP-initiated com assertion assinada e guarda o ID contra replay', function () {
    $start = ssoStartSaml($this);

    // O ID do pedido só existe como hash no banco; o cookie liga o pedido a este navegador.
    $pending = SsoSamlRequest::query()->sole();
    expect($start['request_id'])->toStartWith('ONELOGIN_')
        ->and($pending->request_id_hash)->toBe(SsoSamlRequest::hashRequestId($start['request_id']))
        ->and($pending->request_id_hash)->not->toContain($start['request_id'])
        ->and($start['binding'])->not->toBe('');

    $xml = ssoSamlSign(ssoSamlXml($this->connection, ['in_response_to' => $start['request_id']]), $this->idp);

    ssoPostAcs($this, $this->connection, $xml, $start['binding'])
        ->assertRedirect(config('fortify.home'));

    $this->assertAuthenticatedAs($this->member);
    expect($pending->fresh()->consumed_at)->not->toBeNull()
        ->and(SsoConsumedAssertion::query()->count())->toBe(1)
        ->and(SsoIdentity::query()->where('user_id', $this->member->id)->exists())->toBeTrue()
        ->and(ssoLastAudit($this->org, 'sso.login_succeeded'))->toMatchArray(['protocol' => 'saml', 'domain' => SSO_DOMAIN]);
});

it('manda o AuthnRequest ao SSO do IdP com o ACS desta conexão', function () {
    $response = $this->post(route('sso.login.start'), ['email' => 'maria@'.SSO_DOMAIN]);
    $location = (string) $response->headers->get('Location');
    $params = ssoAuthorizeParams($response);
    $request = (string) gzinflate((string) base64_decode($params['SAMLRequest']));

    expect($location)->toStartWith(SSO_SAML_IDP_SSO.'?')
        ->and($request)->toContain('Destination="'.SSO_SAML_IDP_SSO.'"')
        ->and($request)->toContain('AssertionConsumerServiceURL="'.route('sso.saml.acs', ['connection' => $this->connection->ulid]).'"')
        ->and($request)->toContain(route('sso.saml.metadata', ['connection' => $this->connection->ulid]));
});

it('publica o metadata do SP exigindo assertion assinada', function () {
    $response = $this->get(route('sso.saml.metadata', ['connection' => $this->connection->ulid]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/samlmetadata+xml')
        ->and($response->getContent())->toContain('entityID="'.route('sso.saml.metadata', ['connection' => $this->connection->ulid]).'"')
        ->and($response->getContent())->toContain('Location="'.route('sso.saml.acs', ['connection' => $this->connection->ulid]).'"')
        ->and($response->getContent())->toContain('WantAssertionsSigned="true"')
        ->and($response->getContent())->not->toContain('PRIVATE KEY');
});

it('recusa assertion sem assinatura', function () {
    samlAssertRefused($this, samlLogin($this, signAssertion: false), 'saml_unsigned_assertion');
});

it('recusa assinatura feita com outro certificado', function () {
    samlAssertRefused($this, samlLogin($this, idp: ssoCertificate('IdP impostor')), 'saml_signature_invalid');
});

it('recusa Response assinada com a assertion sem assinatura', function () {
    samlAssertRefused($this, samlLogin($this, signAssertion: false, signResponse: true), 'saml_unsigned_assertion');
});

it('recusa assinatura só na Response com a assertion trocada (wrapping)', function () {
    $mutate = fn (string $xml): string => str_replace('maria@'.SSO_DOMAIN, 'owner@'.SSO_DOMAIN, $xml);

    samlAssertRefused($this, samlLogin($this, signAssertion: false, signResponse: true, mutate: $mutate), ['saml_unsigned_assertion', 'saml_signature_invalid']);
});

it('recusa assertion assinada com conteúdo alterado depois da assinatura', function () {
    $mutate = fn (string $xml): string => str_replace('>maria@'.SSO_DOMAIN.'<', '>owner@'.SSO_DOMAIN.'<', $xml);

    samlAssertRefused($this, samlLogin($this, mutate: $mutate), 'saml_signature_invalid');
});

it('recusa XML signature wrapping com assertion forjada ao lado da assinada', function () {
    $mutate = function (string $xml): string {
        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $ns = 'urn:oasis:names:tc:SAML:2.0:assertion';
        $signed = $dom->getElementsByTagNameNS($ns, 'Assertion')->item(0);

        // Assertion forjada: cópia sem assinatura, outro ID e outro usuário, ANTES da assinada.
        $evil = $signed->cloneNode(true);
        $evil->setAttribute('ID', '_evil');
        $evil->removeChild($evil->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Signature')->item(0));

        foreach ($evil->getElementsByTagNameNS($ns, 'NameID') as $nameId) {
            $nameId->nodeValue = 'owner@'.SSO_DOMAIN;
        }

        $signed->parentNode->insertBefore($evil, $signed);

        return $dom->saveXML();
    };

    samlAssertRefused($this, samlLogin($this, mutate: $mutate), ['saml_signature_structure', 'saml_invalid', 'saml_signature_invalid']);
    $this->assertGuest();
});

it('recusa XML signature wrapping com a assertion assinada escondida em Extensions', function () {
    $mutate = function (string $xml): string {
        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $ns = 'urn:oasis:names:tc:SAML:2.0:assertion';
        $root = $dom->documentElement;
        $signed = $dom->getElementsByTagNameNS($ns, 'Assertion')->item(0);

        $evil = $signed->cloneNode(true);
        $evil->setAttribute('ID', '_evil');
        $evil->removeChild($evil->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Signature')->item(0));

        $extensions = $dom->createElementNS('urn:oasis:names:tc:SAML:2.0:protocol', 'samlp:Extensions');
        $extensions->appendChild($signed);
        $issuer = $root->getElementsByTagNameNS($ns, 'Issuer')->item(0);
        $root->insertBefore($extensions, $issuer->nextSibling);
        $root->appendChild($evil);

        return $dom->saveXML();
    };

    samlAssertRefused($this, samlLogin($this, mutate: $mutate), ['saml_signature_structure', 'saml_invalid', 'saml_unsigned_assertion', 'saml_signature_invalid']);
});

it('recusa replay da mesma assertion (IdP-initiated, onde não há pedido para consumir)', function () {
    $this->connection->forceFill(['saml_allow_idp_initiated' => true])->save();
    $xml = ssoSamlSign(ssoSamlXml($this->connection, ['in_response_to' => null]), $this->idp);

    ssoPostAcs($this, $this->connection, $xml, null)->assertRedirect(config('fortify.home'));
    $this->assertAuthenticatedAs($this->member);
    auth()->logout();

    samlAssertRefused($this, ssoPostAcs($this, $this->connection, $xml, null), 'saml_replay');
});

it('recusa a mesma resposta SP-initiated duas vezes (pedido de uso único)', function () {
    $start = ssoStartSaml($this);
    $xml = ssoSamlSign(ssoSamlXml($this->connection, ['in_response_to' => $start['request_id']]), $this->idp);

    ssoPostAcs($this, $this->connection, $xml, $start['binding'])->assertRedirect(config('fortify.home'));
    auth()->logout();

    samlAssertRefused($this, ssoPostAcs($this, $this->connection, $xml, $start['binding']), 'saml_request_unknown');
});

it('recusa InResponseTo desconhecido', function () {
    $start = ssoStartSaml($this);
    $xml = ssoSamlSign(ssoSamlXml($this->connection, ['in_response_to' => 'ONELOGIN_pedido_que_nao_existe']), $this->idp);

    samlAssertRefused($this, ssoPostAcs($this, $this->connection, $xml, $start['binding']), 'saml_request_unknown');
});

it('recusa resposta levada a outro navegador (sem o cookie do pedido)', function () {
    samlAssertRefused($this, samlLogin($this, withBinding: false), 'saml_browser_mismatch');
});

it('recusa pedido vencido', function () {
    $start = ssoStartSaml($this);
    SsoSamlRequest::query()->update(['expires_at' => now()->subMinute()]);
    $xml = ssoSamlSign(ssoSamlXml($this->connection, ['in_response_to' => $start['request_id']]), $this->idp);

    samlAssertRefused($this, ssoPostAcs($this, $this->connection, $xml, $start['binding']), 'flow_expired');
});

it('recusa Destination errado', function () {
    samlAssertRefused($this, samlLogin($this, ['destination' => 'https://atacante.example.com/acs']), 'saml_destination');
});

it('recusa Destination que é só prefixo do ACS', function () {
    samlAssertRefused($this, samlLogin($this, ['destination' => rtrim((string) config('app.url'), '/').'/sso']), 'saml_destination');
});

it('recusa assinatura SHA-1', function () {
    samlAssertRefused($this, samlLogin($this, sha1: true), 'saml_weak_algorithm');
});

it('recusa resposta iniciada pelo IdP quando a conexão não permite (padrão)', function () {
    $xml = ssoSamlSign(ssoSamlXml($this->connection, ['in_response_to' => null]), $this->idp);

    samlAssertRefused($this, ssoPostAcs($this, $this->connection, $xml, null), 'saml_idp_initiated_disabled');
});

it('aceita resposta iniciada pelo IdP só com a opção ligada', function () {
    $this->connection->forceFill(['saml_allow_idp_initiated' => true])->save();
    $xml = ssoSamlSign(ssoSamlXml($this->connection, ['in_response_to' => null]), $this->idp);

    ssoPostAcs($this, $this->connection, $xml, null)->assertRedirect(config('fortify.home'));
    $this->assertAuthenticatedAs($this->member);
});

it('recusa Audience errada', function () {
    samlAssertRefused($this, samlLogin($this, ['audience' => 'https://outro-sp.example.com']), 'saml_audience');
});

it('recusa Issuer errado', function () {
    samlAssertRefused($this, samlLogin($this, ['issuer' => 'https://atacante.example.com']), 'saml_issuer');
});

it('recusa Recipient errado', function () {
    samlAssertRefused($this, samlLogin($this, ['recipient' => 'https://atacante.example.com/acs']), 'saml_subject_confirmation');
});

it('recusa assertion vencida (NotOnOrAfter)', function () {
    samlAssertRefused($this, samlLogin($this, ['not_on_or_after' => -600]), ['saml_expired', 'saml_subject_confirmation']);
});

it('recusa e-mail de domínio não verificado', function () {
    samlAssertRefused($this, samlLogin($this, ['email' => 'maria@outro.com.br']), 'domain_not_allowed');
});

it('usa o e-mail como sujeito quando o NameID é transitório', function () {
    samlLogin($this, [
        'name_id' => '_transitorio_'.bin2hex(random_bytes(4)),
        'name_id_format' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:transient',
    ])->assertRedirect(config('fortify.home'));

    $hash = SsoIdentity::hashSubject($this->connection->id, 'email:maria@'.SSO_DOMAIN);
    expect(SsoIdentity::query()->where('subject_hash', $hash)->exists())->toBeTrue();
});

it('recusa resposta sem SAMLResponse ou fora do formato', function () {
    ssoStartSaml($this);

    samlAssertRefused($this, $this->post(route('sso.saml.acs', ['connection' => $this->connection->ulid]), ['SAMLResponse' => base64_encode('<nao-e-saml/>')]), 'saml_invalid');
    samlAssertRefused($this, $this->post(route('sso.saml.acs', ['connection' => $this->connection->ulid]), []), 'saml_invalid');
});

it('recusa XML com DOCTYPE (XXE)', function () {
    $xxe = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol">&x;</samlp:Response>';

    samlAssertRefused($this, $this->post(route('sso.saml.acs', ['connection' => $this->connection->ulid]), ['SAMLResponse' => base64_encode($xxe)]), 'saml_invalid');
});
