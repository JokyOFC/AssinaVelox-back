<?php

use App\Enums\FieldType;
use App\Events\EnvelopeReadyForFinalization;
use App\Services\Identity\CapturePurge;
use App\Services\Identity\IdentityCaptures;
use App\Services\Identity\Models\IdentityCapture;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../../Phase2/Identity/Support/IdentityHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da Fase 2, onda B — "apagadas 0 dias depois" com a retenção desligada
|--------------------------------------------------------------------------
| `assinavelox.capture.retention_days = 0` DESLIGA a exclusão das fotos vinculadas a aceite
| (config/assinavelox.php:159; CapturePurge::run(), `if ($retentionDays > 0)`): rosto e
| documento ficam guardados para sempre. O aviso de privacidade monta a frase com o mesmo
| número (ConsentText::withWaveBNotice(), app/Services/Signing/ConsentText.php:609-611) e
| promete ao participante que as fotos serão "apagadas 0 dias depois". A etapa de captura
| recebe `retention_days: 0`. Dado sensível (tratado como tal pela doc, §5) guardado sem prazo
| sob a promessa de exclusão imediata.
*/

beforeEach(function () {
    $this->work = storage_path('app/tmp/tests/'.Str::ulid());
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
    config()->set('inertia.ssr.enabled', false);
    Http::preventStrayRequests();
    Event::fake([EnvelopeReadyForFinalization::class]);
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('com a retenção desligada, o aviso promete apagar as fotos "0 dias depois" e elas nunca são apagadas', function () {
    config()->set('assinavelox.capture.retention_days', 0);

    $ctx = signerEnvelope([
        ['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'fields' => [FieldType::Signature]],
    ]);
    identityEnableFlags($ctx['organization'], ['identity_capture']);
    app(IdentityCaptures::class)->setRequirement($ctx['envelope'], $ctx['recipients']['maria@exemplo.test'], ['selfie', 'document_front'], $ctx['owner']);

    $token = $ctx['tokens']['maria@exemplo.test'];

    $identify = $this->get(route('sign.show', ['token' => $token]))->viewData('page')['props'];
    $notice = (string) $identify['privacy']['notice'];

    $props = authenticateSigner($this, $token);
    identityPostCapture($this, $token, 'selfie', identityPng())->assertCreated();
    identityPostCapture($this, $token, 'document_front', identityPng(), 'doc.png')->assertCreated();
    identityAccept($this, $token, $props)->assertSessionHasNoErrors();

    // Um ano e meio depois, a limpeza não apaga nada.
    expect(app(CapturePurge::class)->run(now()->addDays(540)))->toBe(['expired' => 0, 'orphans' => 0]);

    $capture = IdentityCapture::withoutOrganizationScope()->where('kind', 'selfie')->sole();
    expect(Storage::disk('documents')->exists((string) $capture->storage_path))->toBeTrue();

    expect($notice)->not->toMatch('/apagadas 0 dias depois/u', 'Aviso exibido: '.$notice);
});
