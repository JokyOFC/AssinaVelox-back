<?php

use App\Models\Envelope;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| H-SEC §1 — cobertura dos cabeçalhos de segurança
|--------------------------------------------------------------------------
| O middleware SecurityHeaders é registrado com `append` GLOBAL (bootstrap/app.php), e não
| dentro do grupo `web`. Estes testes cobram o que essa escolha promete: os cabeçalhos
| aparecem em TODA resposta — página autenticada, página pública, JSON do webhook, resposta
| de erro e download — e não apenas nas telas felizes.
*/

beforeEach(fn () => $this->withoutVite());

/** Cabeçalhos que valem para qualquer resposta, sem exceção. */
function assertBaselineSecurityHeaders(TestResponse $response): void
{
    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Permitted-Cross-Domain-Policies', 'none')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin-allow-popups')
        ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');

    $permissions = (string) $response->headers->get('Permissions-Policy');

    expect($permissions)->toContain('camera=()')
        ->and($permissions)->toContain('microphone=()')
        ->and($permissions)->toContain('geolocation=()')
        ->and($permissions)->toContain('payment=()')
        ->and($permissions)->toContain('usb=()')
        ->and($permissions)->toContain('display-capture=()');
}

test('a linha de base de cabeçalhos vale para toda rota pública, autenticada e de erro', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    // Pública (a entrada `/` só redireciona para o login — o redirecionamento também leva a linha de base).
    assertBaselineSecurityHeaders($this->get(route('home'))->assertRedirect(route('login')));
    assertBaselineSecurityHeaders($this->get(route('verify.index'))->assertOk());
    assertBaselineSecurityHeaders($this->get(route('login'))->assertOk());

    // Autenticada.
    actingAsMember($owner, $organization);
    assertBaselineSecurityHeaders($this->get(route('dashboard'))->assertOk());

    // Erro: 404 de rota inexistente e 403 do painel interno.
    assertBaselineSecurityHeaders($this->get('/rota-que-nao-existe-'.uniqid())->assertNotFound());
    assertBaselineSecurityHeaders($this->get(route('admin.audit.index'))->assertForbidden());
});

test('o webhook responde JSON com os mesmos cabeçalhos, mesmo recusando a assinatura', function () {
    $response = $this->postJson(route('webhooks.mercadopago'), ['type' => 'payment', 'data' => ['id' => '1']])
        ->assertStatus(401);

    assertBaselineSecurityHeaders($response);
});

