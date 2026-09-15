<?php

use App\Enums\MembershipRole;
use App\Models\AuditEvent;
use App\Models\SsoConnection;
use App\Models\SsoDomain;
use App\Models\SsoIdentity;
use App\Services\Sso\SsoSession;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/Support/SsoHelpers.php';

/*
|--------------------------------------------------------------------------
| G-SSO — Configurações › Login único (docs/fase-3/sso.md §3, §4 e §10)
|--------------------------------------------------------------------------
| Cadastro com segredo cifrado, proteção contra SSRF no emissor e no metadata, domínios
| verificados por TXT (um domínio, uma organização), teste de conexão sem login.
*/

beforeEach(function () {
    ['organization' => $this->org, 'owner' => $this->owner] = ssoWorkspace(domain: '');
    Http::preventStrayRequests();
    actingAsMember($this->owner, $this->org);
});

/**
 * @return array<string, mixed>
 */
function ssoOidcPayload(array $overrides = []): array
{
    return [
        'protocol' => 'oidc',
        'name' => 'Login da empresa',
        'oidc_issuer' => SSO_ISSUER,
        'oidc_client_id' => SSO_CLIENT_ID,
        'oidc_client_secret' => SSO_CLIENT_SECRET,
        'oidc_id_token_alg' => 'RS256',
        'jit_provisioning' => false,
        'jit_role' => 'member',
        'two_factor_policy' => 'keep',
        ...$overrides,
    ];
}

it('só owner e admin acessam a tela', function () {
    $member = attachMember($this->org, MembershipRole::Member);
    actingAsMember($member, $this->org);

    $this->get(route('settings.sso'))->assertForbidden();
});

