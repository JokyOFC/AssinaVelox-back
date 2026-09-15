<?php

use App\Enums\EnvelopeStatus;
use App\Models\BulkGenerationRow;
use App\Models\Envelope;
use App\Services\BulkGeneration\BulkGenerationPump;
use App\Services\BulkGeneration\BulkGenerationQuota;
use App\Services\BulkGeneration\BulkGenerationStatus;
use App\Services\BulkGeneration\BulkRowOutcome;
use App\Services\BulkGeneration\BulkRowStatus;
use App\Services\BulkGeneration\RowGenerator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/BulkHelpers.php';

/*
|--------------------------------------------------------------------------
| Cancelar no meio: para as pendentes, devolve a cota, não toca no que já foi criado/enviado
|--------------------------------------------------------------------------
| Fila falsa controlada: o teste decide quais linhas "rodam" antes do cancelamento.
*/

beforeEach(function () {
    $this->withoutVite();
    templatesRequirePdftool();
    $this->work = templatesWorkspace();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    bulkEnable($this->organization);
    bulkQuota($this->organization, 50);
    actingAsMember($this->owner, $this->organization);
    $this->template = bulkPdfTemplate($this->organization, $this->owner, $this->work);
    config()->set('assinavelox.bulk_generation.concurrency_per_organization', 3);
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

test('cancelar no meio interrompe as linhas pendentes sem afetar os envelopes já enviados', function () {
    Mail::fake();
    Notification::fake();
    Queue::fake();

    $batch = bulkValidate(bulkUpload($this->template, bulkFile(bulkCsv($this->work.'/lote.csv', bulkPdfRows(10)))));
    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'send'])->assertSessionHasNoErrors();
    $file = $batch->fresh()->source_file;

    // O orquestrador põe 3 na fila; duas rodam e são enviadas.
    app(BulkGenerationPump::class)->pump($this->organization->id);
    $queued = BulkGenerationRow::query()->where('status', BulkRowStatus::Queued->value)->orderBy('row_index')->get();
    app(RowGenerator::class)->handle($queued[0]->id);
    app(RowGenerator::class)->handle($queued[1]->id);

    expect(Envelope::query()->where('status', EnvelopeStatus::InProgress->value)->count())->toBe(2)
        ->and($this->organization->currentSubscription()->first()->envelopes_reserved)->toBe(8);

    $this->post(route('bulk_generations.cancel', $batch))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('bulk_generations.show', $batch));

    $batch->refresh();

    expect($batch->status)->toBe(BulkGenerationStatus::Canceled)
        ->and($batch->canceled_by_user_id)->toBe($this->owner->id)
        ->and($batch->canceled_count)->toBe(8)
        ->and(BulkGenerationRow::query()->where('status', BulkRowStatus::Canceled->value)->count())->toBe(8)
        ->and(BulkGenerationRow::query()->where('status', BulkRowStatus::Canceled->value)->whereNotNull('payload')->count())->toBe(0)
        ->and(app(BulkGenerationQuota::class)->reservedFor($batch))->toBe(0)
        ->and($this->organization->currentSubscription()->first()->envelopes_reserved)->toBe(0)
        ->and(Storage::disk('documents')->exists($file))->toBeFalse();

    // Os dois enviados continuam enviados; nada novo é criado, nem por um job que já estava na fila.
    app(RowGenerator::class)->handle($queued[2]->id);
    app(BulkGenerationPump::class)->pump($this->organization->id);

    expect(Envelope::query()->count())->toBe(2)
        ->and(Envelope::query()->where('status', EnvelopeStatus::InProgress->value)->count())->toBe(2)
        ->and($batch->fresh()->status)->toBe(BulkGenerationStatus::Canceled);
});

test('linha reivindicada antes do cancelamento e processada depois não gera envelope', function () {
    Queue::fake();

    $batch = bulkValidate(bulkUpload($this->template, bulkFile(bulkCsv($this->work.'/lote.csv', bulkPdfRows(3)))));
    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'send'])->assertSessionHasNoErrors();

    $row = BulkGenerationRow::query()->where('bulk_generation_id', $batch->id)->orderBy('row_index')->first();
    $row->forceFill(['status' => BulkRowStatus::Processing])->save(); // um worker já pegou a linha

    $this->post(route('bulk_generations.cancel', $batch))->assertSessionHasNoErrors();

    expect($row->fresh()->status)->toBe(BulkRowStatus::Processing);

    app(RowGenerator::class)->handle($row->id);

    expect(Envelope::query()->count())->toBe(0)
        ->and($row->fresh()->status)->toBe(BulkRowStatus::Canceled)
        ->and(app(BulkGenerationQuota::class)->reservedFor($batch->fresh()))->toBe(0);
});

test('envelope criado e ainda sem envio quando o lote é cancelado fica para revisão, sem enviar', function () {
    Mail::fake();
    Queue::fake();

    $batch = bulkValidate(bulkUpload($this->template, bulkFile(bulkCsv($this->work.'/lote.csv', bulkPdfRows(3)))));
    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'send'])->assertSessionHasNoErrors();

    // Simula a queda entre a criação e o envio: envelope criado, linha sem destino.
    $row = BulkGenerationRow::query()->where('bulk_generation_id', $batch->id)->orderBy('row_index')->first();
    $row->forceFill(['status' => BulkRowStatus::Processing])->save();
    $batch->refresh()->forceFill(['options' => ['mode' => 'review', 'scheduled_for' => null]])->save();
    app(RowGenerator::class)->handle($row->id);
    $row->refresh()->forceFill(['outcome' => null])->save();
    $batch->refresh()->forceFill(['options' => ['mode' => 'send', 'scheduled_for' => null]])->save();

    $this->post(route('bulk_generations.cancel', $batch))->assertSessionHasNoErrors();

    app(RowGenerator::class)->handle($row->id);
    $row->refresh();

    expect($row->outcome)->toBe(BulkRowOutcome::Ready)
        ->and($row->outcome_message)->toBe('Lote cancelado antes do envio; o documento ficou para revisão.')
        ->and(Envelope::query()->sole()->status)->toBe(EnvelopeStatus::Ready);
});

test('só um lote em geração pode ser cancelado', function () {
    $batch = bulkValidate(bulkUpload($this->template, bulkFile(bulkCsv($this->work.'/lote.csv', bulkPdfRows(1)))));

    $this->post(route('bulk_generations.cancel', $batch))
        ->assertSessionHasErrors(['batch' => 'Só é possível cancelar um lote em geração.']);

    expect($batch->fresh()->status)->toBe(BulkGenerationStatus::Validated);
});

test('lote concluído não é cancelado e os envelopes ficam como estão', function () {
    $batch = bulkValidate(bulkUpload($this->template, bulkFile(bulkCsv($this->work.'/lote.csv', bulkPdfRows(2)))));
    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'review'])->assertSessionHasNoErrors();

    expect($batch->fresh()->status)->toBe(BulkGenerationStatus::Completed);

    $this->post(route('bulk_generations.cancel', $batch))->assertSessionHasErrors('batch');

    expect(Envelope::query()->count())->toBe(2)
        ->and(BulkGenerationRow::query()->where('outcome', BulkRowOutcome::Ready->value)->count())->toBe(2);
});
