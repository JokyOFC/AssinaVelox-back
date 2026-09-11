<?php

use App\Enums\FieldType;
use App\Events\EnvelopeReadyForFinalization;
use App\Services\Identity\CaptureEvidence;
use App\Services\Identity\IdentityCaptures;
use App\Services\Identity\Models\IdentityCapture;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../../Phase2/Identity/Support/IdentityHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da Fase 2, onda B — arquivo da galeria vira "imagem capturada pelo participante"
|--------------------------------------------------------------------------
| A etapa de captura aceita tanto a câmera quanto um arquivo escolhido (`source` = `camera` |
| `upload`). A origem é gravada em `identity_captures.source`, mas a evidência do remetente
| (CaptureEvidence::forEnvelope(), app/Services/Identity/CaptureEvidence.php:64-77) descarta o
| campo e rotula TODA foto como "Imagem capturada pelo participante". Para um arquivo enviado
| — que pode ser uma foto de terceiro, baixada ou editada — a plataforma só sabe que alguém,
| com a sessão do participante, enviou aquele arquivo. "Capturada pelo participante" afirma uma
| autoria e uma captura que não foram provadas (T1: descrever o meio, não afirmar o que não se
| provou). O próprio aviso ao participante diz "imagens enviadas por você".
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

it('um arquivo escolhido da galeria aparece na evidência como "imagem capturada pelo participante", sem a origem', function () {
    $ctx = signerEnvelope([
        ['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'fields' => [FieldType::Signature]],
    ]);
    identityEnableFlags($ctx['organization'], ['identity_capture']);
    app(IdentityCaptures::class)->setRequirement($ctx['envelope'], $ctx['recipients']['maria@exemplo.test'], ['document_front'], $ctx['owner']);

    $token = $ctx['tokens']['maria@exemplo.test'];
    $props = authenticateSigner($this, $token);

    $this->post(
        route('sign.capture.store', ['token' => $token, 'kind' => 'document_front']),
        ['image' => identityUpload(identityPng(), 'baixada-da-internet.png'), 'source' => 'upload'],
        ['Accept' => 'application/json'],
    )->assertCreated();

    identityAccept($this, $token, $props)->assertSessionHasNoErrors();

    expect(IdentityCapture::withoutOrganizationScope()->sole()->source)->toBe('upload');

    $item = app(CaptureEvidence::class)->forEnvelope($ctx['envelope']->fresh())['recipients'][$ctx['recipients']['maria@exemplo.test']->ulid][0];

    $honest = array_key_exists('source', $item) || ! str_contains(mb_strtolower((string) $item['label']), 'capturada');

    expect($honest)->toBeTrue(sprintf(
        'Evidência de um upload diz "%s" e não informa a origem (chaves: %s).',
        $item['label'],
        implode(', ', array_keys($item)),
    ));
});