it('mostra o aviso honesto enquanto não há provedor conectado', function () {
    $this->get(route('settings.sso'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/sso')
            ->where('connection', null)
            ->where('homologated', false)
            ->where('enabled', ['oidc' => true, 'saml' => true])
            ->where('can.manage_enforce', true));
});

it('guarda o client secret cifrado e nunca o devolve nem registra', function () {
    $this->post(route('settings.sso.connections.store'), ssoOidcPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $connection = SsoConnection::withoutOrganizationScope()->sole();
    $raw = DB::table('sso_connections')->value('oidc_client_secret');

    expect($connection->status->value)->toBe('draft')
        ->and($raw)->not->toContain(SSO_CLIENT_SECRET)
        ->and(decrypt($raw, false))->toBe(SSO_CLIENT_SECRET)
        ->and($connection->toArray())->not->toHaveKey('oidc_client_secret');

    $page = $this->get(route('settings.sso'));
    $page->assertInertia(fn ($p) => $p->where('connection.oidc.has_client_secret', true)
        ->where('connection.oidc.redirect_uri', route('sso.oidc.callback', ['connection' => $connection->ulid])));
    expect($page->getContent())->not->toContain(SSO_CLIENT_SECRET);

    $audit = AuditEvent::withoutOrganizationScope()->where('organization_id', $this->org->id)->get()->pluck('payload')->toJson();
    expect($audit)->not->toContain(SSO_CLIENT_SECRET);
});

it('bloqueia emissor que resolve para IP interno já no cadastro', function (string $issuer) {
    $this->post(route('settings.sso.connections.store'), ssoOidcPayload(['oidc_issuer' => $issuer]))
        ->assertSessionHasErrors('oidc_issuer');

    expect(SsoConnection::withoutOrganizationScope()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'nome interno' => 'https://interno.example.com',
    'metadados da nuvem' => 'https://metadados.example.com',
    'IP literal' => 'https://10.0.0.5',
    'localhost' => 'https://localhost',
]);

it('nunca aceita owner no JIT', function () {
    $this->post(route('settings.sso.connections.store'), ssoOidcPayload(['jit_role' => 'owner']))
        ->assertSessionHasErrors('jit_role');
});

it('recusa algoritmo simétrico ou none na conexão', function (string $alg) {
    $this->post(route('settings.sso.connections.store'), ssoOidcPayload(['oidc_id_token_alg' => $alg]))
        ->assertSessionHasErrors('oidc_id_token_alg');
})->with(['HS256', 'none']);

it('trocar dado do provedor volta para "em configuração", desliga a obrigatoriedade e exige novo teste', function () {
    $connection = ssoOidcConnection($this->org, ['enforce' => true]);
    // Com o SSO obrigatório, o owner sem 2FA só passa com a sessão aberta pelo SSO.
    session()->put(SsoSession::AUTHENTICATED, [(string) $this->org->id => $connection->id]);

    $this->patch(route('settings.sso.connections.update', $connection->ulid), ['oidc_client_id' => 'outro-cliente', 'oidc_client_secret' => ''])
        ->assertSessionHasNoErrors();

    $connection->refresh();
    expect($connection->status->value)->toBe('draft')
        ->and($connection->enforce)->toBeFalse()
        ->and($connection->last_test_status)->toBeNull()
        ->and($connection->oidc_client_secret)->toBe(SSO_CLIENT_SECRET)
        ->and(ssoLastAudit($this->org, 'sso.connection_updated')['fields'])->toContain('oidc_client_id')
        ->and(ssoLastAudit($this->org, 'sso.connection_updated')['retest_required'])->toBeTrue();
});

it('ativar exige teste bem-sucedido', function () {
    $connection = ssoOidcConnection($this->org, ['status' => 'draft', 'last_test_status' => null, 'last_tested_at' => null]);

    $this->post(route('settings.sso.connections.status', $connection->ulid), ['status' => 'active'])
        ->assertSessionHasErrors('status');
    expect($connection->fresh()->status->value)->toBe('draft');
});

it('testa a conexão OIDC sem entrar nem vincular ninguém', function () {
    ssoVerifiedDomain($this->org);
    $connection = ssoOidcConnection($this->org, ['status' => 'draft', 'last_test_status' => null, 'last_tested_at' => null]);
    $key = ssoRsaKey('k1');
    $test = $this;
    $this->nonce = '';
    ssoFakeOidcProvider([$key['jwk']], fn (HttpRequest $request) => [
        'id_token' => ssoIdToken(ssoClaims($test->nonce, ['email' => 'owner.teste@'.SSO_DOMAIN]), $key['private']),
    ]);

    $params = ssoAuthorizeParams($this->post(route('settings.sso.connections.test', $connection->ulid)));
    $this->nonce = $params['nonce'];

    $this->get(route('sso.oidc.callback', ['connection' => $connection->ulid, 'state' => $params['state'], 'code' => 'c']))
        ->assertRedirect(route('settings.sso'));

    $connection->refresh();
    expect($connection->last_test_status)->toBe('ok')
        ->and($connection->status->value)->toBe('draft')
        ->and(SsoIdentity::query()->count())->toBe(0)
        ->and(ssoLastAudit($this->org, 'sso.connection_tested')['result'])->toBe('ok');
    $this->assertAuthenticatedAs($this->owner);

    // Com o teste aprovado, a ativação passa.
    $this->post(route('settings.sso.connections.status', $connection->ulid), ['status' => 'active'])->assertSessionHasNoErrors();
    expect($connection->fresh()->status->value)->toBe('active');
});

it('registra a falha do teste quando o discovery aponta para endereço interno', function () {
    $connection = ssoOidcConnection($this->org, ['oidc_issuer' => 'https://interno.example.com', 'status' => 'draft']);

    $this->post(route('settings.sso.connections.test', $connection->ulid))->assertSessionHasErrors('test');

    expect($connection->fresh()->last_test_status)->toBe('failed')
        ->and(ssoLastAudit($this->org, 'sso.connection_tested')['reason'])->toBe('url_blocked:blocked_address');
    Http::assertNothingSent();
});

// -- SAML ----------------------------------------------------------------------------------------

it('cadastra conexão SAML com certificado público e recusa chave privada', function () {
    $idp = ssoCertificate();
    $payload = [
        'protocol' => 'saml', 'name' => 'SAML', 'saml_idp_entity_id' => SSO_SAML_IDP_ENTITY,
        'saml_idp_sso_url' => SSO_SAML_IDP_SSO,
    ];

    $this->post(route('settings.sso.connections.store'), [...$payload, 'saml_idp_certificates' => $idp['private']])
        ->assertSessionHasErrors('saml_idp_certificates');

    $this->post(route('settings.sso.connections.store'), [...$payload, 'saml_idp_certificates' => $idp['certificate']])
        ->assertSessionHasNoErrors();

    $connection = SsoConnection::withoutOrganizationScope()->sole();
    expect($connection->idpCertificates())->toHaveCount(1)
        ->and($connection->saml_allow_idp_initiated)->toBeFalse();

    $this->get(route('settings.sso'))->assertInertia(fn ($p) => $p
        ->where('connection.saml.sp_acs_url', route('sso.saml.acs', ['connection' => $connection->ulid]))
        ->has('connection.saml.certificates', 1));
});

it('importa o metadata do IdP colado e recusa buscar metadata em endereço interno', function () {
    $idp = ssoCertificate();
    $connection = ssoSamlConnection($this->org, $idp['certificate'], ['saml_idp_entity_id' => 'antigo', 'saml_idp_sso_url' => 'https://idp.example.com/antigo']);
    $body = trim(str_replace(['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n"], '', $idp['certificate']));
    $xml = '<?xml version="1.0"?><md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="https://idp.example.com/entidade">'
        .'<md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">'
        .'<md:KeyDescriptor use="signing"><ds:KeyInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#"><ds:X509Data><ds:X509Certificate>'.$body.'</ds:X509Certificate></ds:X509Data></ds:KeyInfo></md:KeyDescriptor>'
        .'<md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="https://idp.example.com/sso-novo"/>'
        .'</md:IDPSSODescriptor></md:EntityDescriptor>';

    $this->post(route('settings.sso.connections.metadata', $connection->ulid), ['metadata_xml' => $xml])->assertSessionHasNoErrors();

    $connection->refresh();
    expect($connection->saml_idp_entity_id)->toBe('https://idp.example.com/entidade')
        ->and($connection->saml_idp_sso_url)->toBe('https://idp.example.com/sso-novo')
        ->and($connection->status->value)->toBe('draft');

    $this->post(route('settings.sso.connections.metadata', $connection->ulid), ['metadata_url' => 'https://interno.example.com/metadata'])
        ->assertSessionHasErrors('metadata');
    Http::assertNothingSent();
});

// -- Domínios ------------------------------------------------------------------------------------

it('verifica o domínio pelo registro TXT com o token', function () {
    $this->post(route('settings.sso.domains.store'), ['domain' => 'Empresa.com.br'])->assertSessionHasNoErrors();
    $domain = SsoDomain::withoutOrganizationScope()->sole();

    expect($domain->domain)->toBe('empresa.com.br')->and($domain->isVerified())->toBeFalse();

    ssoFakeTxt([]);
    $this->post(route('settings.sso.domains.verify', $domain->ulid))->assertSessionHasErrors('verify');
    expect($domain->fresh()->isVerified())->toBeFalse();

    ssoFakeTxt(['_assinavelox-sso.empresa.com.br' => ['outro=valor', 'assinavelox-sso='.$domain->verification_token]]);
    $this->post(route('settings.sso.domains.verify', $domain->ulid))->assertSessionHasNoErrors();

    expect($domain->fresh()->isVerified())->toBeTrue()
        ->and(ssoLastAudit($this->org, 'sso.domain_verified')['domain'])->toBe('empresa.com.br');
});

it('um domínio verificado pertence a uma única organização', function () {
    ssoVerifiedDomain($this->org, 'empresa.com.br');

    ['organization' => $other, 'owner' => $otherOwner] = createOrganizationWithOwner();
    ssoEnable($other);
    actingAsMember($otherOwner, $other);

    $this->post(route('settings.sso.domains.store'), ['domain' => 'empresa.com.br'])->assertSessionHasErrors('domain');
});

it('reivindicação pendente de outra organização não consegue verificar um domínio já verificado', function () {
    ['organization' => $other, 'owner' => $otherOwner] = createOrganizationWithOwner();
    ssoEnable($other);
    $claim = SsoDomain::withoutOrganizationScope()->create([
        'organization_id' => $other->id, 'domain' => 'empresa.com.br', 'verification_token' => str_repeat('c', 40),
    ]);
    ssoVerifiedDomain($this->org, 'empresa.com.br');

    ssoFakeTxt(['_assinavelox-sso.empresa.com.br' => ['assinavelox-sso='.str_repeat('c', 40)]]);
    actingAsMember($otherOwner, $other);

    $this->post(route('settings.sso.domains.verify', $claim->ulid))->assertSessionHasErrors('verify');
    expect($claim->fresh()->isVerified())->toBeFalse();
});

it('recusa domínio inválido', function (string $domain) {
    $this->post(route('settings.sso.domains.store'), ['domain' => $domain])->assertSessionHasErrors('domain');
})->with(['localhost', 'empresa', '10.0.0.5', 'a..com']);

it('remove o domínio e a entrada por ele deixa de valer', function () {
    $domain = ssoVerifiedDomain($this->org);

    $this->delete(route('settings.sso.domains.destroy', $domain->ulid))->assertSessionHasNoErrors();
    expect(SsoDomain::withoutOrganizationScope()->count())->toBe(0);
});

// -- Entrada pela tela de login -------------------------------------------------------------------

it('na tela de login, domínio sem conexão ou conexão inativa dá mensagem honesta', function () {
    auth()->logout();

    $this->post(route('sso.login.start'), ['email' => 'maria@semsso.com.br'])
        ->assertSessionHasErrors(['email' => 'Não há login corporativo disponível para este domínio. Entre com e-mail e senha.']);

    ssoVerifiedDomain($this->org);
    ssoOidcConnection($this->org, ['status' => 'draft']);

    $this->post(route('sso.login.start'), ['email' => 'maria@'.SSO_DOMAIN])
        ->assertSessionHasErrors(['email' => 'O login corporativo desta organização ainda não está disponível.']);
});
