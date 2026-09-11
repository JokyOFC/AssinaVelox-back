<?php

use App\Enums\AuditEventType;
use App\Enums\FieldType;
use App\Events\EnvelopeReadyForFinalization;
use App\Models\AuditEvent;
use App\Models\SignatureAcceptance;
use App\Services\Identity\CaptureEvidence;
use App\Services\Identity\CapturePurge;
use App\Services\Identity\CaptureStep;
use App\Services\Identity\IdentityCaptures;
use App\Services\Identity\Models\IdentityCapture;
use App\Services\Signing\SignerContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../Domain/Support/DomainHelpers.php';
require_once __DIR__.'/Support/IdentityHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 2 §2.10 (C-ID) — captura SIMPLES de foto do rosto e do documento
|--------------------------------------------------------------------------
| Exigência por participante, bloqueio do aceite, normalização com GD (sem EXIF/GPS),
| armazenamento cifrado por organização, vínculo ao aceite, isolamento, Permissions-Policy,
| vocabulário da evidência e retenção. Nada de biometria.
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

/**
 * Envelope enviado, flag ligada e foto exigida da primeira pessoa.
 *
 * @param  list<string>  $kinds
 * @return array<string, mixed>
 */
function captureEnvelope(array $kinds = ['selfie']): array
{
    $ctx = signerEnvelope([
        ['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'fields' => [FieldType::Signature]],
        ['name' => 'Henrique Dias', 'email' => 'henrique@exemplo.test', 'fields' => [FieldType::Signature], 'order' => 1],
    ]);

    identityEnableFlags($ctx['organization'], ['identity_capture']);
    app(IdentityCaptures::class)->setRequirement($ctx['envelope'], $ctx['recipients']['maria@exemplo.test'], $kinds, $ctx['owner']);

    return $ctx + ['token' => $ctx['tokens']['maria@exemplo.test']];
}

function captureDiskFiles(): array
{
    return array_values(array_filter(Storage::disk('documents')->allFiles(), fn (string $path): bool => str_contains($path, '/identity/')));
}

it('o remetente exige fotos no rascunho; fora do rascunho, de outra organização ou sem flag não', function () {
    $ctx = domainEnvelope(['Contrato'], [
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
    ], sent: false);

    identityEnableFlags($ctx['organization'], ['identity_capture']);
    actingAsMember($ctx['owner'], $ctx['organization']);

    $recipient = $ctx['recipients']['maria@exemplo.test'];
    $url = route('envelopes.recipients.identity_capture', ['envelope' => $ctx['envelope']->ulid, 'recipient' => $recipient->ulid]);

    $this->putJson($url, ['kinds' => ['document_front', 'selfie']])->assertOk()->assertJsonPath('kinds', ['selfie', 'document_front']);
    $this->putJson($url, ['kinds' => ['document_back']])->assertStatus(422);
    $this->putJson($url, ['kinds' => ['impressao_digital']])->assertStatus(422);

    expect(app(IdentityCaptures::class)->requirementsForEnvelope($ctx['envelope']))->toBe([$recipient->ulid => ['selfie', 'document_front']]);

    $event = AuditEvent::query()->withoutGlobalScopes()->where('event_type', AuditEventType::IdentityCaptureRequirementUpdated->value)->firstOrFail();
    expect($event->payload)->toBe(['recipient_ulid' => $recipient->ulid, 'kinds' => ['selfie', 'document_front']]);

    // Outra organização: 404 pelo binding escopado.
    ['organization' => $other, 'owner' => $otherOwner] = createOrganizationWithOwner();
    identityEnableFlags($other, ['identity_capture']);
    actingAsMember($otherOwner, $other);
    $this->putJson($url, ['kinds' => ['selfie']])->assertNotFound();

    // Depois do envio a lista está congelada.
    actingAsMember($ctx['owner'], $ctx['organization']);
    $ctx['envelope']->forceFill(['status' => 'in_progress', 'sent_at' => now()])->save();
    $this->putJson($url, ['kinds' => []])->assertStatus(422);

    // Flag desligada: a rota não existe para a organização.
    config()->set('assinavelox.features.identity_capture', false);
    $this->putJson($url, ['kinds' => []])->assertNotFound();
});

it('a foto exigida bloqueia o aceite até existir e depois fica vinculada a ele', function () {
    $ctx = captureEnvelope(['selfie', 'document_front']);
    $token = $ctx['token'];
    $props = authenticateSigner($this, $token);

    identityAccept($this, $token, $props)->assertSessionHasErrors('signature');
    expect(session('errors')->first('signature'))->toContain('Foto do rosto')->toContain('Foto do documento (frente)');

    identityPostCapture($this, $token, 'selfie', identityJpegWithGps())->assertCreated()
        ->assertJsonPath('identity_capture.complete', false);

    identityAccept($this, $token, $props)->assertSessionHasErrors('signature');
    expect(SignatureAcceptance::withoutOrganizationScope()->count())->toBe(0);

    identityPostCapture($this, $token, 'document_front', identityPng(), 'doc.png')->assertCreated()
        ->assertJsonPath('identity_capture.complete', true);

    identityAccept($this, $token, $props)->assertSessionHasNoErrors();

    $acceptance = SignatureAcceptance::withoutOrganizationScope()->firstOrFail();
    $captures = IdentityCapture::withoutOrganizationScope()->orderBy('id')->get();

    expect($captures)->toHaveCount(2)
        ->and($captures->every(fn (IdentityCapture $capture): bool => $capture->signature_acceptance_id === $acceptance->getKey()))->toBeTrue()
        ->and(array_column($acceptance->fields_snapshot['identity_captures'], 'kind'))->toBe(['selfie', 'document_front'])
        ->and($acceptance->fields_snapshot['identity_captures'][0]['sha256'])->toBe($captures[0]->sha256)
        ->and(json_encode($acceptance->fields_snapshot))->not->toContain('identity/');

    // Fotos antes do código não existem: sem sessão, o envio volta para a página.
    $this->flushSession();
    $this->post(route('sign.capture.store', ['token' => $ctx['tokens']['henrique@exemplo.test'], 'kind' => 'selfie']), ['image' => identityUpload(identityPng())])
        ->assertRedirect();
});

it('normaliza: remove EXIF/GPS e metadados, limita a dimensão e grava cifrado no disco da organização', function () {
    config()->set('assinavelox.capture.output_max_side', 800);
    $ctx = captureEnvelope(['selfie', 'document_front']);
    authenticateSigner($this, $ctx['token']);

    $raw = identityJpegWithGps(1600, 1200);
    expect($raw)->toContain('GPSLatitude')->toContain('CameraDeTeste');

    identityPostCapture($this, $ctx['token'], 'selfie', $raw)->assertCreated();

    $capture = IdentityCapture::withoutOrganizationScope()->where('kind', 'selfie')->firstOrFail();
    $stored = Storage::disk('documents')->get($capture->storage_path);
    $plain = Crypt::decryptString($stored);
    $size = getimagesizefromstring($plain);

    expect($capture->storage_path)->toStartWith(sprintf('orgs/%s/envelopes/%s/identity/selfie-', $ctx['organization']->ulid, $ctx['envelope']->ulid))
        ->and(str_starts_with($stored, "\xFF\xD8"))->toBeFalse()
        ->and($stored)->not->toContain('GPSLatitude')
        ->and(str_starts_with($plain, "\xFF\xD8"))->toBeTrue()
        ->and($plain)->not->toContain('Exif')
        ->and($plain)->not->toContain('GPSLatitude')
        ->and($plain)->not->toContain('CameraDeTeste')
        ->and([$size[0], $size[1]])->toBe([800, 600])
        ->and([$capture->width, $capture->height])->toBe([800, 600])
        ->and($capture->mime_type)->toBe('image/jpeg')
        ->and(hash('sha256', $plain))->toBe($capture->sha256);

    // PNG com chunk de texto: reencodado como JPEG, sem o texto.
    identityPostCapture($this, $ctx['token'], 'document_front', pngWithTextChunk(identityPng(), 'Comment', 'segredo-no-metadado'), 'doc.png')->assertCreated();

    $document = IdentityCapture::withoutOrganizationScope()->where('kind', 'document_front')->firstOrFail();
    $docPlain = Crypt::decryptString(Storage::disk('documents')->get($document->storage_path));

    expect($docPlain)->not->toContain('segredo-no-metadado')
        ->and(str_starts_with($docPlain, "\xFF\xD8"))->toBeTrue();

    // A trilha registra o fato, nunca a imagem nem o caminho.
    $trail = identityAuditPayloads();
    expect($trail)->toContain('identity_capture.recorded')
        ->and($trail)->not->toContain('identity/')
        ->and($trail)->not->toContain(base64_encode(substr($plain, 0, 48)));
});

it('recusa SVG, arquivo que não é imagem, imagem gigante e arquivo grande demais', function () {
    $ctx = captureEnvelope();
    authenticateSigner($this, $ctx['token']);

    identityPostCapture($this, $ctx['token'], 'selfie', identitySvg(), 'foto.svg')
        ->assertStatus(422)->assertJsonPath('code', 'unsupported_image')->assertJsonPath('message', 'Imagens SVG não são aceitas. Envie uma foto em JPEG ou PNG.');

    identityPostCapture($this, $ctx['token'], 'selfie', signerPdfBytes('nao-e-foto'), 'foto.jpg')
        ->assertStatus(422)->assertJsonPath('code', 'unsupported_image');

    identityPostCapture($this, $ctx['token'], 'selfie', 'isto não é uma imagem', 'foto.png')
        ->assertStatus(422);

    identityPostCapture($this, $ctx['token'], 'selfie', identityGiantPng(), 'gigante.png')
        ->assertStatus(422)->assertJsonPath('code', 'image_too_large');

    config()->set('assinavelox.capture.max_upload_kb', 64);
    identityPostCapture($this, $ctx['token'], 'selfie', random_bytes(70 * 1024), 'grande.jpg')
        ->assertStatus(422)->assertJsonValidationErrors('image');

    // Tipo não exigido desta pessoa: não se coleta.
    identityPostCapture($this, $ctx['token'], 'document_front', identityPng(), 'doc.png')->assertNotFound();

    expect(IdentityCapture::withoutOrganizationScope()->count())->toBe(0)
        ->and(captureDiskFiles())->toBe([]);
});

it('refazer a foto antes do aceite substitui a anterior e apaga o arquivo antigo', function () {
    $ctx = captureEnvelope();
    authenticateSigner($this, $ctx['token']);

    identityPostCapture($this, $ctx['token'], 'selfie', identityPng(300, 300), 'a.png')->assertCreated();
    $first = IdentityCapture::withoutOrganizationScope()->firstOrFail();

    identityPostCapture($this, $ctx['token'], 'selfie', identityPng(500, 400), 'b.png')->assertCreated();

    expect(IdentityCapture::withoutOrganizationScope()->count())->toBe(1)
        ->and(IdentityCapture::withoutOrganizationScope()->first()->width)->toBe(500)
        ->and(Storage::disk('documents')->exists($first->storage_path))->toBeFalse()
        ->and(captureDiskFiles())->toHaveCount(1);
});

it('a imagem de outra organização nunca é acessível', function () {
    $a = captureEnvelope();
    $propsA = authenticateSigner($this, $a['token']);
    identityPostCapture($this, $a['token'], 'selfie', identityPng())->assertCreated();
    identityAccept($this, $a['token'], $propsA)->assertSessionHasNoErrors();

    $this->flushSession();

    $b = captureEnvelope();
    $propsB = authenticateSigner($this, $b['token']);
    identityPostCapture($this, $b['token'], 'selfie', identityJpegWithGps())->assertCreated();
    identityAccept($this, $b['token'], $propsB)->assertSessionHasNoErrors();

    $captureA = IdentityCapture::withoutOrganizationScope()->where('organization_id', $a['organization']->getKey())->firstOrFail();
    $captureB = IdentityCapture::withoutOrganizationScope()->where('organization_id', $b['organization']->getKey())->firstOrFail();

    expect($captureB->storage_path)->toStartWith('orgs/'.$b['organization']->ulid.'/');

    // Linha adulterada: foto da organização B apontando para o envelope de A.
    $captureB->forceFill(['envelope_id' => $a['envelope']->getKey()])->save();

    $evidenceA = app(CaptureEvidence::class)->forEnvelope($a['envelope']);
    $ids = collect($evidenceA['recipients'])->flatten(1)->pluck('id')->all();

    expect($ids)->toBe([$captureA->ulid]);

    // O escopo de organização do app também esconde a foto alheia.
    actingAsMember($a['owner'], $a['organization']);
    $this->get(route('envelopes.evidence', ['envelope' => $b['envelope']->ulid]))->assertNotFound();
    $this->putJson(route('envelopes.recipients.identity_capture', [
        'envelope' => $b['envelope']->ulid,
        'recipient' => $b['recipients']['maria@exemplo.test']->ulid,
    ]), ['kinds' => []])->assertNotFound();
});

it('a Permissions-Policy só libera a câmera nas rotas públicas de captura', function () {
    $ctx = captureEnvelope();
    $camera = fn ($response): string => (string) $response->headers->get('Permissions-Policy');

    // Página de quem tem foto exigida: câmera liberada, o resto continua negado.
    $page = $this->get(route('sign.show', ['token' => $ctx['token']]))->assertOk();
    expect($camera($page))->toContain('camera=(self)')
        ->and($camera($page))->not->toContain('camera=()')
        ->and($camera($page))->toContain('microphone=()')
        ->and($camera($page))->toContain('geolocation=()');

    // Outra pessoa do mesmo envelope, sem exigência: negada.
    $other = $this->get(route('sign.show', ['token' => $ctx['tokens']['henrique@exemplo.test']]))->assertOk();
    expect($camera($other))->toContain('camera=()');

    // Envio da foto: liberada. Tipo não exigido (404): negada.
    authenticateSigner($this, $ctx['token']);
    expect($camera(identityPostCapture($this, $ctx['token'], 'selfie', identityPng())->assertCreated()))->toContain('camera=(self)');
    expect($camera(identityPostCapture($this, $ctx['token'], 'document_back', identityPng())->assertNotFound()))->toContain('camera=()');

    // Demais rotas: negada.
    foreach ([route('home'), route('verify.index'), route('sign.document', ['token' => $ctx['token']])] as $url) {
        expect($camera($this->get($url)))->toContain('camera=()');
    }

    actingAsMember($ctx['owner'], $ctx['organization']);
    expect($camera($this->get(route('dashboard'))->assertOk()))->toContain('camera=()');

    // Flag desligada: a própria página do participante volta a negar.
    config()->set('assinavelox.features.identity_capture', false);
    expect($camera($this->get(route('sign.show', ['token' => $ctx['token']]))))->toContain('camera=()');
});

it('a evidência só diz "imagem enviada pelo participante", com a origem declarada, e nega verificação de identidade', function () {
    $ctx = captureEnvelope(['selfie']);
    $props = authenticateSigner($this, $ctx['token']);

    $step = app(CaptureStep::class)->props(
        new SignerContext($ctx['token'], $ctx['links']['maria@exemplo.test'], $ctx['recipients']['maria@exemplo.test'], $ctx['envelope'], $ctx['organization'], SignerContext::STATE_ACTIVE),
        null,
    );

    identityPostCapture($this, $ctx['token'], 'selfie', identityJpegWithGps())->assertCreated();
    identityAccept($this, $ctx['token'], $props)->assertSessionHasNoErrors();

    $evidence = app(CaptureEvidence::class)->forEnvelope($ctx['envelope']->fresh());
    $item = $evidence['recipients'][$ctx['recipients']['maria@exemplo.test']->ulid][0];

    expect($item['label'])->toBe('Imagem enviada pelo participante — origem informada pelo navegador: câmera')
        ->and($item['source'])->toBe('camera')
        ->and($item['kind_label'])->toBe('Foto do rosto')
        ->and($item['available'])->toBeTrue()
        ->and($item['thumbnail'])->toStartWith('data:image/jpeg;base64,')
        ->and($evidence['notice'])->toContain('Não houve verificação de identidade')
        ->and($step['items'][0]['upload_url'])->toBeNull()
        ->and($step['notice'])->toContain('não são usadas para verificar sua identidade');

    $labels = collect(AuditEventType::cases())
        ->filter(fn (AuditEventType $type): bool => str_starts_with($type->value, 'identity_capture.') || str_starts_with($type->value, 'cpf_lookup.'))
        ->map->label()->implode(' ');

    $texts = mb_strtolower(json_encode([$evidence['notice'], $item, $step, $labels], JSON_UNESCAPED_UNICODE));

    foreach (['identidade verificada', 'identidade confirmada', 'biometria', 'biométric', 'liveness', 'prova de vida', 'reconhecimento facial', 'face match', 'assinatura avançada', 'qualificada', 'reconhecimento de firma'] as $forbidden) {
        expect($texts)->not->toContain($forbidden);
    }

    // A verificação pública não fala de captura nem expõe o resumo da foto.
    $public = (string) $this->get(route('verify.show', ['code' => $ctx['envelope']->verification_code]))->assertOk()->getContent();
    // Integração (I-2B): as flags compartilhadas (`features`, com a chave `identity_capture`)
    // estão em toda página Inertia; o nome da chave não é conteúdo sobre captura.
    $public = (string) preg_replace('/(&quot;|")features\1:\{[^}]*\}/', '', $public);
    expect($public)->not->toContain($item['sha256'])
        ->and(mb_strtolower($public))->not->toContain('captur');
});

it('a retenção apaga a imagem e mantém o registro; foto sem aceite sai inteira', function () {
    config()->set('assinavelox.capture.retention_days', 180);
    config()->set('assinavelox.capture.orphan_retention_hours', 48);

    $ctx = captureEnvelope();
    $props = authenticateSigner($this, $ctx['token']);
    identityPostCapture($this, $ctx['token'], 'selfie', identityPng())->assertCreated();
    identityAccept($this, $ctx['token'], $props)->assertSessionHasNoErrors();

    $this->flushSession();

    // Foto de quem abandonou a assinatura (sem aceite).
    app(IdentityCaptures::class)->setRequirement($ctx['envelope'], $ctx['recipients']['henrique@exemplo.test'], ['selfie'], $ctx['owner']);
    authenticateSigner($this, $ctx['tokens']['henrique@exemplo.test']);
    identityPostCapture($this, $ctx['tokens']['henrique@exemplo.test'], 'selfie', identityPng())->assertCreated();

    $linked = IdentityCapture::withoutOrganizationScope()->whereNotNull('signature_acceptance_id')->firstOrFail();
    $orphan = IdentityCapture::withoutOrganizationScope()->whereNull('signature_acceptance_id')->firstOrFail();

    expect(app(CapturePurge::class)->run(now()->addHours(49)))->toBe(['expired' => 0, 'orphans' => 1]);
    expect(IdentityCapture::withoutOrganizationScope()->whereKey($orphan->getKey())->exists())->toBeFalse()
        ->and(Storage::disk('documents')->exists($orphan->storage_path))->toBeFalse();

    expect(app(CapturePurge::class)->run(now()->addDays(181)))->toBe(['expired' => 1, 'orphans' => 0]);

    $purged = $linked->fresh();
    $item = app(CaptureEvidence::class)->forEnvelope($ctx['envelope'])['recipients'][$ctx['recipients']['maria@exemplo.test']->ulid][0];

    expect($purged->storage_path)->toBeNull()
        ->and($purged->purged_at)->not->toBeNull()
        ->and($purged->sha256)->toBe($linked->sha256)
        ->and(Storage::disk('documents')->exists($linked->storage_path))->toBeFalse()
        ->and($item['available'])->toBeFalse()
        ->and($item['thumbnail'])->toBeNull()
        ->and(captureDiskFiles())->toBe([]);

    $reasons = AuditEvent::query()->withoutGlobalScopes()
        ->where('event_type', AuditEventType::IdentityCapturePurged->value)
        ->get()->map(fn (AuditEvent $event): array => $event->payload)->all();

    expect($reasons)->toEqualCanonicalizing([['count' => 1, 'reason' => 'orphan'], ['count' => 1, 'reason' => 'retention']]);
});
