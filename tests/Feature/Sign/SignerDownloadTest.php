<?php

use App\Enums\AuditEventType;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Models\AuditEvent;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/SignerHelpers.php';

/*
|--------------------------------------------------------------------------
| Comprovante e cópia do arquivo final (ROUTES §1.3 sign.download)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/signer-download-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

/**
 * Assina de verdade, pelo caminho público, e devolve o contexto do envelope.
 *
 * @return array<string, mixed>
 */
function signAsMaria(object $test): array
{
    $ctx = signerEnvelope();
    $token = $ctx['tokens']['maria@exemplo.test'];
    $props = authenticateSigner($test, $token);

    $test->post(route('sign.complete', ['token' => $token]), [
        'authorization' => $props['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ])->assertRedirect();

    return $ctx + ['token' => $token];
}

it('entrega o comprovante do aceite depois de assinar, sem prometer o que não existe', function () {
    $ctx = signAsMaria($this);

    $response = $this->get(route('sign.download', ['token' => $ctx['token'], 'type' => 'evidence']));

    $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Content-Type'))->toContain('text/plain')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment');

    $texto = $response->getContent();

    expect($texto)->toContain('COMPROVANTE DE ACEITE ELETRÔNICO')
        ->toContain('Maria Alves Souza')
        ->toContain($ctx['version']->sha256)
        ->toContain('Contrato de locação')
        // O que a plataforma NÃO afirma.
        ->toContain('NÃO é a página de evidências do documento')
        ->toContain('Um resumo SHA-256 não')
        ->toContain('ainda não publicado')
        ->not->toContain('assinado digitalmente')
        // E-mail só mascarado.
        ->not->toContain('maria@exemplo.test');

    expect(AuditEvent::query()
        ->where('event_type', AuditEventType::EnvelopeDownloaded->value)
        ->exists())->toBeTrue();
});

it('não entrega comprovante a quem ainda não assinou', function () {
    $ctx = signerEnvelope();

    $this->get(route('sign.download', ['token' => $ctx['tokens']['maria@exemplo.test'], 'type' => 'evidence']))
        ->assertNotFound();
});

it('recusa o PDF final enquanto o envelope não estiver concluído', function () {
    $ctx = signAsMaria($this);

    $this->get(route('sign.download', ['token' => $ctx['token'], 'type' => 'signed']))
        ->assertNotFound();
});

it('entrega o PDF final quando o envelope conclui e a versão final existe', function () {
    $ctx = signAsMaria($this);

    $bytes = signerPdfBytes('final');
    $ulid = (string) Str::ulid();
    $path = sprintf('orgs/%s/envelopes/%s/%s.pdf', $ctx['organization']->ulid, $ctx['envelope']->ulid, $ulid);
    Storage::disk('documents')->put($path, $bytes);

    $final = DocumentVersion::factory()->forDocument($ctx['version']->document)->create([
        'ulid' => $ulid,
        'version_number' => 9,
        'kind' => DocumentVersionKind::Final,
        'storage_path' => $path,
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen($bytes),
        'sha256' => hash('sha256', $bytes),
    ]);

    $ctx['envelope']->forceFill([
        'status' => EnvelopeStatus::Finalizing,
    ])->save();

    $ctx['envelope']->refresh()->forceFill([
        'status' => EnvelopeStatus::Completed,
        'completed_at' => now(),
        'final_document_version_id' => $final->id,
    ])->save();

    $response = $this->get(route('sign.download', ['token' => $ctx['token'], 'type' => 'signed']));

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('attachment');

    // E o comprovante agora publica o resumo do arquivo final.
    $texto = $this->get(route('sign.download', ['token' => $ctx['token'], 'type' => 'evidence']))->getContent();
    expect($texto)->toContain($final->sha256);
});

it('não entrega arquivo nenhum para quem só tem o link e nunca confirmou identidade', function () {
    $ctx = signerEnvelope();
    $token = $ctx['tokens']['maria@exemplo.test'];

    $this->get(route('sign.download', ['token' => $token, 'type' => 'evidence']))->assertNotFound();
    $this->get(route('sign.download', ['token' => $token, 'type' => 'signed']))->assertNotFound();
    $this->get(route('sign.document', ['token' => $token]))->assertNotFound();
});
