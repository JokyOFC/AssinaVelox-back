<?php

use App\Models\BulkGenerationRow;
use App\Models\Subscription;
use App\Services\BulkGeneration\BulkRowOutcome;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../../Phase3/Bulk/Support/BulkHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial F-BULK — a reserva da confirmação não chega ao envio
|--------------------------------------------------------------------------
| BulkGenerationManager::confirm reserva UMA unidade por linha válida ("a cota é reservada só
| para 4"). No modo "enviar ao gerar", RowGenerator::deliver DEVOLVE a unidade do lote
| (`releaseRow`) e só depois o SendEnvelope reserva a própria, em outra transação. Nesse
| intervalo a unidade volta a ser livre: outro envio da organização (outro usuário, outro lote,
| a API) pode tomá-la, e a linha confirmada termina "não enviada" por falta de cota.
|
| O ouvinte abaixo simula exatamente esse envio concorrente: quando a primeira unidade do lote
| volta (`envelopes_reserved` diminui), outro envio consome uma unidade.
*/

beforeEach(function () {
    $this->withoutVite();
    templatesRequirePdftool();
    $this->work = templatesWorkspace();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    bulkEnable($this->organization);
    actingAsMember($this->owner, $this->organization);
    $this->template = bulkPdfTemplate($this->organization, $this->owner, $this->work);
});

afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

test('uma linha com cota reservada na confirmação é enviada mesmo com outro envio da organização acontecendo durante o lote', function () {
    Mail::fake();
    Notification::fake();
    bulkQuota($this->organization, 2);

    $batch = bulkValidate(bulkUpload($this->template, bulkFile(bulkCsv($this->work.'/lote.csv', bulkPdfRows(2)))));

    expect($batch->valid_count)->toBe(2);

    $concurrentSend = false;

    Subscription::updated(function (Subscription $subscription) use (&$concurrentSend): void {
        if ($concurrentSend
            || ! $subscription->wasChanged('envelopes_reserved')
            || (int) $subscription->envelopes_reserved >= (int) $subscription->getOriginal('envelopes_reserved')) {
            return;
        }

        // Outro envio da organização, fora do lote, entra no intervalo entre a devolução da
        // unidade do lote e a reserva do SendEnvelope da linha.
        $concurrentSend = true;
        Subscription::withoutOrganizationScope()->whereKey($subscription->getKey())->increment('envelopes_used');
    });

    $this->post(route('bulk_generations.confirm', $batch), ['mode' => 'send'])->assertSessionHasNoErrors();

    // `outcome` tem cast de enum: compara pelo valor (sem o map, nem [sent, sent] passaria).
    $outcomes = BulkGenerationRow::query()->where('bulk_generation_id', $batch->id)->orderBy('row_index')->get()
        ->map(fn (BulkGenerationRow $row): ?string => $row->outcome?->value)->all();

    expect($concurrentSend)->toBeTrue()
        // As duas linhas tinham a unidade reservada na confirmação: as duas deveriam sair.
        // Hoje a primeira termina `not_sent` ("cota"), porque a reserva foi devolvida antes do envio.
        ->and($outcomes)->toBe([BulkRowOutcome::Sent->value, BulkRowOutcome::Sent->value]);
});
