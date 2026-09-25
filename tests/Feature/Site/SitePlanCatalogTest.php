<?php

use App\Models\Plan;

/*
|--------------------------------------------------------------------------
| Catálogo de planos para o site institucional (docs/site-institucional.md)
|--------------------------------------------------------------------------
| Rota pública, sem sessão, chamada pelo navegador do visitante de outra origem. O que ela
| publica segue a mesma regra de visibilidade da tela interna de planos.
*/

test('o site recebe os planos ativos em ordem, com rótulos de recursos e o destino de cada botão', function () {
    Plan::factory()->free()->create();
    Plan::factory()->professional()->create();
    Plan::factory()->enterprise()->create();
    Plan::factory()->inactive()->create(['code' => 'legado', 'is_public' => true, 'sort_order' => 0]);

    $plans = $this->getJson(route('site.plans'))->assertOk()->json('data');

    expect(array_column($plans, 'code'))->toBe(['free', 'professional', 'enterprise']);

    [$free, $professional, $enterprise] = $plans;

    expect($free['is_free'])->toBeTrue()
        ->and($free['price_cents'])->toBe(0)
        ->and($free['price_formatted'])->toBe('Grátis')
        ->and($free['billing_period'])->toBe('monthly')
        ->and($free['features'])->toContain('5 documentos/mês')
        ->and($free['features'])->toContain('Código por e-mail')
        ->and($free['limits'])->toBe(['envelopes_per_month' => 5, 'members' => 1])
        ->and($free['price_is_placeholder'])->toBeFalse()
        ->and($free['cta'])->toBe('register')
        ->and($free['register_url'])->toBe(route('register'));

    expect($professional['highlighted'])->toBeTrue()
        ->and($professional['price_cents'])->toBe(4_900)
        ->and($professional['price_formatted'])->toBe('R$ 49,00')
        ->and($professional['price_is_placeholder'])->toBeTrue()
        ->and($professional['cta'])->toBe('register');

    expect($enterprise['cta'])->toBe('register')
        ->and($enterprise['highlighted'])->toBeFalse();
});

test('o catálogo expõe exatamente as chaves previstas, e nenhuma a mais', function () {
    Plan::factory()->free()->create();

    $plan = $this->getJson(route('site.plans'))->json('data.0');

    expect(array_keys($plan))->toEqualCanonicalizing([
        'code', 'name', 'description', 'currency', 'billing_period', 'billing_period_label',
        'price_cents', 'price_cents_monthly', 'price_formatted', 'is_free', 'features', 'limits',
        'highlighted', 'is_sandbox', 'price_is_placeholder', 'cta', 'register_url',
    ]);
});

test('em produção, planos sandbox não são anunciados ao site', function () {
    Plan::factory()->free()->create();
    // Sandbox e não público: preço de desenvolvimento, nunca oferta.
    Plan::factory()->professional()->create();
    Plan::factory()->enterprise()->create(['is_sandbox' => false, 'is_public' => true]);

    $this->app->detectEnvironment(fn (): string => 'production');

    $codes = array_column($this->getJson(route('site.plans'))->assertOk()->json('data'), 'code');

    expect($codes)->toBe(['free', 'enterprise']);
});

test('a assinatura criptográfica da operadora só é anunciada quando há certificado ativo', function () {
    Plan::factory()->professional()->create();

    $features = $this->getJson(route('site.plans'))->json('data.0.features');

    expect($features)->not->toContain('Assinatura criptográfica da operadora');
});

test('o navegador do site, em outra origem, recebe os cabeçalhos de CORS e de cache', function () {
    Plan::factory()->free()->create();

    $response = $this->getJson(route('site.plans'), ['Origin' => 'https://assinavelox.com.br'])
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', '*');

    expect($response->headers->get('Cache-Control'))->toContain('max-age=300')
        ->and($response->headers->has('Set-Cookie'))->toBeFalse();
});
