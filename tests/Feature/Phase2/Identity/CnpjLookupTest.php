<?php

use App\Enums\MembershipRole;
use App\Integrations\Cnpj\CnpjLookupRecord;
use App\Integrations\Cnpj\FakeCnpjLookup;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/IdentityHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 2 §2.11 (C-ID) — autopreenchimento por CNPJ (Minha Receita, sem SLA)
|--------------------------------------------------------------------------
| Cache em `cnpj_lookups`, timeout = indisponível (nunca sucesso), resposta malformada
| rejeitada, limite por usuário e formulário manual que nunca bloqueia. Nenhum teste toca a
| rede: Http::fake + preventStrayRequests.
*/

const CNPJ_SERPRO = '33683111000280';

beforeEach(function () {
    $this->withoutVite();
    config()->set('inertia.ssr.enabled', false);
    Http::preventStrayRequests();
    config()->set('assinavelox.cnpj.driver', 'minha_receita');
    config()->set('assinavelox.cnpj.base_url', 'https://minhareceita.org');

    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    identityEnableFlags($this->organization, ['cnpj_lookup']);
    actingAsMember($this->owner, $this->organization);
});

/**
 * @return array<string, mixed>
 */
function minhaReceitaPayload(array $overrides = []): array
{
    return array_merge([
        'cnpj' => CNPJ_SERPRO,
        'razao_social' => "SERVICO FEDERAL DE PROCESSAMENTO DE DADOS (SERPRO)\u{0007}",
        'nome_fantasia' => 'SERPRO REGIONAL',
        'descricao_situacao_cadastral' => 'ATIVA',
        'logradouro' => 'SGAN 601',
        'numero' => 'S/N',
        'bairro' => 'ASA NORTE',
        'municipio' => 'BRASILIA',
        'uf' => 'DF',
        'cep' => '70836-900',
        'cnae_fiscal' => 6204000,
        'cnae_fiscal_descricao' => 'Consultoria em tecnologia da informação',
        // O que NUNCA pode ser guardado nem devolvido: sócios, e-mail e telefones.
        'qsa' => [['nome_socio' => 'FULANO DE TAL', 'cnpj_cpf_do_socio' => '***123456**']],
        'email' => 'contato@exemplo.test',
        'ddd_telefone_1' => '6121234567',
    ], $overrides);
}

function cnpjLookup(object $test, string $cnpj = '33.683.111/0002-80'): mixed
{
    return $test->postJson(route('settings.organization.cnpj'), ['cnpj' => $cnpj]);
}

it('preenche razão social e nome fantasia, minimiza o payload e usa o cache na segunda consulta', function () {
    Http::fake(['https://minhareceita.org/*' => Http::response(minhaReceitaPayload())]);

    $first = cnpjLookup($this)->assertOk();

    expect($first->json('status'))->toBe('found')
        ->and($first->json('cnpj'))->toBe('33.683.111/0002-80')
        ->and($first->json('suggestions.legal_name'))->toBe('SERVICO FEDERAL DE PROCESSAMENTO DE DADOS (SERPRO)')
        ->and($first->json('suggestions.name'))->toBe('SERPRO REGIONAL')
        ->and($first->json('data.address.postal_code'))->toBe('70836900')
        ->and($first->json('source.provider'))->toBe('minha_receita')
        ->and($first->json('source.simulated'))->toBeFalse()
        ->and($first->json('source.cached'))->toBeFalse()
        ->and($first->json('source.attribution'))->toContain('Receita Federal')
        ->and($first->json('manual_fill'))->toBeTrue();

    $second = cnpjLookup($this, CNPJ_SERPRO)->assertOk();

    expect($second->json('status'))->toBe('found')->and($second->json('source.cached'))->toBeTrue();

    Http::assertSentCount(1);
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://minhareceita.org/'.CNPJ_SERPRO
        && $request->hasHeader('X-Correlation-Id'));

    $record = CnpjLookupRecord::query()->where('cnpj', CNPJ_SERPRO)->firstOrFail();
    $stored = json_encode($record->payload);
    $served = (string) $first->getContent();

    foreach ([$stored, $served] as $haystack) {
        expect($haystack)->not->toContain('FULANO')
            ->and($haystack)->not->toContain('contato@exemplo.test')
            ->and($haystack)->not->toContain('6121234567');
    }

    expect($record->status)->toBe('found')
        ->and($record->expires_at->greaterThan(now()->addDays(29)))->toBeTrue();
});

it('404 vira "não encontrado" com cache curto; o formulário segue manual', function () {
    Http::fake(['https://minhareceita.org/*' => Http::response(['message' => 'não encontrado'], 404)]);

    expect(cnpjLookup($this)->assertOk()->json('status'))->toBe('not_found');
    expect(cnpjLookup($this)->assertOk()->json('source.cached'))->toBeTrue();

    Http::assertSentCount(1);

    $record = CnpjLookupRecord::query()->firstOrFail();

    expect($record->status)->toBe('not_found')
        ->and($record->payload)->toBeNull()
        ->and($record->expires_at->lessThanOrEqualTo(now()->addHours(24)))->toBeTrue();
});

