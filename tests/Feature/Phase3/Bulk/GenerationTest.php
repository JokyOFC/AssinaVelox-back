<?php

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\PlanConsumptionStatus;
use App\Jobs\BulkGeneration\GenerateEnvelopeFromRowJob;
use App\Jobs\BulkGeneration\StartBulkGenerationJob;
use App\Models\AuditEvent;
use App\Models\BulkGenerationRow;
use App\Models\Envelope;
use App\Models\PlanConsumption;
use App\Services\BulkGeneration\BulkGenerationProgress;
use App\Services\BulkGeneration\BulkGenerationPump;
use App\Services\BulkGeneration\BulkGenerationQuota;
use App\Services\BulkGeneration\BulkGenerationStatus;
use App\Services\BulkGeneration\BulkRowOutcome;
use App\Services\BulkGeneration\BulkRowStatus;
use App\Services\BulkGeneration\RowGenerator;
use App\Services\Templates\TemplateManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/BulkHelpers.php';

/*
|--------------------------------------------------------------------------
| Geração: um envelope por linha válida, idempotente, com a versão do modelo fixada
|--------------------------------------------------------------------------
| Modelo PDF fixo com campo de assinatura para cada papel: o envelope nasce PRONTO.
*/

beforeEach(function () {
    $this->withoutVite();
    templatesRequirePdftool();
    $this->work = templatesWorkspace();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    bulkEnable($this->organization);
    bulkQuota($this->organization, null);
    actingAsMember($this->owner, $this->organization);
    $this->template = bulkPdfTemplate($this->organization, $this->owner, $this->work);
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

test('200 linhas geram 200 envelopes prontos, cada um com os participantes da sua linha', function () {
    $batch = bulkValidate(bulkUpload($this->template, bulkFile(bulkCsv($this->work.'/lote.csv', bulkPdfRows(200)))));

    expect($batch->valid_count)->toBe(200);

    $file = $batch->source_file;

    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'review'])->assertSessionHasNoErrors();

    $batch->refresh();
    $envelopes = Envelope::query()->with('recipients')->orderBy('id')->get();

    expect($batch->status)->toBe(BulkGenerationStatus::Completed)
        ->and($batch->created_count)->toBe(200)
        ->and($batch->failed_count)->toBe(0)
        ->and($batch->finished_at)->not->toBeNull()
        ->and($envelopes)->toHaveCount(200)
        ->and($envelopes->pluck('status')->unique()->all())->toBe([EnvelopeStatus::Ready])
        ->and(BulkGenerationRow::query()->where('outcome', BulkRowOutcome::Ready->value)->count())->toBe(200)
        ->and(BulkGenerationRow::query()->whereNotNull('payload')->count())->toBe(0);

    $row = BulkGenerationRow::query()->where('bulk_generation_id', $batch->id)->where('row_index', 51)->sole();
    $envelope = $envelopes->firstWhere('id', $row->envelope_id);

    expect($envelope->title)->toBe('Vistoria 50')
        ->and($envelope->recipients->pluck('email')->all())->toBe(['inquilino50@example.com', 'dono50@example.com'])
        ->and($envelope->recipients->pluck('role_label')->all())->toBe(['Locatário', 'Locador']);

    // Cota: tudo reservado na confirmação e devolvido quando os documentos nasceram para revisão.
    $subscription = $this->organization->currentSubscription()->first();

    expect(app(BulkGenerationQuota::class)->reservedFor($batch))->toBe(0)
        ->and($subscription->envelopes_reserved)->toBe(0)
        ->and($subscription->envelopes_used)->toBe(0)
        ->and(PlanConsumption::query()->where('status', PlanConsumptionStatus::Released->value)->count())->toBe(200);

    // Planilha fora do disco ao terminar; trilha no envelope com o lote e o usuário que confirmou.
    expect(Storage::disk('documents')->exists((string) $file))->toBeFalse()
        ->and($batch->source_file)->toBeNull();

    $created = AuditEvent::query()->where('envelope_id', $envelope->id)->where('event_type', AuditEventType::EnvelopeCreated->value)->sole();

    expect($created->payload['bulk_generation'])->toBe($batch->ulid)
        ->and($created->payload['bulk_row'])->toBe(51)
        ->and($created->actor_type)->toBe(ActorType::User)
        ->and($created->actor_id)->toBe($this->owner->id);
})->group('slow');

