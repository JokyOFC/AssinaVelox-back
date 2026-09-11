<?php

use App\Models\Envelope;
use App\Models\Template;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/TemplateHelpers.php';

beforeEach(function () {
    $this->work = templatesWorkspace();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

test('flag desligada: a tela Modelos continua sendo o placeholder da Fase 1', function () {
    actingAsMember($this->owner, $this->organization);

    $this->get(route('templates.index'))->assertInertia(fn (Assert $page) => $page
        ->component('templates/index')
        ->where('feature', 'templates')
        ->where('title', 'Modelos de documentos')
        ->missing('enabled')
        ->missing('templates'));
});

test('flag desligada: todas as outras rotas de modelos respondem 404', function () {
    templatesEnable($this->organization);
    $template = templateHtml($this->organization, $this->owner, '<p>{{nome}}</p>', [
        ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
    ]);
    config()->set('assinavelox.features.templates', false);

    actingAsMember($this->owner, $this->organization);

    $this->get(route('templates.picker'))->assertNotFound();
    $this->post(route('templates.store'), ['name' => 'X', 'source_type' => 'html'])->assertNotFound();
    $this->get(route('templates.edit', $template))->assertNotFound();
    $this->put(route('templates.update', $template), ['roles' => [['name' => 'A']]])->assertNotFound();
    $this->post(route('templates.duplicate', $template))->assertNotFound();
    $this->post(route('templates.archive', $template))->assertNotFound();
    $this->post(route('templates.restore', $template))->assertNotFound();
    $this->get(route('templates.preview', $template))->assertNotFound();
    $this->get(route('templates.source.show', $template))->assertNotFound();
    $this->post(route('templates.use', $template), [])->assertNotFound();

    expect(Template::query()->count())->toBe(1);
});

test('flag desligada: envelopes.create ignora ?template e cria o rascunho vazio da Fase 1', function () {
    templatesEnable($this->organization);
    $template = templateHtml($this->organization, $this->owner, '<p>{{nome}}</p>', [
        ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
    ]);
    config()->set('assinavelox.features.templates', false);

    actingAsMember($this->owner, $this->organization);

    $response = $this->get(route('envelopes.create', ['template' => $template->ulid]));

    $envelope = Envelope::query()->latest('id')->firstOrFail();

    $response->assertRedirect(route('envelopes.edit', ['envelope' => $envelope->ulid, 'step' => 1]));
    expect($envelope->title)->toBe('Novo documento')
        ->and($envelope->document()->exists())->toBeFalse();
});

test('flag ligada só na configuração global (sem o plano) continua desligada', function () {
    config()->set('assinavelox.features.templates', true);
    actingAsMember($this->owner, $this->organization);

    $this->get(route('templates.index'))->assertInertia(fn (Assert $page) => $page->missing('enabled'));
    $this->get(route('templates.picker'))->assertNotFound();
});

test('flag ligada só no plano (sem a configuração global) continua desligada', function () {
    templatesEnable($this->organization);
    config()->set('assinavelox.features.templates', false);
    actingAsMember($this->owner, $this->organization);

    $this->get(route('templates.index'))->assertInertia(fn (Assert $page) => $page->missing('enabled'));
});

test('flag ligada: a galeria mostra os modelos da organização', function () {
    templatesEnable($this->organization);
    templateHtml($this->organization, $this->owner, '<p>{{nome}}</p>', [
        ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
    ]);

    actingAsMember($this->owner, $this->organization);

    $this->get(route('templates.index'))->assertInertia(fn (Assert $page) => $page
        ->component('templates/index')
        ->where('feature', 'templates')
        ->where('enabled', true)
        ->has('templates', 1)
        ->where('templates.0.name', 'Contrato de locação')
        ->where('templates.0.category', 'Locação')
        ->where('templates.0.source_type', 'html')
        ->where('templates.0.roles_count', 1)
        ->where('templates.0.variables_count', 1)
        ->where('categories', ['Locação'])
        ->where('can.create', true));
});
