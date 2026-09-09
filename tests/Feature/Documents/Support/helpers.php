<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes do pipeline documental (B-DOC)
|--------------------------------------------------------------------------
| Incluído com require_once pelos arquivos de teste. Não contém testes.
*/

use App\Enums\EnvelopeStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Documents\Support\DocumentFixtures;

if (! function_exists('fakeDocumentsDisk')) {
    /**
     * Disco `documents` local com raiz EXCLUSIVA do teste.
     *
     * `Storage::fake()` usa uma raiz compartilhada (storage/framework/testing/disks/…) e
     * precisa limpá-la no início de cada teste. No Windows essa limpeza recursiva compete
     * com handles recém-liberados e falha de forma intermitente
     * (`FilesystemIterator: o sistema não pode encontrar o arquivo`), derrubando testes
     * que nada têm a ver com o problema. Uma raiz nova por teste resolve na origem: nada
     * precisa ser apagado antes de começar.
     */
    function fakeDocumentsDisk(string $root): void
    {
        config()->set('filesystems.disks.documents', [
            'driver' => 'local',
            'root' => $root,
            'visibility' => 'private',
            'serve' => false,
            'throw' => true,
            'report' => false,
        ]);

        Storage::forgetDisk('documents');
    }
}

if (! function_exists('cleanupDocumentsWorkspace')) {
    /**
     * Remove a área de trabalho do teste sem derrubar a suíte se o Windows ainda segurar
     * um handle (mesma tática de App\Services\Pdf\Support\TemporaryDirectory).
     */
    function cleanupDocumentsWorkspace(?string $path): void
    {
        if ($path === null || ! is_dir($path)) {
            return;
        }

        $filesystem = new Filesystem;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                if ($filesystem->deleteDirectory($path)) {
                    return;
                }
            } catch (Throwable) {
                // tenta de novo
            }

            usleep(50_000);
        }
    }
}

if (! function_exists('uploadDocument')) {
    /** Envia um arquivo pela rota real do wizard. */
    function uploadDocument(Envelope $envelope, string $path, string $clientName, ?string $clientMime = null): TestResponse
    {
        return test()->post(
            route('envelopes.document.store', ['envelope' => $envelope->ulid]),
            ['file' => DocumentFixtures::upload($path, $clientName, $clientMime)],
        );
    }
}

if (! function_exists('expectNothingPersisted')) {
    /** Nada é persistido nem gravado no disco quando o arquivo é recusado. */
    function expectNothingPersisted(Envelope $envelope): void
    {
        expect(Document::withoutOrganizationScope()->where('envelope_id', $envelope->id)->exists())->toBeFalse()
            ->and(DocumentVersion::withoutOrganizationScope()->count())->toBe(0)
            ->and(Storage::disk('documents')->allFiles())->toBe([])
            ->and($envelope->fresh()->status)->toBe(EnvelopeStatus::Draft);

        Queue::assertNothingPushed();
    }
}
