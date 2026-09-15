<?php

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\SsoDomain;
use App\Models\SsoIdentity;
use App\Models\User;
use App\Services\Sso\SsoSession;
use Firebase\JWT\JWT;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;

require_once __DIR__.'/Support/SsoHelpers.php';

/*
|--------------------------------------------------------------------------
| G-SSO — OIDC com adaptador próprio (docs/fase-3/sso.md §6)
|--------------------------------------------------------------------------
| IdP SIMULADO: chave RSA/EC gerada no teste, discovery e JWKS por Http::fake, id_token
| assinado com firebase/php-jwt. Casos negativos obrigatórios da onda G: assinatura inválida,
| alg none, RS256→HS256, aud/iss errados, expirado, nonce/state reutilizados ou trocados,
| at_hash errado, email_verified falso, domínio não verificado, discovery em IP interno.
*/

beforeEach(function () {
    ['organization' => $this->org, 'owner' => $this->owner] = ssoWorkspace();
    $this->connection = ssoOidcConnection($this->org);
    $this->member = ssoMember($this->org);
    $this->key = ssoRsaKey('k1');
    $this->jwks = [$this->key['jwk']];
    $this->nonce = '';
    $this->claims = [];
    $this->tokenFactory = null;
    $this->tokenRequests = [];
    $this->discovery = [];

    $test = $this;
    ssoFakeOidcProvider(fn () => $test->jwks, function (HttpRequest $request) use ($test): array {
        parse_str($request->body(), $body);
        $test->tokenRequests[] = ['body' => $body, 'authorization' => $request->header('Authorization')[0] ?? null];

        if ($test->tokenFactory !== null) {
            return ($test->tokenFactory)($test->nonce);
        }

        return [
            'token_type' => 'Bearer',
            'access_token' => 'access-token-1',
            'id_token' => ssoIdToken(ssoClaims($test->nonce, $test->claims), $test->key['private']),
        ];
    }, fn () => $test->discovery);
});

/**
 * Login completo: tela de login → provedor → volta com o `state` e o código.
 *
 * @param  array<string, mixed>  $claims
 */
function oidcLogin(object $test, array $claims = [], ?callable $factory = null, string $email = 'maria@'.SSO_DOMAIN): TestResponse
{
    $params = ssoStartOidc($test, $email);
    $test->nonce = $params['nonce'];
    $test->claims = $claims;
    $test->tokenFactory = $factory;

    return $test->get(route('sso.oidc.callback', ['connection' => $test->connection->ulid, 'state' => $params['state'], 'code' => 'codigo-1']));
}

function oidcAssertRefused(object $test, TestResponse $response, string $reason): void
{
    $response->assertRedirect(route('login'))->assertSessionHasErrors('email');
    $test->assertGuest();
    expect(ssoLastAudit($test->org, 'sso.login_failed')['reason'] ?? null)->toBe($reason);
}

it('entra por OIDC com Authorization Code + PKCE S256, state e nonce, e vincula a identidade', function () {
    $params = ssoStartOidc($this);

    expect($params['response_type'])->toBe('code')
        ->and($params['client_id'])->toBe(SSO_CLIENT_ID)
        ->and($params['code_challenge_method'])->toBe('S256')
        ->and($params['scope'])->toBe('openid email profile')
        ->and($params['redirect_uri'])->toBe(route('sso.oidc.callback', ['connection' => $this->connection->ulid]))
        ->and(strlen($params['state']))->toBeGreaterThanOrEqual(40)
        ->and(strlen($params['nonce']))->toBeGreaterThanOrEqual(40);

    $this->nonce = $params['nonce'];

    $this->get(route('sso.oidc.callback', ['connection' => $this->connection->ulid, 'state' => $params['state'], 'code' => 'codigo-1']))
        ->assertRedirect(config('fortify.home'));

    $this->assertAuthenticatedAs($this->member);

    // PKCE: o verifier enviado ao token endpoint é o que gerou o challenge da ida.
    $sent = $this->tokenRequests[0];
    expect(ssoB64u(hash('sha256', $sent['body']['code_verifier'], true)))->toBe($params['code_challenge'])
        ->and($sent['body']['grant_type'])->toBe('authorization_code')
        ->and($sent['body'])->not->toHaveKey('client_secret')
        ->and($sent['authorization'])->toBe('Basic '.base64_encode(SSO_CLIENT_ID.':'.urlencode(SSO_CLIENT_SECRET)));

    expect(session(SsoSession::AUTHENTICATED))->toBe([(string) $this->org->id => $this->connection->id])
        ->and(SsoIdentity::query()->where('user_id', $this->member->id)->exists())->toBeTrue()
        ->and(Membership::query()->where('user_id', $this->member->id)->value('auth_via'))->toBe('sso');

    $audit = ssoLastAudit($this->org, 'sso.login_succeeded');
    expect($audit)->toMatchArray(['connection' => $this->connection->ulid, 'protocol' => 'oidc', 'domain' => SSO_DOMAIN, 'linked' => true])
        ->and(json_encode($audit))->not->toContain('maria@')
        ->and(json_encode($audit))->not->toContain('access-token');
});

