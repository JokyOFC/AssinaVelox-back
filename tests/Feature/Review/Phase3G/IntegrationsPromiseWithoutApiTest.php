<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial onda G (produto/semântica) — promessas em API e integrações
|--------------------------------------------------------------------------
| 1. Com `embedded_signing` ligada e a API v1 desligada (o padrão de `api_integrations`),
|    API e integrações renderiza o placeholder `integrations/index`: o cartão "Widget de
|    assinatura — Deixe o participante assinar dentro do seu site" aparece logo acima de
|    "A API pública, chaves e webhooks estarão disponíveis na Fase 2". O widget só abre com
|    uma sessão criada pela API v1 (EmbedFeature: "na prática também exige api_integrations").
|    A mesma tela promete e nega, e o menu lateral continua com o selo "Fase 2".
|
| 2. A tela de origens do widget (interface em PT-BR) instrui "Crie a sessão do participante
|    com a ability embedded_signing:manage, informando a origin do site" — inglês e jargão.
*/

beforeEach(fn () => $this->withoutVite());

it('não oferece o widget na mesma tela que diz que a API ainda não existe', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    config()->set('assinavelox.features.api_integrations', false);
    config()->set('assinavelox.features.outbound_webhooks', false);
    config()->set('assinavelox.features.rest_hooks', false);
    config()->set('assinavelox.features.embedded_signing', true);

    $plan = $organization->currentSubscription()->with('plan')->first()?->plan;
    $features = (array) ($plan->features ?? []);
    $features['embedded_signing'] = true;
    $plan->forceFill(['features' => $features])->save();

    actingAsMember($owner, $organization);

    $page = $this->get(route('integrations.index'))->assertOk()->viewData('page');

    $placeholder = $page['component'] === 'integrations/index';
    $widgetOffered = ($page['props']['features']['embedded_signing'] ?? false) === true;

    expect($placeholder && $widgetOffered)->toBeFalse(
        'API e integrações mostra o cartão "Widget de assinatura" acima de "A API pública… estarão disponíveis na Fase 2"; o widget depende da API v1.',
    );
});

it('a tela de origens do widget fala português, sem "ability" nem "origin"', function () {
    $source = (string) file_get_contents(base_path('resources/js/pages/embed/origins.tsx'));
    $text = (string) preg_replace('/\s+/u', ' ', $source);

    expect(preg_match('/com a ability/u', $text))->toBe(
        0,
        'origins.tsx: "Crie a sessão do participante com a ability embedded_signing:manage, informando a origin do site".',
    );
});
