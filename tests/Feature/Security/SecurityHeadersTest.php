<?php

use App\Models\User;
use Illuminate\Support\Facades\Vite;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

beforeEach(fn () => $this->withoutVite());

test('todas as respostas trazem os cabeçalhos de segurança básicos', function () {
    $response = $this->get(route('login'));

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    expect($response->headers->has('X-Robots-Tag'))->toBeFalse();
});

test('a CSP usa nonce e só permite unsafe-inline em style-src', function () {
    $response = $this->get(route('login'));

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)->not->toBeNull()
        ->and($csp)->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9]{40}'/")
        ->and($csp)->toContain("style-src 'self' 'unsafe-inline'")
        ->and($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->toContain("base-uri 'self'");

    preg_match("/'nonce-([A-Za-z0-9]{40})'/", $csp, $matches);
    expect(Vite::cspNonce())->toBe($matches[1]);
    expect(substr_count($csp, 'unsafe-inline'))->toBe(1);
});

test('páginas públicas do signatário e de verificação não enviam referrer e pedem noindex', function () {
    $this->get(route('verify.index'))
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

    // Token que não corresponde a convite nenhum: 404 genérico (docs/fluxo-do-signatario.md
    // §3) — o que importa aqui é que os cabeçalhos valem para /assinar/* inclusive no erro,
    // que é justamente a resposta que um buscador ou um proxy encontraria.
    $this->get(route('sign.show', ['token' => str_repeat('a', 43)]))
        ->assertNotFound()
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
});

test('a CSP pode ser desativada ou emitida em modo report-only pela configuração', function () {
    config(['assinavelox.security_headers.csp_enabled' => false]);
    $response = $this->get(route('login'));
    expect($response->headers->has('Content-Security-Policy'))->toBeFalse();

    config(['assinavelox.security_headers.csp_enabled' => true, 'assinavelox.security_headers.csp_report_only' => true]);
    $response = $this->get(route('login'));
    expect($response->headers->has('Content-Security-Policy-Report-Only'))->toBeTrue()
        ->and($response->headers->has('Content-Security-Policy'))->toBeFalse();
});

test('rotas públicas respondem e o webhook do Mercado Pago aceita POST sem CSRF', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
    $this->get(route('legal.terms'))->assertOk();
    $this->get(route('legal.privacy'))->assertOk();
    $this->get(route('verify.show', ['code' => 'ABCDEFGHJKLM']))->assertOk();

    // O webhook está fora do CSRF (bootstrap/app.php): uma requisição sem token chega ao
    // controller. Desde o incremento 5 ele exige assinatura válida, então a resposta
    // esperada é 401 — e NÃO 419, que é o que uma barreira de CSRF devolveria.
    $this->postJson(route('webhooks.mercadopago'), ['type' => 'payment', 'data' => ['id' => '123']])
        ->assertStatus(401)
        ->assertExactJson(['error' => 'invalid_signature']);

    // Com assinatura correta, a mesma requisição sem CSRF passa.
    $secret = 'segredo-do-webhook-no-teste-de-seguranca';
    config()->set('assinavelox.mercadopago.webhook_secret', $secret);

    $requestId = 'req-seguranca';
    $ts = (int) round(microtime(true) * 1000);
    $signature = 'ts='.$ts.',v1='.hash_hmac('sha256', "id:123;request-id:{$requestId};ts:{$ts};", $secret);

    $this->postJson(
        route('webhooks.mercadopago').'?data.id=123&type=payment',
        ['type' => 'payment', 'action' => 'payment.updated', 'data' => ['id' => '123']],
        ['x-signature' => $signature, 'x-request-id' => $requestId],
    )->assertOk()->assertJson(['received' => true]);
});

test('o painel interno exige platform admin e as páginas placeholder respondem', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    actingAsMember($owner, $organization);

    foreach (['admin.billing.index', 'admin.users.index', 'admin.audit.index', 'admin.settings.index'] as $route) {
        $this->get(route($route))->assertForbidden();
    }

    $admin = User::factory()->platformAdmin()->create();

    foreach (['admin.billing.index', 'admin.users.index', 'admin.audit.index', 'admin.settings.index'] as $route) {
        $this->actingAs($admin)->get(route($route))->assertOk();
    }
});
