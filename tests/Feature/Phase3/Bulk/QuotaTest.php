<?php

use App\Enums\PlanConsumptionStatus;
use App\Jobs\BulkGeneration\StartBulkGenerationJob;
use App\Models\BulkGenerationRow;
use App\Models\Envelope;
use App\Models\PlanConsumption;
use App\Services\BulkGeneration\BulkGenerationLimits;
use App\Services\BulkGeneration\BulkGenerationQuota;
use App\Services\BulkGeneration\BulkGenerationStatus;
use App\Services\BulkGeneration\BulkRowStatus;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/BulkHelpers.php';

/*
|--------------------------------------------------------------------------
| Cota: reservada na confirmação, só para as linhas válidas; se não couber, nada é criado
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->withoutVite();
    $this->work = templatesWorkspace();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    bulkEnable($this->organization);
    actingAsMember($this->owner, $this->organization);
    $this->template = bulkHtmlTemplate($this->organization, $this->owner);

    // 3 linhas válidas e 1 inválida.
    $this->csv = bulkCsv($this->work.'/lote.csv', [
        ['Locatário — nome', 'Locatário — e-mail', 'Nome do imóvel', 'CPF do locatário', 'Valor do aluguel'],
        ['Ana Souza', 'ana@example.com', 'Casa', '529.982.247-25', '1.500,00'],
        ['Bruno Lima', 'bruno@example.com', 'Apto', '529.982.247-25', '900'],
        ['Carla Dias', 'carla@', 'Sala', '529.982.247-25', '800'],
        ['Dora Reis', 'dora@example.com', 'Loja', '529.982.247-25', '700'],
    ]);
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

test('confirmar reserva uma unidade por linha VÁLIDA — as inválidas não reservam nada', function () {
    Queue::fake([StartBulkGenerationJob::class]);
    bulkQuota($this->organization, 10);

    $batch = bulkValidate(bulkUpload($this->template, bulkFile($this->csv)));

    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'review'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('bulk_generations.show', $batch));

    $batch->refresh();
    $subscription = $this->organization->currentSubscription()->first();
    $consumptions = PlanConsumption::query()->where('idempotency_key', 'like', "bulk:{$batch->id}:row:%")->get();

    expect($batch->status)->toBe(BulkGenerationStatus::Running)
        ->and($batch->confirmed_by_user_id)->toBe($this->owner->id)
        ->and($consumptions)->toHaveCount(3)
        ->and($consumptions->pluck('status')->unique()->all())->toBe([PlanConsumptionStatus::Reserved])
        ->and($consumptions->pluck('envelope_id')->filter()->all())->toBe([])
        ->and($subscription->envelopes_reserved)->toBe(3)
        ->and($subscription->remainingEnvelopes())->toBe(7)
        ->and(BulkGenerationRow::query()->where('status', BulkRowStatus::Pending->value)->count())->toBe(3)
        ->and(BulkGenerationRow::query()->where('status', BulkRowStatus::Invalid->value)->count())->toBe(1)
        ->and(app(BulkGenerationQuota::class)->reservedFor($batch))->toBe(3);

    Queue::assertPushed(StartBulkGenerationJob::class, fn ($job) => $job->batchId === $batch->id);
});

test('cota insuficiente: nada é criado nem reservado, e a mensagem diz quantos cabem', function () {
    Queue::fake();
    bulkQuota($this->organization, 10, used: 8);

    $batch = bulkValidate(bulkUpload($this->template, bulkFile($this->csv)));

    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'review'])
        ->assertSessionHasErrors(['batch' => 'A cota do plano comporta mais 2 documentos neste ciclo, e o lote tem 3 linhas válidas. Nada foi criado. Remova linhas da planilha ou amplie o plano.']);

    expect($batch->fresh()->status)->toBe(BulkGenerationStatus::Validated)
        ->and(PlanConsumption::query()->count())->toBe(0)
        ->and($this->organization->currentSubscription()->first()->envelopes_reserved)->toBe(0)
        ->and(BulkGenerationRow::query()->where('status', BulkRowStatus::Pending->value)->count())->toBe(0)
        ->and(Envelope::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

test('as reservas de outros envios contam: o lote só cabe no que sobra', function () {
    Queue::fake([StartBulkGenerationJob::class]);
    bulkQuota($this->organization, 4);

    $subscription = $this->organization->currentSubscription()->first();
    $subscription->forceFill(['envelopes_reserved' => 2])->save();

    $batch = bulkValidate(bulkUpload($this->template, bulkFile($this->csv)));

    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'review'])
        ->assertSessionHasErrors(['batch' => 'A cota do plano comporta mais 2 documentos neste ciclo, e o lote tem 3 linhas válidas. Nada foi criado. Remova linhas da planilha ou amplie o plano.']);
});

test('reconfirmar não reserva de novo', function () {
    Queue::fake([StartBulkGenerationJob::class]);
    bulkQuota($this->organization, 10);

    $batch = bulkValidate(bulkUpload($this->template, bulkFile($this->csv)));

    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'review'])->assertSessionHasNoErrors();
    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'review'])->assertSessionHasErrors('batch');

    expect(PlanConsumption::query()->count())->toBe(3)
        ->and($this->organization->currentSubscription()->first()->envelopes_reserved)->toBe(3);
});

test('lote sem pré-validação ou sem linha válida não pode ser confirmado', function () {
    Queue::fake();

    $batch = bulkUpload($this->template, bulkFile($this->csv));
    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'review'])
        ->assertSessionHasErrors(['batch' => 'Este lote não está pronto para confirmação. Refaça a pré-validação.']);

    $invalid = bulkCsv($this->work.'/invalido.csv', [
        ['Locatário — nome', 'Locatário — e-mail', 'Nome do imóvel', 'CPF do locatário', 'Valor do aluguel'],
        ['Ana Souza', 'ana@', 'Casa', '529.982.247-25', '1.500,00'],
    ]);
    $none = bulkValidate(bulkUpload($this->template, bulkFile($invalid)));

    $this->post(route('bulk_generations.confirm', $none), ['mode' => 'review'])
        ->assertSessionHasErrors(['batch' => 'Nenhuma linha válida para gerar. Corrija a planilha e envie de novo.']);

    expect(PlanConsumption::query()->count())->toBe(0);
});

test('limite de lotes simultâneos por organização', function () {
    Queue::fake([StartBulkGenerationJob::class]);
    config()->set('assinavelox.bulk_generation.max_concurrent_batches', 1);

    $first = bulkValidate(bulkUpload($this->template, bulkFile($this->csv)));
    $second = bulkValidate(bulkUpload($this->template, bulkFile($this->csv)));

    $this->post(route('bulk_generations.confirm', $first), ['mode' => 'review'])->assertSessionHasNoErrors();
    $this->post(route('bulk_generations.confirm', $second), ['mode' => 'review'])
        ->assertSessionHasErrors(['batch' => 'Já há 1 lote em geração nesta organização (o limite do plano). Aguarde um terminar para confirmar este.']);
});

test('o plano pode apertar os limites globais, nunca ampliá-los', function () {
    bulkEnable($this->organization, ['bulk_generation_limits' => ['max_rows' => 2, 'concurrency' => 50]]);

    $this->post(route('bulk_generations.store', $this->template), ['file' => bulkFile($this->csv)])
        ->assertSessionHasErrors(['file' => 'A planilha passa do limite de 2 linhas por lote. Divida em lotes menores.']);

    expect(BulkGenerationLimits::for($this->organization)->concurrency)->toBe(3);
});