test('a versão do modelo fica fixada no envio da planilha', function () {
    $batch = bulkValidate(bulkUpload($this->template, bulkFile(bulkCsv($this->work.'/lote.csv', bulkPdfRows(2)))));
    $pinned = $batch->template_version_id;

    // O modelo ganha uma versão nova (mais um papel) depois do envio da planilha.
    app(TemplateManager::class)->update($this->template->fresh(), $this->owner, [
        'roles' => [
            ['ref' => 'locatario', 'name' => 'Locatário', 'participant_role' => 'signer'],
            ['ref' => 'locador', 'name' => 'Locador', 'participant_role' => 'signer'],
            ['ref' => 'fiador', 'name' => 'Fiador', 'participant_role' => 'signer'],
        ],
        'fields' => [],
        'signing_order' => 'parallel',
    ]);

    expect($this->template->fresh()->current_version_id)->not->toBe($pinned);

    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'review'])->assertSessionHasNoErrors();

    $usages = DB::table('template_usages')->pluck('template_version_id')->unique()->all();

    expect($usages)->toBe([$pinned])
        ->and(Envelope::query()->count())->toBe(2)
        ->and(Envelope::query()->first()->recipients()->count())->toBe(2);
});

test('enviar ao gerar: cada envelope pronto é enviado e a cota passa a ser a do envio', function () {
    Mail::fake();
    Notification::fake();
    bulkQuota($this->organization, 10);

    $batch = bulkValidate(bulkUpload($this->template, bulkFile(bulkCsv($this->work.'/lote.csv', bulkPdfRows(3)))));

    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'send'])->assertSessionHasNoErrors();

    $envelopes = Envelope::query()->get();

    expect($envelopes->pluck('status')->unique()->all())->toBe([EnvelopeStatus::InProgress])
        ->and(BulkGenerationRow::query()->where('outcome', BulkRowOutcome::Sent->value)->count())->toBe(3)
        ->and(app(BulkGenerationQuota::class)->reservedFor($batch->fresh()))->toBe(0);

    foreach ($envelopes as $envelope) {
        expect(PlanConsumption::query()->where('idempotency_key', "envelope:{$envelope->id}:send")->sole()->status)
            ->toBe(PlanConsumptionStatus::Committed);
    }

    expect($this->organization->currentSubscription()->first()->envelopes_used)->toBe(3);
});

test('agendar: cada envelope pronto recebe o envio agendado pelas regras do §2.5', function () {
    config()->set('assinavelox.features.reminders', true);
    bulkEnable($this->organization, ['reminders' => true]);

    $batch = bulkValidate(bulkUpload($this->template, bulkFile(bulkCsv($this->work.'/lote.csv', bulkPdfRows(2)))));
    $when = now()->setTimezone('America/Sao_Paulo')->addDays(2)->setTime(9, 30)->format('Y-m-d\TH:i');

    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'schedule', 'scheduled_for' => $when])->assertSessionHasNoErrors();

    $envelopes = Envelope::query()->get();

    expect($envelopes->pluck('status')->unique()->all())->toBe([EnvelopeStatus::Ready])
        ->and($envelopes->pluck('scheduled_send_at')->filter()->count())->toBe(2)
        ->and(BulkGenerationRow::query()->where('outcome', BulkRowOutcome::Scheduled->value)->count())->toBe(2);
});