it('aceita id_token ES256 quando a conexão fixa ES256', function () {
    $ec = ssoEcKey('ec1');
    $this->jwks = [$ec['jwk']];
    $this->connection->forceFill(['oidc_id_token_alg' => 'ES256'])->save();

    oidcLogin($this, factory: fn (string $nonce) => ['id_token' => ssoIdToken(ssoClaims($nonce), $ec['private'], 'ES256', 'ec1')])
        ->assertRedirect(config('fortify.home'));

    $this->assertAuthenticatedAs($this->member);
});

it('recusa assinatura inválida (outra chave com o mesmo kid)', function () {
    $other = ssoRsaKey('k1');

    oidcAssertRefused($this, oidcLogin($this, factory: fn (string $nonce) => [
        'id_token' => ssoIdToken(ssoClaims($nonce), $other['private']),
    ]), 'id_token_signature_invalid');
});

it('recusa alg none', function () {
    $factory = function (string $nonce): array {
        $header = ssoB64u(json_encode(['alg' => 'none', 'typ' => 'JWT', 'kid' => 'k1']));
        $payload = ssoB64u(json_encode(ssoClaims($nonce)));

        return ['id_token' => $header.'.'.$payload.'.'];
    };

    oidcAssertRefused($this, oidcLogin($this, factory: $factory), 'id_token_alg_not_allowed');
});

it('recusa a troca RS256 → HS256 com a chave pública como segredo', function () {
    $public = $this->key['public'];

    oidcAssertRefused($this, oidcLogin($this, factory: fn (string $nonce) => [
        'id_token' => JWT::encode(ssoClaims($nonce), $public, 'HS256', 'k1'),
    ]), 'id_token_alg_not_allowed');
});

it('recusa aud errado', function () {
    oidcAssertRefused($this, oidcLogin($this, ['aud' => 'outro-cliente']), 'id_token_audience');
});

it('recusa várias audiências sem azp do cliente', function () {
    oidcAssertRefused($this, oidcLogin($this, ['aud' => [SSO_CLIENT_ID, 'outro'], 'azp' => 'outro']), 'id_token_azp');
});

it('recusa iss errado', function () {
    oidcAssertRefused($this, oidcLogin($this, ['iss' => 'https://atacante.example.com']), 'id_token_issuer');
});

it('recusa token expirado (fora da tolerância)', function () {
    $now = Carbon::now()->getTimestamp();

    oidcAssertRefused($this, oidcLogin($this, ['iat' => $now - 400, 'exp' => $now - 120]), 'id_token_expired');
});

it('recusa token sem exp', function () {
    $factory = function (string $nonce): array {
        $claims = ssoClaims($nonce);
        unset($claims['exp']);

        return ['id_token' => ssoIdToken($claims, $this->key['private'])];
    };

    oidcAssertRefused($this, oidcLogin($this, factory: $factory), 'id_token_expired');
});

it('recusa nonce trocado', function () {
    oidcAssertRefused($this, oidcLogin($this, ['nonce' => 'nonce-de-outro-fluxo']), 'id_token_nonce');
});

it('recusa nonce reutilizado de um login anterior', function () {
    oidcLogin($this)->assertRedirect(config('fortify.home'));
    $oldNonce = $this->nonce;
    auth()->logout();

    // Novo fluxo, mas o provedor (ou quem capturou o token) devolve o id_token com o nonce antigo.
    oidcAssertRefused($this, oidcLogin($this, factory: fn () => [
        'id_token' => ssoIdToken(ssoClaims($oldNonce), $this->key['private']),
    ]), 'id_token_nonce');
});

it('recusa state reutilizado (uso único)', function () {
    $params = ssoStartOidc($this);
    $this->nonce = $params['nonce'];
    $url = route('sso.oidc.callback', ['connection' => $this->connection->ulid, 'state' => $params['state'], 'code' => 'codigo-1']);

    $this->get($url)->assertRedirect(config('fortify.home'));
    auth()->logout();

    oidcAssertRefused($this, $this->get($url), 'state_invalid');
    expect($this->tokenRequests)->toHaveCount(1);
});

