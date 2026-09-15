<?php

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Integrations\GoogleDrive\GoogleOAuthClient;
use App\Jobs\Documents\ProcessDocumentUpload;
use App\Models\AuditEvent;
use App\Models\CloudImport;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Services\CloudImport\CloudImporter;
use App\Services\CloudImport\CloudImportRejected;
use App\Services\CloudImport\CloudProvider;
use App\Services\CloudImport\Dto\CloudFileSelection;
use App\Services\CloudImport\GoogleImportSession;
use App\Services\CloudImport\SimulatedCloudFileSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support/ConnectorHelpers.php';

/*
|--------------------------------------------------------------------------
| Google Drive — importação pelo MESMO caminho de um upload (docs/fase-3/conectores.md §3, §4)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    Bus::fake([ProcessDocumentUpload::class]);
    Storage::fake('documents');
    connectorsDns();
    connectorsConfigure();
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    connectorsEnable($this->organization);
    actingAsMember($this->owner, $this->organization);
    $this->envelope = connectorsDraft($this->organization, $this->owner);
    $this->fileId = '1AbCdEfGhIjKlMn';
});

/**
 * @return list<string>
 */
function googleImportEvents(int $envelopeId): array
{
    return AuditEvent::query()->withoutGlobalScopes()->where('envelope_id', $envelopeId)->orderBy('id')->get()
        ->map(fn (AuditEvent $event): string => $event->event_type->value)
        ->all();
}

test('PDF do Drive entra como um upload: documento, hash, origem na trilha e quem importou', function () {
    $pdf = connectorsPdfBytes();
    connectorsFakeGoogle([$this->fileId => ['name' => 'Contrato.pdf', 'mimeType' => 'application/pdf', 'bytes' => $pdf]]);
    connectorsGoogleAuthorize($this, $this->envelope);

    $this->post(route('cloud_import.google.store', $this->envelope), ['file_ids' => [$this->fileId]])
        ->assertRedirect(route('envelopes.edit', $this->envelope))
        ->assertSessionHas('success');

    $document = Document::withoutOrganizationScope()->where('envelope_id', $this->envelope->getKey())->sole();
    $version = DocumentVersion::withoutOrganizationScope()->where('document_id', $document->getKey())->sole();
    expect($document->original_filename)->toBe('Contrato.pdf')
        ->and($version->sha256)->toBe(hash('sha256', $pdf));

    $import = CloudImport::withoutOrganizationScope()->sole();
    expect($import->status)->toBe(CloudImport::STATUS_COMPLETED)
        ->and($import->provider)->toBe(CloudProvider::GoogleDrive)
        ->and($import->external_id)->toBe($this->fileId)
        ->and($import->sha256)->toBe(hash('sha256', $pdf))
        ->and($import->document_id)->toBe($document->getKey())
        ->and($import->imported_by_user_id)->toBe($this->owner->getKey())
        ->and($import->simulated)->toBeFalse();

    expect(googleImportEvents($this->envelope->getKey()))->toContain('document.uploaded', 'cloud_import.completed');

    $payload = AuditEvent::query()->withoutGlobalScopes()
        ->where('envelope_id', $this->envelope->getKey())
        ->where('event_type', AuditEventType::CloudImportCompleted->value)
        ->sole()->payload;
    expect($payload['provider'])->toBe('google_drive')
        ->and($payload['external_id'])->toBe($this->fileId)
        ->and($payload['sha256'])->toBe(hash('sha256', $pdf));

    // Mesmo pipeline do upload: o processamento (PDF criptografado, assinado…) é o do upload.
    Bus::assertDispatched(ProcessDocumentUpload::class);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'alt=media')
        && $request->header('Authorization') === ['Bearer ya29.SENTINELA-GOOGLE-ACCESS']);

    // "Nenhum token além da importação": revogado no Google e fora da sessão.
    Http::assertSent(fn (Request $request): bool => $request->url() === GoogleOAuthClient::REVOKE_URL
        && $request->data()['token'] === 'ya29.SENTINELA-GOOGLE-ACCESS');
    expect(app(GoogleImportSession::class)->authorized($this->envelope, $this->owner))->toBeFalse();
});

test('executável com nome .pdf no Drive é recusado pela inspeção do upload, e o token some mesmo assim', function () {
    $exe = "MZ\x90\x00\x03\x00\x00\x00".str_repeat("\x00", 120).'This program cannot be run in DOS mode.';
    connectorsFakeGoogle([$this->fileId => ['name' => 'contrato.pdf', 'mimeType' => 'application/pdf', 'bytes' => $exe]]);
    connectorsGoogleAuthorize($this, $this->envelope);

    $this->post(route('cloud_import.google.store', $this->envelope), ['file_ids' => [$this->fileId]])
        ->assertRedirect(route('cloud_import.show', $this->envelope))
        ->assertSessionHasErrors('file');

    expect(Document::withoutOrganizationScope()->where('envelope_id', $this->envelope->getKey())->exists())->toBeFalse();

    $import = CloudImport::withoutOrganizationScope()->sole();
    expect($import->status)->toBe(CloudImport::STATUS_REJECTED)
        ->and($import->rejection_code)->toStartWith('upload_')
        ->and($import->document_id)->toBeNull();

    expect(googleImportEvents($this->envelope->getKey()))->toContain('cloud_import.rejected')
        ->not->toContain('document.uploaded');

    Http::assertSent(fn (Request $request): bool => $request->url() === GoogleOAuthClient::REVOKE_URL);
    expect(app(GoogleImportSession::class)->authorized($this->envelope, $this->owner))->toBeFalse();
});

