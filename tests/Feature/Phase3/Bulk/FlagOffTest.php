<?php

use App\Models\BulkGeneration;
use App\Services\BulkGeneration\BulkGenerationFeature;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/BulkHelpers.php';

/*
|--------------------------------------------------------------------------
| Flag `bulk_generation` desligada (o padrão): o recurso não existe (T8)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->withoutVite();
    $this->work = templatesWorkspace();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    actingAsMember($this->owner, $this->organization);
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

test('a flag nasce desligada', function () {
    expect(config('assinavelox.features.bulk_generation'))->toBeFalse()
        ->and(BulkGenerationFeature::enabled($this->organization))->toBeFalse();
})->skip(fn () => (bool) env('ASSINAVELOX_FEATURE_BULK_GENERATION', false), 'ambiente com a flag ligada');

test('com a flag desligada todas as rotas do lote respondem 404, mesmo com modelos ligados', function () {
    templatesEnable($this->organization);
    $template = bulkHtmlTemplate($this->organization, $this->owner);
    $csv = bulkCsv($this->work.'/lote.csv', [['Nome'], ['Ana']]);
    $ghost = '01HZZZZZZZZZZZZZZZZZZZZZZZ';

    $this->get(route('bulk_generations.index'))->assertNotFound();
    $this->get(route('bulk_generations.create', $template))->assertNotFound();
    $this->get(route('bulk_generations.sample', $template))->assertNotFound();
    $this->post(route('bulk_generations.store', $template), ['file' => bulkFile($csv)])->assertNotFound();
    $this->get(route('bulk_generations.show', $ghost))->assertNotFound();
    $this->put(route('bulk_generations.mapping', $ghost), ['mapping' => []])->assertNotFound();
    $this->post(route('bulk_generations.confirm', $ghost), ['mode' => 'review'])->assertNotFound();
    $this->post(route('bulk_generations.cancel', $ghost))->assertNotFound();
    $this->delete(route('bulk_generations.destroy', $ghost))->assertNotFound();
    $this->get(route('bulk_generations.report', $ghost))->assertNotFound();

    expect(BulkGeneration::query()->count())->toBe(0);
});

test('interruptor global ligado sem o plano, ou sem a flag de modelos, continua 404', function () {
    templatesEnable($this->organization);
    config()->set('assinavelox.features.bulk_generation', true);

    // Plano sem `bulk_generation`.
    $this->get(route('bulk_generations.index'))->assertNotFound();

    // Plano com `bulk_generation`, mas `templates` desligada.
    bulkEnable($this->organization);
    config()->set('assinavelox.features.templates', false);

    $this->get(route('bulk_generations.index'))->assertNotFound();
});

test('um lote já criado some quando a flag é desligada', function () {
    bulkEnable($this->organization);
    $template = bulkHtmlTemplate($this->organization, $this->owner);
    $batch = bulkUpload($template, bulkFile(bulkCsv($this->work.'/lote.csv', [['Nome do imóvel'], ['Casa']])));

    $this->get(route('bulk_generations.show', $batch))->assertOk();

    config()->set('assinavelox.features.bulk_generation', false);

    $this->get(route('bulk_generations.show', $batch))->assertNotFound();
    $this->get(route('bulk_generations.report', $batch))->assertNotFound();
});

test('a prop compartilhada features.bulk_generation acompanha a flag', function () {
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('features.bulk_generation', false));

    bulkEnable($this->organization);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('features.bulk_generation', true));
});
