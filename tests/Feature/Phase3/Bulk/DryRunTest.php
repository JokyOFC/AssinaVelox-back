<?php

use App\Models\BulkGeneration;
use App\Models\BulkGenerationRow;
use App\Models\Envelope;
use App\Models\PlanConsumption;
use App\Services\BulkGeneration\BulkGenerationStatus;
use App\Services\BulkGeneration\BulkRowStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/BulkHelpers.php';

/*
|--------------------------------------------------------------------------
| Envio da planilha, mapeamento e pré-validação (dry run) — nada é criado
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->withoutVite();
    $this->work = templatesWorkspace();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    bulkEnable($this->organization);
    actingAsMember($this->owner, $this->organization);
    $this->template = bulkHtmlTemplate($this->organization, $this->owner);
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

test('a tela "Gerar em lote" mostra as colunas esperadas e os limites', function () {
    $this->get(route('bulk_generations.create', $this->template))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('bulk-generations/create')
            ->where('template.id', $this->template->ulid)
            ->where('columns.0.label', 'Título do documento')
            ->where('columns.1.label', 'Locatário — nome')
            ->where('columns.1.required', true)
            ->where('direct_send.available', false)
            ->where('limits.max_rows', 1000));
});

test('o envio guarda a planilha com sha256, sugere o mapeamento e não valida nada ainda', function () {
    $path = bulkCsv($this->work.'/lote.csv', [
        ['VALOR DO ALUGUEL', 'locatario - nome', 'E-mail do Locatário', 'cpf_do_locatario', 'Nome do imóvel', 'Observação interna'],
        ['1.500,00', 'Ana Souza', 'ana@example.com', '529.982.247-25', 'Casa', 'não vai para lugar nenhum'],
    ]);

    $batch = bulkUpload($this->template, bulkFile($path, 'Clientes de setembro.csv'));

    expect($batch->status)->toBe(BulkGenerationStatus::Draft)
        ->and($batch->source_format)->toBe('csv')
        ->and($batch->source_filename)->toBe('Clientes de setembro.csv')
        ->and($batch->source_sha256)->toBe(hash_file('sha256', $path))
        ->and($batch->template_version_id)->toBe($this->template->current_version_id)
        ->and($batch->row_count)->toBe(1)
        ->and(Storage::disk('documents')->exists($batch->source_file))->toBeTrue()
        ->and(BulkGenerationRow::query()->count())->toBe(0);

    $roleUlid = templateRoleIds($this->template)['Locatário'];

    expect(bulkSuggestedMapping($batch))->toBe([
        '0' => 'var:valor',
        '1' => "role:{$roleUlid}:name",
        '2' => "role:{$roleUlid}:email",
        '3' => 'var:cpf',
        '4' => 'var:nome',
    ]);
});

test('a pré-validação faz o relatório por linha sem criar envelope nem tocar a cota', function () {
    $path = bulkCsv($this->work.'/lote.csv', [
        ['Locatário — nome', 'Locatário — e-mail', 'Nome do imóvel', 'CPF do locatário', 'Valor do aluguel', 'Início', 'Mobiliado'],
        ['Ana Souza', 'ana@example.com', 'Casa', '529.982.247-25', '1.500,00', '05/10/2026', 'sim'],
        ['Bruno', 'bruno@', 'Apto', '529.982.247-25', '900', '', 'não'],
        ['', 'carla@example.com', 'Sala', '111.111.111-11', 'mil reais', '31/02/2026', 'talvez'],
        ['Dora Lima', 'DORA@EXAMPLE.COM', '', '52998224725', '2000', '2026-12-01', ''],
    ]);

    $batch = bulkValidate(bulkUpload($this->template, bulkFile($path)));

    expect($batch->status)->toBe(BulkGenerationStatus::Validated)
        ->and($batch->valid_count)->toBe(1)
        ->and($batch->invalid_count)->toBe(3)
        ->and($batch->dry_run_at)->not->toBeNull();

    $rows = BulkGenerationRow::query()->where('bulk_generation_id', $batch->id)->orderBy('row_index')->get();

    expect($rows->pluck('row_index')->all())->toBe([2, 3, 4, 5])
        ->and($rows[0]->status)->toBe(BulkRowStatus::Valid)
        ->and($rows[0]->payload['values'])->toBe(['nome' => 'Casa', 'cpf' => '529.982.247-25', 'valor' => '1.500,00', 'inicio' => '05/10/2026', 'mobiliado' => '1']);

    $messages = fn (int $i): array => collect($rows[$i]->errors)->pluck('message', 'header')->all();

    expect($messages(1))->toBe(['Locatário — e-mail' => 'Informe um e-mail válido para "Locatário".'])
        ->and(array_keys($messages(2)))->toBe(['CPF do locatário', 'Valor do aluguel', 'Início', 'Mobiliado', 'Locatário — nome'])
        ->and($messages(3))->toBe(['Nome do imóvel' => 'Preencha "Nome do imóvel".']);

    // Nada criado, nada reservado; erros sem o valor digitado.
    expect(Envelope::query()->count())->toBe(0)
        ->and(PlanConsumption::query()->count())->toBe(0)
        ->and($this->organization->currentSubscription()->first()->envelopes_reserved)->toBe(0)
        ->and(json_encode($rows->pluck('errors')->all()))->not->toContain('bruno@')
        ->and(json_encode($rows->pluck('errors')->all()))->not->toContain('mil reais');
});

test('o payload guarda só as colunas mapeadas e fica cifrado no banco', function () {
    $path = bulkCsv($this->work.'/lote.csv', [
        ['Locatário — nome', 'Locatário — e-mail', 'Nome do imóvel', 'CPF do locatário', 'Valor do aluguel', 'Senha do portal'],
        ['Ana Souza', 'ana@example.com', 'Casa', '529.982.247-25', '1.500,00', 'segredo-que-nao-mapeei'],
    ]);

    $batch = bulkValidate(bulkUpload($this->template, bulkFile($path)));
    $raw = (string) DB::table('bulk_generation_rows')->where('bulk_generation_id', $batch->id)->value('payload');
    $row = BulkGenerationRow::query()->where('bulk_generation_id', $batch->id)->sole();

    expect($raw)->not->toContain('ana@example.com')
        ->and($raw)->not->toContain('Ana Souza')
        ->and(json_encode($row->payload))->not->toContain('segredo-que-nao-mapeei')
        ->and($row->payload['participants'][templateRoleIds($this->template)['Locatário']])->toBe(['name' => 'Ana Souza', 'email' => 'ana@example.com']);
});

test('mapeamento incompleto, repetido ou com destino inexistente é recusado com orientação', function () {
    $path = bulkCsv($this->work.'/lote.csv', [
        ['Locatário — nome', 'Outro nome', 'Nome do imóvel'],
        ['Ana', 'Bia', 'Casa'],
    ]);

    $batch = bulkUpload($this->template, bulkFile($path));
    $role = templateRoleIds($this->template)['Locatário'];

    $this->put(route('bulk_generations.mapping', $batch), ['mapping' => [
        '0' => "role:{$role}:name",
        '1' => "role:{$role}:name",
        '2' => 'var:inexistente',
    ]])->assertSessionHasErrors('mapping');

    $errors = session('errors')->get('mapping');

    expect($errors)->toContain('"Locatário — nome" está ligado a mais de uma coluna. Escolha só uma.')
        ->and($errors)->toContain('A coluna "Nome do imóvel" foi ligada a um campo que não existe neste modelo.')
        ->and($errors)->toContain('Escolha a coluna de "Locatário — e-mail".')
        ->and($errors)->toContain('Escolha a coluna de "CPF do locatário".')
        ->and($batch->fresh()->status)->toBe(BulkGenerationStatus::Draft);
});

test('refazer a pré-validação substitui o relatório anterior', function () {
    $path = bulkCsv($this->work.'/lote.csv', [
        ['Locatário — nome', 'Locatário — e-mail', 'Nome do imóvel', 'CPF do locatário', 'Valor do aluguel', 'Apelido'],
        ['Ana Souza', 'ana@example.com', 'Casa', '529.982.247-25', '1.500,00', 'A'],
    ]);

    $batch = bulkUpload($this->template, bulkFile($path));
    $mapping = bulkSuggestedMapping($batch);

    // Primeiro, o apelido (1 caractere) como nome do participante: inválido.
    $role = templateRoleIds($this->template)['Locatário'];
    $wrong = $mapping;
    unset($wrong['0']);
    $wrong['5'] = "role:{$role}:name";

    expect(bulkValidate($batch, $wrong)->invalid_count)->toBe(1);
    expect(bulkValidate($batch, $mapping)->valid_count)->toBe(1)
        ->and(BulkGenerationRow::query()->where('bulk_generation_id', $batch->id)->count())->toBe(1);
});

test('a planilha-modelo traz o cabeçalho que a sugestão reconhece por inteiro', function () {
    $response = $this->get(route('bulk_generations.sample', $this->template))->assertOk();
    $content = $response->streamedContent();

    expect($content)->toStartWith("\xEF\xBB\xBF")
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    file_put_contents($this->work.'/modelo.csv', $content."\"Ana Souza\";ana@example.com;Casa;529.982.247-25;1500;;;\"Contrato Ana\"\r\n");
    $batch = bulkUpload($this->template, bulkFile($this->work.'/modelo.csv'));

    expect(count(bulkSuggestedMapping($batch)))->toBe(count($batch->headers));

    $validated = bulkValidate($batch);

    expect($validated->valid_count)->toBe(1)
        ->and(BulkGenerationRow::query()->where('bulk_generation_id', $batch->id)->sole()->payload['title'])->toBe('Contrato Ana');
});

test('planilha recusada no envio não cria lote nem guarda arquivo', function () {
    $path = bulkXlsx($this->work.'/macro.xlsx', [['rows' => [['Nome'], ['Ana']]]], ['xl/vbaProject.bin' => 'x']);

    $this->post(route('bulk_generations.store', $this->template), ['file' => bulkFile($path)])
        ->assertSessionHasErrors(['file' => 'A planilha contém macros. Salve como .xlsx comum (sem macros) e envie de novo.']);

    $this->post(route('bulk_generations.store', $this->template), ['file' => bulkFile(bulkCsv($this->work.'/x.pdf', [['a'], ['b']]))])
        ->assertSessionHasErrors('file');

    expect(BulkGeneration::query()->count())->toBe(0)
        ->and(Storage::disk('documents')->allFiles())->toBe([]);
});

test('descartar um lote não confirmado apaga as linhas e o arquivo', function () {
    $path = bulkCsv($this->work.'/lote.csv', [
        ['Locatário — nome', 'Locatário — e-mail', 'Nome do imóvel', 'CPF do locatário', 'Valor do aluguel'],
        ['Ana Souza', 'ana@example.com', 'Casa', '529.982.247-25', '1.500,00'],
    ]);

    $batch = bulkValidate(bulkUpload($this->template, bulkFile($path)));
    $file = $batch->source_file;

    $this->delete(route('bulk_generations.destroy', $batch))->assertRedirect(route('bulk_generations.index'));

    expect(BulkGeneration::query()->count())->toBe(0)
        ->and(BulkGenerationRow::query()->count())->toBe(0)
        ->and(Storage::disk('documents')->exists($file))->toBeFalse();
});

test('a tela do lote traz cabeçalhos, destinos, mapeamento e o relatório de problemas', function () {
    $path = bulkCsv($this->work.'/lote.csv', [
        ['Locatário — nome', 'Locatário — e-mail', 'Nome do imóvel', 'CPF do locatário', 'Valor do aluguel'],
        ['Ana Souza', 'ana@example.com', 'Casa', '529.982.247-25', '1.500,00'],
        ['Bruno', 'bruno@', 'Apto', '529.982.247-25', '900'],
    ]);

    $batch = bulkValidate(bulkUpload($this->template, bulkFile($path)));

    $this->get(route('bulk_generations.show', $batch))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('bulk-generations/show')
            ->where('batch.status', 'validated')
            ->where('headers.1.label', 'Locatário — e-mail')
            ->where('headers.1.letter', 'B')
            ->where('mapping.2', 'var:nome')
            ->where('counts.valid', 1)
            ->where('counts.invalid', 1)
            ->where('rows.filter', 'problems')
            ->has('rows.data', 1)
            ->where('rows.data.0.line', 3)
            ->where('quota.needed', 1)
            ->where('can.manage', true));
});