test('imagem PNG com nome .pdf é recusada: a extensão não confere com o conteúdo', function () {
    connectorsFakeGoogle([$this->fileId => ['name' => 'contrato.pdf', 'mimeType' => 'application/pdf', 'bytes' => connectorsPngBytes()]]);
    connectorsGoogleAuthorize($this, $this->envelope);

    $this->post(route('cloud_import.google.store', $this->envelope), ['file_ids' => [$this->fileId]])
        ->assertSessionHasErrors('file');

    expect(CloudImport::withoutOrganizationScope()->sole()->rejection_code)->toBe('upload_extension_mismatch');
});

test('Documento do Google é exportado como PDF (files.export) e ganha a extensão certa', function () {
    connectorsFakeGoogle([$this->fileId => ['name' => 'Proposta comercial', 'mimeType' => 'application/vnd.google-apps.document', 'bytes' => connectorsPdfBytes()]]);
    connectorsGoogleAuthorize($this, $this->envelope);

    $this->post(route('cloud_import.google.store', $this->envelope), ['file_ids' => [$this->fileId]])
        ->assertRedirect(route('envelopes.edit', $this->envelope));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/'.$this->fileId.'/export?mimeType=application%2Fpdf'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'alt=media'));

    expect(Document::withoutOrganizationScope()->where('envelope_id', $this->envelope->getKey())->sole()->original_filename)
        ->toBe('Proposta comercial.pdf');
});

test('pasta do Drive é recusada sem download', function () {
    connectorsFakeGoogle([$this->fileId => ['name' => 'Contratos', 'mimeType' => 'application/vnd.google-apps.folder', 'bytes' => '']]);
    connectorsGoogleAuthorize($this, $this->envelope);

    $this->post(route('cloud_import.google.store', $this->envelope), ['file_ids' => [$this->fileId]])
        ->assertSessionHasErrors('file');

    expect(CloudImport::withoutOrganizationScope()->sole()->rejection_code)->toBe('unsupported_type');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'alt=media') || str_contains($request->url(), '/export'));
});

test('tamanho declarado acima do limite é recusado antes do download; corpo maior que o declarado também', function () {
    config()->set('assinavelox.upload.max_mb', 1);
    connectorsFakeGoogle([
        $this->fileId => ['name' => 'grande.pdf', 'mimeType' => 'application/pdf', 'bytes' => connectorsPdfBytes(), 'size' => 5 * 1024 * 1024],
        '2ZyXwVuTsRqPoNm' => ['name' => 'mentiroso.pdf', 'mimeType' => 'application/pdf', 'bytes' => connectorsPdfBytes().str_repeat('A', 1100 * 1024), 'size' => 100],
    ]);

    connectorsGoogleAuthorize($this, $this->envelope);
    $this->post(route('cloud_import.google.store', $this->envelope), ['file_ids' => [$this->fileId]])->assertSessionHasErrors('file');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'alt=media'));

    connectorsGoogleAuthorize($this, $this->envelope);
    $this->post(route('cloud_import.google.store', $this->envelope), ['file_ids' => ['2ZyXwVuTsRqPoNm']])->assertSessionHasErrors('file');

    expect(CloudImport::withoutOrganizationScope()->pluck('rejection_code')->all())->toBe(['file_too_large', 'file_too_large'])
        ->and(Document::withoutOrganizationScope()->count())->toBe(0);
});

test('sem autorização na sessão nada é chamado; id de arquivo malformado ou em excesso não passa da validação', function () {
    Http::fake();

    $this->post(route('cloud_import.google.store', $this->envelope), ['file_ids' => [$this->fileId]])
        ->assertRedirect(route('cloud_import.show', $this->envelope))
        ->assertSessionHas('error');

    $this->post(route('cloud_import.google.store', $this->envelope), ['file_ids' => ['../../etc/passwd']])
        ->assertSessionHasErrors('file_ids.0');

    // Sem `multi_document`, o envelope aceita um arquivo: dois de uma vez é recusado.
    $this->post(route('cloud_import.google.store', $this->envelope), ['file_ids' => [$this->fileId, '2ZyXwVuTsRqPoNm']])
        ->assertSessionHasErrors('file_ids');

    Http::assertNothingSent();
    expect(CloudImport::withoutOrganizationScope()->count())->toBe(0);
});

test('simulador identificado: a importação fica marcada como simulada na linha e na trilha', function () {
    $import = app(CloudImporter::class)->import(
        $this->envelope,
        $this->owner,
        new SimulatedCloudFileSource(CloudProvider::GoogleDrive, connectorsPdfBytes(), 'simulado.pdf'),
        new CloudFileSelection(CloudProvider::GoogleDrive, externalId: 'sim-000001'),
    );

    expect($import->simulated)->toBeTrue()
        ->and($import->status)->toBe(CloudImport::STATUS_COMPLETED);

    $payload = AuditEvent::query()->withoutGlobalScopes()
        ->where('event_type', AuditEventType::CloudImportCompleted->value)
        ->sole()->payload;
    expect($payload['simulated'])->toBeTrue();
});

test('envelope que já saiu da preparação não aceita importação', function () {
    $this->envelope->forceFill(['status' => EnvelopeStatus::InProgress])->save();

    expect(fn () => app(CloudImporter::class)->import(
        $this->envelope,
        $this->owner,
        new SimulatedCloudFileSource(CloudProvider::GoogleDrive, connectorsPdfBytes()),
        new CloudFileSelection(CloudProvider::GoogleDrive),
    ))->toThrow(CloudImportRejected::class);

    expect(CloudImport::withoutOrganizationScope()->count())->toBe(0);
});