test('agendar exige a flag de envio agendado e um horário válido', function () {
    Queue::fake();
    $batch = bulkValidate(bulkUpload($this->template, bulkFile(bulkCsv($this->work.'/lote.csv', bulkPdfRows(1)))));

    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'schedule', 'scheduled_for' => '2030-01-01T09:00'])
        ->assertSessionHasErrors(['mode' => 'O envio agendado não está disponível no plano atual da organização.']);

    config()->set('assinavelox.features.reminders', true);
    bulkEnable($this->organization, ['reminders' => true]);

    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'schedule', 'scheduled_for' => 'amanhã'])
        ->assertSessionHasErrors(['scheduled_for' => 'Informe a data e a hora do envio.']);

    expect($batch->fresh()->status)->toBe(BulkGenerationStatus::Validated)
        ->and(PlanConsumption::query()->count())->toBe(0);
});

test('idempotência: reexecutar o job de uma linha não duplica o envelope', function () {
    $batch = bulkValidate(bulkUpload($this->template, bulkFile(bulkCsv($this->work.'/lote.csv', bulkPdfRows(2)))));
    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'review'])->assertSessionHasNoErrors();

    $row = BulkGenerationRow::query()->where('bulk_generation_id', $batch->id)->orderBy('row_index')->first();

    app(RowGenerator::class)->handle($row->id);
    (new GenerateEnvelopeFromRowJob($row->id))->handle(app(RowGenerator::class));

    expect(Envelope::query()->count())->toBe(2)
        ->and($row->fresh()->envelope_id)->not->toBeNull()
        ->and(DB::table('template_usages')->count())->toBe(2);
});

test('queda no meio: linha presa em processamento é gerada uma única vez', function () {
    Queue::fake();
    $batch = bulkValidate(bulkUpload($this->template, bulkFile(bulkCsv($this->work.'/lote.csv', bulkPdfRows(2)))));
    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'review'])->assertSessionHasNoErrors();

    $row = BulkGenerationRow::query()->where('bulk_generation_id', $batch->id)->orderBy('row_index')->first();
    $row->forceFill(['status' => BulkRowStatus::Processing])->save(); // o worker caiu aqui

    app(RowGenerator::class)->handle($row->id);
    app(RowGenerator::class)->handle($row->id);

    expect(Envelope::query()->count())->toBe(1)
        ->and($row->fresh()->status)->toBe(BulkRowStatus::Created)
        ->and($row->fresh()->attempts)->toBe(1);
});

test('queda depois da criação: a reexecução só refaz o destino (envio), sem duplicar', function () {
    Mail::fake();
    Notification::fake();
    Queue::fake();

    $batch = bulkValidate(bulkUpload($this->template, bulkFile(bulkCsv($this->work.'/lote.csv', bulkPdfRows(1)))));
    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'send'])->assertSessionHasNoErrors();

    $row = BulkGenerationRow::query()->where('bulk_generation_id', $batch->id)->sole();
    $row->forceFill(['status' => BulkRowStatus::Processing])->save();

    // Simula a queda logo depois da criação: o envelope existe, a linha não tem destino.
    $batch->refresh()->forceFill(['options' => ['mode' => 'review', 'scheduled_for' => null]])->save();
    app(RowGenerator::class)->handle($row->id);
    $row->refresh()->forceFill(['outcome' => null])->save();
    $batch->refresh()->forceFill(['options' => ['mode' => 'send', 'scheduled_for' => null]])->save();

    expect(Envelope::query()->sole()->status)->toBe(EnvelopeStatus::Ready);

    app(RowGenerator::class)->handle($row->id);

    expect(Envelope::query()->count())->toBe(1)
        ->and($row->fresh()->outcome)->toBe(BulkRowOutcome::Sent)
        ->and(Envelope::query()->sole()->status)->toBe(EnvelopeStatus::InProgress);
});

