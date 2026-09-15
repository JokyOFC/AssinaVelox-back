<?php

use App\Enums\AuditEventType;
use App\Enums\FieldType;
use App\Events\EnvelopeReadyForFinalization;
use App\Models\AuditEvent;
use App\Models\SignatureAcceptance;
use App\Services\Identity\IdentityCaptures;
use App\Services\Identity\IdentityFeatures;
use App\Services\Identity\IdentityVideos;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../../Phase2/Domain/Support/DomainHelpers.php';
require_once __DIR__.'/../../Phase2/Identity/Support/IdentityHelpers.php';
require_once __DIR__.'/Support/VideoHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 3 §3.3 (F-VIDEO) — exigência do vídeo curto e convivência com as fotos
|--------------------------------------------------------------------------
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

it('a flag nasce desligada e fica fora do contrato das quatro flags de identidade', function () {
    ['organization' => $organization] = createOrganizationWithOwner();

    expect(config('assinavelox.features.identity_video'))->toBeFalse()
        ->and(IdentityFeatures::identityVideo($organization))->toBeFalse()
        ->and(IdentityFeatures::forOrganization($organization))->not->toHaveKey('identity_video');

    // Plano liga, interruptor global desligado: continua desligada (T8).
    $plan = $organization->currentSubscription()->with('plan')->first()->plan;
    $plan->forceFill(['features' => array_merge((array) $plan->features, ['identity_video' => true])])->save();

    expect(IdentityFeatures::identityVideo($organization->fresh()))->toBeFalse();

    // Interruptor global liga, plano não: desligada.
    config()->set('assinavelox.features.identity_video', true);
    $plan->forceFill(['features' => array_merge((array) $plan->features, ['identity_video' => false])])->save();

    expect(IdentityFeatures::identityVideo($organization->fresh()))->toBeFalse();
});

it('o remetente exige vídeo só no rascunho, com duração entre 3 s e o teto; outra organização e flag desligada 404', function () {
    $ctx = domainEnvelope(['Contrato'], [
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
    ], sent: false);

    identityEnableFlags($ctx['organization'], ['identity_video']);
    actingAsMember($ctx['owner'], $ctx['organization']);

    $recipient = $ctx['recipients']['maria@exemplo.test'];
    $url = route('envelopes.recipients.identity_video', ['envelope' => $ctx['envelope']->ulid, 'recipient' => $recipient->ulid]);

    $this->putJson($url, ['required' => true])->assertOk()
        ->assertJsonPath('required', true)
        ->assertJsonPath('max_seconds', 10);
    $this->putJson($url, ['required' => true, 'max_seconds' => 20])->assertOk()->assertJsonPath('max_seconds', 20);
    $this->putJson($url, ['required' => true, 'max_seconds' => 2])->assertStatus(422);
    $this->putJson($url, ['required' => true, 'max_seconds' => 31])->assertStatus(422);
    $this->putJson($url, [])->assertStatus(422);

    expect(app(IdentityVideos::class)->requirementsForEnvelope($ctx['envelope']))->toBe([$recipient->ulid => ['max_seconds' => 20]]);

    // A lista JSON (usada pelo wizard e pelo detalhe) traz a exigência e os limites.
    $this->getJson(route('envelopes.identity_videos.index', ['envelope' => $ctx['envelope']->ulid]))->assertOk()
        ->assertJsonPath('requirements.'.$recipient->ulid.'.max_seconds', 20)
        ->assertJsonPath('limits.default_seconds', 10)
        ->assertJsonPath('limits.ceiling_seconds', 30)
        ->assertJsonPath('items', []);

    $events = AuditEvent::query()->withoutGlobalScopes()
        ->where('event_type', AuditEventType::IdentityVideoRequirementUpdated->value)
        ->orderBy('id')->get()->map(fn (AuditEvent $event): array => $event->payload)->all();

    expect($events)->toBe([
        ['recipient_ulid' => $recipient->ulid, 'required' => true, 'max_seconds' => 10],
        ['recipient_ulid' => $recipient->ulid, 'required' => true, 'max_seconds' => 20],
    ]);

    // "video" não é tipo de FOTO: a exigência de fotos continua com a lista fechada da Fase 2.
    identityEnableFlags($ctx['organization'], ['identity_capture']);
    $this->putJson(route('envelopes.recipients.identity_capture', ['envelope' => $ctx['envelope']->ulid, 'recipient' => $recipient->ulid]), ['kinds' => ['video']])
        ->assertStatus(422);

    // Outra organização: 404 pelo binding escopado.
    ['organization' => $other, 'owner' => $otherOwner] = createOrganizationWithOwner();
    identityEnableFlags($other, ['identity_video']);
    actingAsMember($otherOwner, $other);
    $this->putJson($url, ['required' => false])->assertNotFound();
    $this->getJson(route('envelopes.identity_videos.index', ['envelope' => $ctx['envelope']->ulid]))->assertNotFound();

    // Deixar de exigir remove a linha.
    actingAsMember($ctx['owner'], $ctx['organization']);
    $this->putJson($url, ['required' => false])->assertOk()->assertJsonPath('required', false);
    expect(app(IdentityVideos::class)->requirementsForEnvelope($ctx['envelope']))->toBe([]);

    // Depois do envio a lista está congelada.
    $ctx['envelope']->forceFill(['status' => 'in_progress', 'sent_at' => now()])->save();
    $this->putJson($url, ['required' => true])->assertStatus(422);

    // Flag desligada: a rota não existe para a organização.
    config()->set('assinavelox.features.identity_video', false);
    $this->putJson($url, ['required' => true])->assertNotFound();
});