test('a página do signatário e a de verificação não vazam referrer nem são indexadas', function () {
    foreach ([route('verify.index'), '/assinar/'.str_repeat('a', 43)] as $url) {
        $response = $this->get($url);

        assertBaselineSecurityHeaders($response);
        $response->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    // Fora dessas áreas a política é a padrão, e o noindex NÃO é emitido.
    $this->get(route('login'))
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    expect($this->get(route('login'))->headers->has('X-Robots-Tag'))->toBeFalse();
});

test('a resposta 500 fora de debug traz os cabeçalhos e não revela a mensagem da exceção', function () {
    // Rota de teste que explode com uma mensagem que jamais pode chegar ao navegador.
    Route::middleware('web')->get('/hardening/boom', function (): never {
        throw new RuntimeException('SEGREDO-INTERNO: senha do PKCS#12 do cliente 42');
    });

    // Sem isto o Ignition monta a página de diagnóstico, que mostra a exceção inteira —
    // comportamento correto em desenvolvimento e proibido em produção.
    config(['app.debug' => false]);

    $response = $this->get('/hardening/boom');

    assertBaselineSecurityHeaders($response);

    expect($response->getStatusCode())->toBe(500)
        ->and($response->getContent())->not->toContain('SEGREDO-INTERNO')
        ->and($response->getContent())->not->toContain('PKCS#12');
});

test('a CSP não admite unsafe-inline em script-src e libera o worker do PDF.js', function () {
    $csp = (string) $this->get(route('login'))->headers->get('Content-Security-Policy');

    expect($csp)->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9]{40}'/")
        ->and($csp)->not->toContain("script-src 'self' 'unsafe-inline'")
        // O PDF.js instancia o worker a partir de um Blob: sem `blob:` em worker-src o
        // visualizador de documentos simplesmente não abre.
        ->and($csp)->toContain("worker-src 'self' blob:")
        // O canvas do PDF.js e as imagens de assinatura são data:/blob:.
        ->and($csp)->toContain('img-src')
        ->and($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->toContain("base-uri 'self'")
        ->and($csp)->toContain("form-action 'self'");

    // 'unsafe-inline' aparece uma única vez, e é em style-src (Tailwind/Radix).
    expect(substr_count($csp, 'unsafe-inline'))->toBe(1);
    expect($csp)->toContain("style-src 'self' 'unsafe-inline'");
});

test('o nonce da CSP é o mesmo que o Inertia/Vite usa na página, e muda a cada resposta', function () {
    $first = $this->get(route('login'));
    $csp = (string) $first->headers->get('Content-Security-Policy');

    preg_match("/'nonce-([A-Za-z0-9]{40})'/", $csp, $matches);

    expect($matches[1] ?? null)->not->toBeNull()
        ->and(Vite::cspNonce())->toBe($matches[1]);

    // Nonce reutilizado entre respostas seria nonce nenhum.
    $second = $this->get(route('login'));
    preg_match("/'nonce-([A-Za-z0-9]{40})'/", (string) $second->headers->get('Content-Security-Policy'), $other);

    expect($other[1] ?? null)->not->toBe($matches[1]);
});

test('os cabeçalhos configuráveis podem ser desligados sem tocar no código', function () {
    config([
        'assinavelox.security_headers.coop' => '',
        'assinavelox.security_headers.corp' => '',
        'assinavelox.security_headers.permissions_policy' => '',
    ]);

    $response = $this->get(route('login'))->assertOk();

    expect($response->headers->has('Cross-Origin-Opener-Policy'))->toBeFalse()
        ->and($response->headers->has('Cross-Origin-Resource-Policy'))->toBeFalse()
        ->and($response->headers->has('Permissions-Policy'))->toBeFalse()
        // O que não é configurável continua valendo.
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY');
});

test('o identificador de correlação volta no cabeçalho e é diferente a cada requisição', function () {
    $first = (string) $this->get(route('home'))->headers->get('X-Correlation-Id');
    $second = (string) $this->get(route('home'))->headers->get('X-Correlation-Id');

    expect($first)->toMatch('/^[0-9A-Z]{26}$/')
        ->and($second)->not->toBe($first);
});

test('um X-Correlation-Id malformado vindo do cliente é descartado, não ecoado', function () {
    $injected = "linha-1\nlevel=CRITICAL msg=\"fingido\"";

    $echoed = (string) $this->withHeaders(['X-Correlation-Id' => $injected])
        ->get(route('home'))
        ->headers->get('X-Correlation-Id');

    expect($echoed)->not->toBe($injected)
        ->and($echoed)->toMatch('/^[0-9A-Z]{26}$/');

    // Um identificador bem formado, por outro lado, é aproveitado (rastro do balanceador).
    $valid = 'edge-01HX9Q2Z8K';
    expect((string) $this->withHeaders(['X-Correlation-Id' => $valid])->get(route('home'))->headers->get('X-Correlation-Id'))
        ->toBe($valid);
});

test('os cabeçalhos acompanham downloads e streams de documento', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    actingAsMember($owner, $organization);

    $envelope = Envelope::factory()->forOrganization($organization, $owner)->create();

    /*
     * O que importa aqui não é o status — depende do estado do envelope — mas o fato de a
     * resposta passar pelo middleware. Download é justamente a resposta que mais escapa da
     * revisão: não é página, não passa pelo Inertia e sai por StreamedResponse.
     */
    foreach ([
        route('envelopes.download', ['envelope' => $envelope->ulid, 'type' => 'original']),
        route('envelopes.document.preview', ['envelope' => $envelope->ulid]),
        route('envelopes.evidence', ['envelope' => $envelope->ulid]),
    ] as $url) {
        assertBaselineSecurityHeaders($this->get($url));
    }
});

test('a CSP não quebra o Inertia: o script da página carrega o nonce do cabeçalho', function () {
    // Sem o nonce no <script> da página, o navegador recusaria o payload do Inertia e a
    // aplicação abriria em branco — a falha clássica de ligar CSP sem testar.
    $response = $this->get(route('login'))->assertOk();

    preg_match("/'nonce-([A-Za-z0-9]{40})'/", (string) $response->headers->get('Content-Security-Policy'), $matches);

    expect($matches[1] ?? null)->not->toBeNull()
        ->and($response->getContent())->toContain('nonce="'.$matches[1].'"');
});

test('o painel interno continua exigindo platform admin com os cabeçalhos aplicados', function () {
    $admin = User::factory()->platformAdmin()->create();

    assertBaselineSecurityHeaders($this->actingAs($admin)->get(route('admin.audit.index'))->assertOk());
});