test('limite de concorrência por organização: nunca mais linhas em geração do que o permitido', function () {
    Queue::fake();
    config()->set('assinavelox.bulk_generation.concurrency_per_organization', 3);

    $batch = bulkValidate(bulkUpload($this->template, bulkFile(bulkCsv($this->work.'/lote.csv', bulkPdfRows(10)))));
    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'review'])->assertSessionHasNoErrors();

    Queue::assertPushed(StartBulkGenerationJob::class);

    expect(app(BulkGenerationPump::class)->pump($this->organization->id))->toBe(3)
        ->and(app(BulkGenerationPump::class)->pump($this->organization->id))->toBe(0);

    Queue::assertPushed(GenerateEnvelopeFromRowJob::class, 3);

    expect(BulkGenerationRow::query()->where('status', BulkRowStatus::Queued->value)->count())->toBe(3)
        ->and(BulkGenerationRow::query()->where('status', BulkRowStatus::Pending->value)->count())->toBe(7);

    // Uma linha termina → libera uma vaga → a próxima entra na fila.
    $first = BulkGenerationRow::query()->where('status', BulkRowStatus::Queued->value)->orderBy('row_index')->first();
    app(RowGenerator::class)->handle($first->id);

    expect(BulkGenerationRow::query()->where('status', BulkRowStatus::Queued->value)->count())->toBe(3)
        ->and(BulkGenerationRow::query()->where('status', BulkRowStatus::Created->value)->count())->toBe(1);
});

test('modelo arquivado depois da confirmação: as linhas falham com motivo e devolvem a cota', function () {
    Queue::fake([StartBulkGenerationJob::class]);
    bulkQuota($this->organization, 10);

    $batch = bulkValidate(bulkUpload($this->template, bulkFile(bulkCsv($this->work.'/lote.csv', bulkPdfRows(3)))));
    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'review'])->assertSessionHasNoErrors();

    app(TemplateManager::class)->archive($this->template->fresh(), $this->owner);

    (new StartBulkGenerationJob($batch->id))->handle(app(BulkGenerationPump::class), app(BulkGenerationProgress::class));

    $batch->refresh();

    expect($batch->status)->toBe(BulkGenerationStatus::Completed)
        ->and($batch->failed_count)->toBe(3)
        ->and(BulkGenerationRow::query()->pluck('error')->unique()->all())->toBe(['O modelo foi arquivado depois da confirmação do lote.'])
        ->and(Envelope::query()->count())->toBe(0)
        ->and($this->organization->currentSubscription()->first()->envelopes_reserved)->toBe(0);
});

test('flag desligada no meio do lote: nenhuma linha é gerada e a cota volta', function () {
    Queue::fake([StartBulkGenerationJob::class]);
    bulkQuota($this->organization, 10);

    $batch = bulkValidate(bulkUpload($this->template, bulkFile(bulkCsv($this->work.'/lote.csv', bulkPdfRows(2)))));
    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'review'])->assertSessionHasNoErrors();

    config()->set('assinavelox.features.bulk_generation', false);
    app(BulkGenerationPump::class)->pump($this->organization->id);

    expect(Envelope::query()->count())->toBe(0)
        ->and(BulkGenerationRow::query()->where('status', BulkRowStatus::Failed->value)->count())->toBe(2)
        ->and($this->organization->currentSubscription()->first()->envelopes_reserved)->toBe(0);
});

test('modelo HTML: sem campos posicionados, "enviar ao gerar" deixa os documentos para revisão', function () {
    Mail::fake();
    $html = bulkHtmlTemplate($this->organization, $this->owner);
    $path = bulkCsv($this->work.'/html.csv', [
        ['Locatário — nome', 'Locatário — e-mail', 'Nome do imóvel', 'CPF do locatário', 'Valor do aluguel'],
        ['Ana Souza', 'ana@example.com', 'Casa', '529.982.247-25', '1.500,00'],
    ]);

    $batch = bulkValidate(bulkUpload($html, bulkFile($path)));
    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'send'])->assertSessionHasNoErrors();

    $row = BulkGenerationRow::query()->where('bulk_generation_id', $batch->id)->sole();

    expect($row->status)->toBe(BulkRowStatus::Created)
        ->and($row->outcome)->toBe(BulkRowOutcome::NotSent)
        ->and($row->outcome_message)->toStartWith('O documento precisa de revisão antes do envio')
        ->and(Envelope::query()->sole()->status->isDraftLike())->toBeTrue()
        ->and(app(BulkGenerationQuota::class)->reservedFor($batch->fresh()))->toBe(0);
});
