<?php

use App\Enums\MembershipRole;
use App\Jobs\BulkGeneration\StartBulkGenerationJob;
use App\Models\BulkGeneration;
use App\Services\BulkGeneration\BulkGenerationStatus;
use App\Services\Templates\TemplateManager;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/BulkHelpers.php';

/*
|--------------------------------------------------------------------------
| Permissões e isolamento entre organizações
|--------------------------------------------------------------------------
| Gerar: a mesma regra de "Usar modelo" (create_envelopes). Ver: quem criou, ou quem vê todos
| os documentos. Cancelar: quem criou, ou quem cancela qualquer documento.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->work = templatesWorkspace();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    bulkEnable($this->organization);
    $this->member = attachMember($this->organization, MembershipRole::Member);
    actingAsMember($this->owner, $this->organization);
    $this->template = bulkHtmlTemplate($this->organization, $this->owner);

    $this->csv = bulkCsv($this->work.'/lote.csv', [
        ['Locatário — nome', 'Locatário — e-mail', 'Nome do imóvel', 'CPF do locatário', 'Valor do aluguel'],
        ['Ana Souza', 'ana@example.com', 'Casa', '529.982.247-25', '1.500,00'],
    ]);
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

test('isolamento: o lote de uma organização não existe para outra', function () {
    $batch = bulkValidate(bulkUpload($this->template, bulkFile($this->csv)));

    ['organization' => $other, 'owner' => $stranger] = createOrganizationWithOwner();
    bulkEnable($other);
    actingAsMember($stranger, $other);

    $this->get(route('bulk_generations.show', $batch))->assertNotFound();
    $this->get(route('bulk_generations.report', $batch))->assertNotFound();
    $this->put(route('bulk_generations.mapping', $batch), ['mapping' => bulkSuggestedMapping($batch)])->assertNotFound();
    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'review'])->assertNotFound();
    $this->post(route('bulk_generations.cancel', $batch))->assertNotFound();
    $this->delete(route('bulk_generations.destroy', $batch))->assertNotFound();
    $this->get(route('bulk_generations.create', $this->template))->assertNotFound();
    $this->post(route('bulk_generations.store', $this->template), ['file' => bulkFile($this->csv)])->assertNotFound();

    $this->get(route('bulk_generations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('bulk-generations/index')->has('batches', 0));

    expect($batch->fresh()->status)->toBe(BulkGenerationStatus::Validated);
});

test('operador gera a partir do modelo, mas só vê e mexe nos próprios lotes', function () {
    $ownerBatch = bulkValidate(bulkUpload($this->template, bulkFile($this->csv)));

    actingAsMember($this->member, $this->organization);
    $memberBatch = bulkUpload($this->template, bulkFile($this->csv));

    expect($memberBatch->created_by_user_id)->toBe($this->member->id);

    $this->get(route('bulk_generations.index'))
        ->assertInertia(fn (Assert $page) => $page->has('batches', 1)->where('batches.0.id', $memberBatch->ulid));

    $this->get(route('bulk_generations.show', $memberBatch))->assertOk();
    $this->get(route('bulk_generations.show', $ownerBatch))->assertForbidden();
    $this->get(route('bulk_generations.report', $ownerBatch))->assertForbidden();
    $this->put(route('bulk_generations.mapping', $ownerBatch), ['mapping' => bulkSuggestedMapping($ownerBatch)])->assertForbidden();
    $this->post(route('bulk_generations.confirm', $ownerBatch), ['mode' => 'review'])->assertForbidden();
    $this->delete(route('bulk_generations.destroy', $ownerBatch))->assertForbidden();

    // Quem vê todos os documentos vê todos os lotes.
    actingAsMember($this->owner, $this->organization);

    $this->get(route('bulk_generations.index'))->assertInertia(fn (Assert $page) => $page->has('batches', 2));
    $this->get(route('bulk_generations.show', $memberBatch))->assertOk();
});

test('operador não cancela o lote de outra pessoa; o administrador cancela', function () {
    Queue::fake([StartBulkGenerationJob::class]);

    $batch = bulkValidate(bulkUpload($this->template, bulkFile($this->csv)));
    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'review'])->assertSessionHasNoErrors();

    actingAsMember($this->member, $this->organization);
    $this->post(route('bulk_generations.cancel', $batch))->assertForbidden();

    $admin = attachMember($this->organization, MembershipRole::Admin);
    actingAsMember($admin, $this->organization);
    $this->post(route('bulk_generations.cancel', $batch))->assertSessionHasNoErrors();

    expect($batch->fresh()->status)->toBe(BulkGenerationStatus::Canceled);
});

test('modelo arquivado não gera em lote', function () {
    app(TemplateManager::class)->archive($this->template->fresh(), $this->owner);

    $this->get(route('bulk_generations.create', $this->template))->assertForbidden();
    $this->post(route('bulk_generations.store', $this->template), ['file' => bulkFile($this->csv)])->assertForbidden();

    expect(BulkGeneration::query()->count())->toBe(0);
});

test('relatório CSV: só para quem vê o lote, sem cache, com células neutralizadas e só mensagens', function () {
    $csv = bulkCsv($this->work.'/relatorio.csv', [
        ['Locatário — nome', '-1', 'Nome do imóvel', 'CPF do locatário', 'Valor do aluguel'],
        ['Ana Souza', 'ana@@example', 'Casa', '529.982.247-25', '1.500,00'],
        ['Bruno Lima', 'bruno@example.com', 'Apto', '529.982.247-25', '=HYPERLINK("http://evil.example")'],
    ]);

    $batch = bulkUpload($this->template, bulkFile($csv));
    $mapping = bulkSuggestedMapping($batch);
    $mapping['1'] = 'role:'.templateRoleIds($this->template)['Locatário'].':email';
    $batch = bulkValidate($batch, $mapping);

    $response = $this->get(route('bulk_generations.report', $batch))->assertOk();
    $content = $response->streamedContent();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($content)->toStartWith("\xEF\xBB\xBF".'Linha;Situação;Coluna;Mensagem;Documento')
        ->and($content)->toContain("2;\"Com erro\";'-1;\"Informe um e-mail válido para \"\"Locatário\"\".\";")
        ->and($content)->toContain('3;"Com erro";"Valor do aluguel";')
        ->and($content)->not->toContain('ana@@example')
        ->and($content)->not->toContain('evil.example');

    actingAsMember($this->member, $this->organization);
    $this->get(route('bulk_generations.report', $batch))->assertForbidden();
});