it('tempo esgotado é tratado como indisponível, não é cacheado e não bloqueia salvar o formulário', function () {
    Http::fake(['https://minhareceita.org/*' => Http::failedConnection()]);

    $response = cnpjLookup($this)->assertOk();

    expect($response->json('status'))->toBe('unavailable')
        ->and($response->json('data'))->toBeNull()
        ->and($response->json('manual_fill'))->toBeTrue()
        ->and($response->json('message'))->toContain('manualmente')
        ->and($response->json('source.reason'))->toBe('timeout')
        ->and(CnpjLookupRecord::query()->count())->toBe(0);

    // O formulário de Configurações › Geral salva o CNPJ digitado à mão normalmente.
    $this->patch(route('settings.organization.update'), [
        'name' => 'Serpro Regional',
        'legal_name' => 'Razão social digitada à mão',
        'tax_id' => '33.683.111/0002-80',
    ])->assertSessionHasNoErrors();

    expect($this->organization->fresh()->legal_name)->toBe('Razão social digitada à mão');
});

it('5xx e 429 da fonte viram indisponível, sem cache', function (int $status) {
    Http::fake(['https://minhareceita.org/*' => Http::response('erro', $status)]);

    expect(cnpjLookup($this)->assertOk()->json('status'))->toBe('unavailable')
        ->and(CnpjLookupRecord::query()->count())->toBe(0);
})->with([500, 502, 503, 429]);

it('resposta malformada é rejeitada (HTML, JSON sem razão social, CNPJ trocado, corpo gigante)', function (Closure $body) {
    Http::fake(['https://minhareceita.org/*' => $body()]);

    $response = cnpjLookup($this)->assertOk();

    expect($response->json('status'))->toBe('unavailable')
        ->and($response->json('data'))->toBeNull()
        ->and(CnpjLookupRecord::query()->count())->toBe(0);
})->with([
    'html' => fn () => fn () => Http::response('<html><body>manutenção</body></html>', 200),
    'sem razão social' => fn () => fn () => Http::response(['cnpj' => CNPJ_SERPRO, 'nome_fantasia' => 'X'], 200),
    'outro cnpj' => fn () => fn () => Http::response(minhaReceitaPayload(['cnpj' => '11222333000181']), 200),
    'corpo gigante' => fn () => fn () => Http::response(str_repeat('a', 600 * 1024), 200),
]);

it('CNPJ com dígitos inválidos é recusado sem chamada externa', function () {
    Http::fake();

    cnpjLookup($this, '33.683.111/0002-81')->assertStatus(422)->assertJsonPath('status', 'invalid');

    Http::assertNothingSent();
});

it('limita consultas por usuário e responde 429 com Retry-After, sem afetar outro usuário', function () {
    config()->set('assinavelox.cnpj.rate_limit.per_minute_user', 2);
    Http::fake(['https://minhareceita.org/*' => Http::response(minhaReceitaPayload())]);

    cnpjLookup($this)->assertOk();
    cnpjLookup($this)->assertOk();

    $blocked = cnpjLookup($this)->assertStatus(429);

    expect($blocked->json('status'))->toBe('rate_limited')
        ->and($blocked->json('manual_fill'))->toBeTrue()
        ->and($blocked->headers->get('Retry-After'))->not->toBeNull();

    $admin = attachMember($this->organization, MembershipRole::Admin);
    actingAsMember($admin, $this->organization);

    cnpjLookup($this)->assertOk();
});

it('só owner/admin consultam nas configurações e a flag desligada responde 404', function () {
    Http::fake(['https://minhareceita.org/*' => Http::response(minhaReceitaPayload())]);

    $member = attachMember($this->organization, MembershipRole::Member);
    actingAsMember($member, $this->organization);
    cnpjLookup($this)->assertForbidden();

    actingAsMember($this->owner, $this->organization);
    config()->set('assinavelox.features.cnpj_lookup', false);
    cnpjLookup($this)->assertNotFound();

    Http::assertNothingSent();
});

it('no cadastro (sem organização) usa o interruptor global e limita por IP', function () {
    auth()->logout();
    config()->set('assinavelox.cnpj.driver', 'fake');
    config()->set('assinavelox.cnpj.rate_limit.per_minute_guest', 2);

    $found = $this->postJson(route('cnpj.lookup'), ['cnpj' => '11.222.333/0001-81'])->assertOk();

    expect($found->json('status'))->toBe('found')
        ->and($found->json('source.simulated'))->toBeTrue()
        ->and($found->json('suggestions.legal_name'))->toContain('(simulado)')
        ->and($found->json('message'))->toContain('SIMULADOS');

    expect($this->postJson(route('cnpj.lookup'), ['cnpj' => FakeCnpjLookup::UNAVAILABLE])->assertOk()->json('status'))->toBe('unavailable');
    $this->postJson(route('cnpj.lookup'), ['cnpj' => FakeCnpjLookup::NOT_FOUND])->assertStatus(429);

    config()->set('assinavelox.features.cnpj_lookup', false);
    $this->postJson(route('cnpj.lookup'), ['cnpj' => '11.222.333/0001-81'])->assertNotFound();

    Http::assertNothingSent();
});