it('foto e vídeo juntos: o aceite pede os dois e o snapshot traz as fotos e depois o vídeo; câmera liberada, microfone nunca', function () {
    $ctx = videoEnvelope();
    identityEnableFlags($ctx['organization'], ['identity_capture']);
    $maria = $ctx['recipients']['maria@exemplo.test'];
    app(IdentityCaptures::class)->setRequirement($ctx['envelope'], $maria, ['selfie'], $ctx['owner']);

    $policy = (string) $this->get(route('sign.show', ['token' => $ctx['token']]))->assertOk()->headers->get('Permissions-Policy');

    expect($policy)->toContain('camera=(self)')->toContain('microphone=()');

    $props = authenticateSigner($this, $ctx['token']);

    expect($props['identity_capture']['items'])->toHaveCount(1)
        ->and($props['identity_video']['complete'])->toBeFalse();

    identityAccept($this, $ctx['token'], $props)->assertSessionHasErrors('signature');
    expect(session('errors')->first('signature'))->toContain('Foto do rosto')->toContain('Vídeo curto');

    identityPostCapture($this, $ctx['token'], 'selfie', identityPng())->assertCreated()
        ->assertJsonPath('identity_capture.complete', true);

    // A etapa de fotos não ganha item de vídeo; o vídeo não entra pela rota de fotos.
    $this->post(route('sign.capture.store', ['token' => $ctx['token'], 'kind' => 'selfie']), [], ['Accept' => 'application/json'])->assertStatus(422);
    expect(fn () => route('sign.capture.store', ['token' => $ctx['token'], 'kind' => 'video']))->not->toThrow(Exception::class);
    $this->post(route('sign.capture.store', ['token' => $ctx['token'], 'kind' => 'video']), [], ['Accept' => 'application/json'])->assertNotFound();

    videoPost($this, $ctx['token'], videoWebm(4000.0))->assertCreated()
        ->assertJsonPath('identity_video.complete', true);

    identityAccept($this, $ctx['token'], $props)->assertSessionHasNoErrors();

    $acceptance = SignatureAcceptance::withoutOrganizationScope()->firstOrFail();

    expect(array_column($acceptance->fields_snapshot['identity_captures'], 'kind'))->toBe(['selfie', 'video']);

    // Próxima pessoa, só com vídeo exigido: a câmera abre na página dela também.
    app(IdentityVideos::class)->setRequirement($ctx['envelope'], $ctx['recipients']['henrique@exemplo.test'], true, 15, $ctx['owner']);
    $this->flushSession();

    $henrique = $this->get(route('sign.show', ['token' => $ctx['tokens']['henrique@exemplo.test']]))->assertOk();

    expect((string) $henrique->headers->get('Permissions-Policy'))->toContain('camera=(self)')->toContain('microphone=()')
        ->and($henrique->viewData('page')['props']['identity_video']['max_seconds'])->toBe(15)
        ->and($henrique->viewData('page')['props']['identity_video']['upload_url'])->toBeNull()
        ->and($henrique->viewData('page')['props']['identity_capture'])->toBeNull();
});