it('recusa state trocado ou de outra sessão', function () {
    ssoStartOidc($this);

    oidcAssertRefused($this, $this->get(route('sso.oidc.callback', [
        'connection' => $this->connection->ulid,
        'state' => 'state-que-esta-sessao-nunca-emitiu',
        'code' => 'codigo-1',
    ])), 'state_invalid');

    expect($this->tokenRequests)->toBeEmpty();
});

it('recusa state emitido para outra conexão', function () {
    $params = ssoStartOidc($this);

    ['organization' => $otherOrg] = createOrganizationWithOwner();
    ssoEnable($otherOrg);
    $other = ssoOidcConnection($otherOrg);

    $this->get(route('sso.oidc.callback', ['connection' => $other->ulid, 'state' => $params['state'], 'code' => 'x']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(ssoLastAudit($otherOrg, 'sso.login_failed')['reason'])->toBe('state_invalid')
        ->and($this->tokenRequests)->toBeEmpty();
});

it('recusa fluxo vencido', function () {
    $params = ssoStartOidc($this);
    $this->travel(11)->minutes();

    oidcAssertRefused($this, $this->get(route('sso.oidc.callback', ['connection' => $this->connection->ulid, 'state' => $params['state'], 'code' => 'x'])), 'flow_expired');
});

it('recusa at_hash errado', function () {
    oidcAssertRefused($this, oidcLogin($this, ['at_hash' => 'hash-que-nao-bate']), 'id_token_at_hash');
});

it('aceita at_hash correto', function () {
    oidcLogin($this, factory: fn (string $nonce) => [
        'access_token' => 'access-token-1',
        'id_token' => ssoIdToken(ssoClaims($nonce, ['at_hash' => ssoB64u(substr(hash('sha256', 'access-token-1', true), 0, 16))]), $this->key['private']),
    ])->assertRedirect(config('fortify.home'));

    $this->assertAuthenticatedAs($this->member);
});

it('recusa email_verified falso ou ausente', function (mixed $value) {
    $claims = $value === 'ausente' ? [] : ['email_verified' => $value];
    $factory = function (string $nonce) use ($claims, $value): array {
        $all = ssoClaims($nonce, $claims);

        if ($value === 'ausente') {
            unset($all['email_verified']);
        }

        return ['id_token' => ssoIdToken($all, $this->key['private'])];
    };

    oidcAssertRefused($this, oidcLogin($this, factory: $factory), 'email_not_verified');
})->with([false, 'false', 'ausente']);

it('recusa e-mail de domínio não verificado da organização', function () {
    // Domínio só adicionado (pendente) não vale.
    SsoDomain::withoutOrganizationScope()->create([
        'organization_id' => $this->org->id, 'domain' => 'filial.com.br', 'verification_token' => str_repeat('b', 40),
    ]);

    oidcAssertRefused($this, oidcLogin($this, ['email' => 'joao@filial.com.br']), 'domain_not_allowed');
});

it('acompanha a rotação de chaves: kid novo recarrega o JWKS uma vez', function () {
    oidcLogin($this)->assertRedirect(config('fortify.home'));
    auth()->logout();

    $rotated = ssoRsaKey('k2');
    $this->jwks = [$this->key['jwk'], $rotated['jwk']];

    oidcLogin($this, factory: fn (string $nonce) => ['id_token' => ssoIdToken(ssoClaims($nonce), $rotated['private'], 'RS256', 'k2')])
        ->assertRedirect(config('fortify.home'));

    $this->assertAuthenticatedAs($this->member);
    Http::assertSentCount(5); // discovery + jwks + token, depois token + jwks recarregado
});

it('recusa kid desconhecido mesmo depois da recarga', function () {
    $stranger = ssoRsaKey('kx');

    oidcAssertRefused($this, oidcLogin($this, factory: fn (string $nonce) => [
        'id_token' => ssoIdToken(ssoClaims($nonce), $stranger['private'], 'RS256', 'kx'),
    ]), 'jwks_kid_unknown');
});

it('bloqueia discovery que aponta para IP interno — nenhuma chamada sai', function () {
    $this->connection->forceFill(['oidc_issuer' => 'https://interno.example.com'])->save();

    $this->post(route('sso.login.start'), ['email' => 'maria@'.SSO_DOMAIN])
        ->assertSessionHasErrors('email');

    Http::assertNothingSent();
    expect(ssoLastAudit($this->org, 'sso.login_failed')['reason'])->toBe('url_blocked:blocked_address');
});

it('bloqueia JWKS e token endpoint do discovery que apontam para endereço interno', function (string $field) {
    $this->discovery = [$field => 'https://metadados.example.com/x'];

    $this->post(route('sso.login.start'), ['email' => 'maria@'.SSO_DOMAIN])->assertSessionHasErrors('email');

    expect(ssoLastAudit($this->org, 'sso.login_failed')['reason'])->toBe('url_blocked:blocked_address');
})->with(['jwks_uri', 'token_endpoint']);

it('recusa discovery com issuer diferente do configurado', function () {
    $this->discovery = ['issuer' => 'https://outro.example.com'];

    $this->post(route('sso.login.start'), ['email' => 'maria@'.SSO_DOMAIN])->assertSessionHasErrors('email');
    expect(ssoLastAudit($this->org, 'sso.login_failed')['reason'])->toBe('discovery_issuer_mismatch');
});

it('recusa o erro devolvido pelo provedor sem trocar código nenhum', function () {
    $params = ssoStartOidc($this);

    oidcAssertRefused($this, $this->get(route('sso.oidc.callback', [
        'connection' => $this->connection->ulid, 'state' => $params['state'], 'error' => 'access_denied', 'error_description' => '<script>x</script>',
    ])), 'provider_error:access_denied');

    expect($this->tokenRequests)->toBeEmpty();
});

// -- Vínculo e JIT ------------------------------------------------------------------------------

it('provisiona por JIT como operador, com e-mail confirmado, sem tocar outras organizações', function () {
    $this->connection->forceFill(['jit_provisioning' => true])->save();

    oidcLogin($this, ['sub' => 'novo-1', 'email' => 'novo@'.SSO_DOMAIN, 'name' => 'Pessoa Nova'], email: 'novo@'.SSO_DOMAIN)
        ->assertRedirect(config('fortify.home'));

    $user = User::query()->where('email', 'novo@'.SSO_DOMAIN)->sole();
    $membership = Membership::query()->where('user_id', $user->id)->sole();

    $this->assertAuthenticatedAs($user);
    expect($membership->role)->toBe(MembershipRole::Member)
        ->and($membership->organization_id)->toBe($this->org->id)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(ssoLastAudit($this->org, 'sso.user_provisioned'))->toMatchArray(['role' => 'member', 'new_account' => true]);
});

it('provisiona por JIT no máximo como administrador — nunca owner', function () {
    $this->connection->forceFill(['jit_provisioning' => true, 'jit_role' => 'owner'])->save();

    oidcLogin($this, ['sub' => 'novo-2', 'email' => 'novo2@'.SSO_DOMAIN], email: 'novo2@'.SSO_DOMAIN);

    $user = User::query()->where('email', 'novo2@'.SSO_DOMAIN)->sole();
    expect(Membership::query()->where('user_id', $user->id)->value('role'))->toBe(MembershipRole::Member);

    auth()->logout();
    $this->connection->forceFill(['jit_role' => 'admin'])->save();
    oidcLogin($this, ['sub' => 'novo-3', 'email' => 'novo3@'.SSO_DOMAIN], email: 'novo3@'.SSO_DOMAIN);

    $admin = User::query()->where('email', 'novo3@'.SSO_DOMAIN)->sole();
    expect(Membership::query()->where('user_id', $admin->id)->value('role'))->toBe(MembershipRole::Admin);
});

it('sem JIT, não cria conta nem membership', function () {
    oidcAssertRefused($this, oidcLogin($this, ['sub' => 'x', 'email' => 'ninguem@'.SSO_DOMAIN], email: 'ninguem@'.SSO_DOMAIN), 'no_account');
    expect(User::query()->where('email', 'ninguem@'.SSO_DOMAIN)->exists())->toBeFalse();

    $outsider = User::factory()->create(['email' => 'externo@'.SSO_DOMAIN]);
    oidcAssertRefused($this, oidcLogin($this, ['sub' => 'y', 'email' => $outsider->email], email: $outsider->email), 'not_a_member');
    expect(Membership::query()->where('user_id', $outsider->id)->exists())->toBeFalse();
});

it('JIT de usuário existente de outra organização cria só a membership nova, sem mexer no papel de dono dele', function () {
    ['organization' => $theirs, 'owner' => $owner] = createOrganizationWithOwner();
    $owner->forceFill(['email' => 'dono@'.SSO_DOMAIN])->save();
    $this->connection->forceFill(['jit_provisioning' => true])->save();

    oidcLogin($this, ['sub' => 'dono', 'email' => 'dono@'.SSO_DOMAIN], email: 'dono@'.SSO_DOMAIN)->assertRedirect(config('fortify.home'));

    expect(Membership::query()->where('user_id', $owner->id)->where('organization_id', $theirs->id)->value('role'))->toBe(MembershipRole::Owner)
        ->and(Membership::query()->where('user_id', $owner->id)->where('organization_id', $this->org->id)->value('role'))->toBe(MembershipRole::Member);
});

it('recusa vincular conta local ainda não confirmada', function () {
    $this->member->forceFill(['email_verified_at' => null])->save();

    oidcAssertRefused($this, oidcLogin($this), 'local_account_unverified');
});

it('recusa e-mail reaproveitado no IdP com outro sujeito', function () {
    oidcLogin($this)->assertRedirect(config('fortify.home'));
    auth()->logout();

    oidcAssertRefused($this, oidcLogin($this, ['sub' => 'outro-sujeito']), 'identity_conflict');
});

it('entra pelo sujeito vinculado mesmo se o e-mail mudou dentro do domínio', function () {
    oidcLogin($this)->assertRedirect(config('fortify.home'));
    auth()->logout();

    oidcLogin($this, ['email' => 'maria.souza@'.SSO_DOMAIN])->assertRedirect(config('fortify.home'));
    $this->assertAuthenticatedAs($this->member);
});

it('recusa membership suspensa e conta bloqueada', function () {
    Membership::query()->where('user_id', $this->member->id)->update(['status' => MembershipStatus::Suspended->value]);
    oidcAssertRefused($this, oidcLogin($this), 'membership_suspended');

    Membership::query()->where('user_id', $this->member->id)->update(['status' => MembershipStatus::Active->value]);
    $this->member->forceFill(['blocked_at' => now()])->save();
    oidcAssertRefused($this, oidcLogin($this), 'account_blocked');
});

it('recusa conexão desativada', function () {
    $params = ssoStartOidc($this);
    $this->connection->forceFill(['status' => 'disabled'])->save();

    oidcAssertRefused($this, $this->get(route('sso.oidc.callback', ['connection' => $this->connection->ulid, 'state' => $params['state'], 'code' => 'x'])), 'connection_unavailable');
});

// -- 2FA -----------------------------------------------------------------------------------------

it('mantém o 2FA do usuário por padrão: termina no desafio do Fortify e só então vale como SSO', function () {
    $google = new Google2FA;
    $secret = $google->generateSecretKey();
    $this->member->forceFill(['two_factor_secret' => encrypt($secret), 'two_factor_confirmed_at' => now()])->save();

    oidcLogin($this)->assertRedirect(route('two-factor.login'));
    $this->assertGuest();

    $this->post(route('two-factor.login.store'), ['code' => $google->getCurrentOtp($secret)])
        ->assertRedirect();

    $this->assertAuthenticatedAs($this->member);
    expect(session(SsoSession::AUTHENTICATED))->toBe([(string) $this->org->id => $this->connection->id])
        ->and(ssoLastAudit($this->org, 'sso.login_succeeded')['two_factor'])->toBe('verified');
});

it('dispensa o 2FA só quando a organização decidiu confiar no IdP (e registra)', function () {
    $this->member->forceFill(['two_factor_secret' => encrypt('SEGREDO'), 'two_factor_confirmed_at' => now()])->save();
    $this->connection->forceFill(['two_factor_policy' => 'trust_idp'])->save();

    oidcLogin($this)->assertRedirect(config('fortify.home'));

    $this->assertAuthenticatedAs($this->member);
    expect(ssoLastAudit($this->org, 'sso.login_succeeded')['two_factor'])->toBe('trusted_idp');
});

it('um login por senha descarta o SSO pendente de 2FA', function () {
    $this->member->forceFill(['two_factor_secret' => encrypt('SEGREDO'), 'two_factor_confirmed_at' => now()])->save();

    oidcLogin($this)->assertRedirect(route('two-factor.login'));
    expect(session(SsoSession::PENDING_TWO_FACTOR))->toBeArray();

    event(new Attempting('web', ['email' => $this->member->email], false));

    expect(session(SsoSession::PENDING_TWO_FACTOR))->toBeNull();
});

it('na tela de login não expõe detalhe de configuração do provedor (só na trilha)', function () {
    $this->connection->forceFill(['oidc_issuer' => 'https://interno.example.com'])->save();

    $this->post(route('sso.login.start'), ['email' => 'maria@'.SSO_DOMAIN])
        ->assertSessionHasErrors(['email' => 'Não foi possível entrar pelo login corporativo. Comece de novo ou fale com o administrador da sua empresa.']);

    expect(ssoLastAudit($this->org, 'sso.login_failed')['reason'])->toBe('url_blocked:blocked_address');
});
