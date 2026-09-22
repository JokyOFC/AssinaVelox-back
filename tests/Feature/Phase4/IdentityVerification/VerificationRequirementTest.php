<?php

use App\Enums\AuditEventType;
use App\Enums\FieldType;
use App\Enums\RecipientRole;
use App\Events\EnvelopeReadyForFinalization;
use App\Models\AuditEvent;
use App\Services\Identity\IdentityCaptures;
use App\Services\Identity\IdentityFeatures;
use App\Services\Identity\IdentityVerifications;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../../Phase2/Domain/Support/DomainHelpers.php';
require_once __DIR__.'/../../Phase2/Identity/Support/IdentityHelpers.php';
require_once __DIR__.'/Support/VerificationHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 4 §4.1 — exigência da verificação facial com documento (remetente)
|--------------------------------------------------------------------------
| A flag, a rota do rascunho, o vínculo com as três fotos, o JSON do detalhe e a trilha.
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

it('a flag nasce desligada, só vale junto com identity_capture e fica fora do contrato das quatro flags', function () {
    ['organization' => $organization] = createOrganizationWithOwner();

    expect(config('assinavelox.features.identity_verification'))->toBeFalse()
        ->and(IdentityFeatures::identityVerification($organization))->toBeFalse()
        ->and(IdentityFeatures::forOrganization($organization))->not->toHaveKey('identity_verification');

    // Só o plano diz sim: desligada (T8).
    $plan = $organization->currentSubscription()->with('plan')->first()->plan;
    $plan->forceFill(['features' => array_merge((array) $plan->features, ['identity_verification' => true, 'identity_capture' => true])])->save();
    expect(IdentityFeatures::identityVerification($organization->fresh()))->toBeFalse();

    // Global da verificação ligado sem o da captura: desligada (as fotos vêm da captura).
    config()->set('assinavelox.features.identity_verification', true);
    expect(IdentityFeatures::identityVerification($organization->fresh()))->toBeFalse();

    // Os dois globais ligados, plano com os dois: ligada.
    config()->set('assinavelox.features.identity_capture', true);
    expect(IdentityFeatures::identityVerification($organization->fresh()))->toBeTrue();

    // Plano sem a captura: desligada, mesmo com a verificação no plano.
    $plan->forceFill(['features' => array_merge((array) $plan->features, ['identity_capture' => false])])->save();
    expect(IdentityFeatures::identityVerification($organization->fresh()))->toBeFalse();
});

it('o remetente exige a verificação só no rascunho; ligar acrescenta as três fotos; visualizador, outra organização e flag desligada recusam', function () {
    $ctx = domainEnvelope(['Contrato'], [
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
        ['name' => 'Vera Vê', 'email' => 'vera@exemplo.test', 'role' => RecipientRole::Viewer],
    ], sent: false);

    identityEnableFlags($ctx['organization'], ['participant_roles']);
    verificationEnable($ctx['organization']);
    actingAsMember($ctx['owner'], $ctx['organization']);

    $recipient = $ctx['recipients']['maria@exemplo.test'];
    $url = route('envelopes.recipients.identity_verification', ['envelope' => $ctx['envelope']->ulid, 'recipient' => $recipient->ulid]);

    // Já havia só a foto do rosto exigida: ligar completa com frente e verso, sem tirar nada.
    app(IdentityCaptures::class)->setRequirement($ctx['envelope'], $recipient, ['selfie'], $ctx['owner']);

    $this->putJson($url, ['required' => true])->assertOk()
        ->assertJsonPath('required', true)
        ->assertJsonPath('capture_kinds', ['selfie', 'document_front', 'document_back'])
        ->assertJsonPath('provider_label', 'Simulador');

    $this->putJson($url, [])->assertStatus(422);

    expect(app(IdentityVerifications::class)->requirementsForEnvelope($ctx['envelope']))->toBe([$recipient->ulid => true])
        ->and(app(IdentityCaptures::class)->requirementsForEnvelope($ctx['envelope']))->toBe([$recipient->ulid => ['selfie', 'document_front', 'document_back']]);

    // O JSON do detalhe traz a exigência, o provedor e o aviso de quem comparou.
    $this->getJson(route('envelopes.identity_verifications.index', ['envelope' => $ctx['envelope']->ulid]))->assertOk()
        ->assertJsonPath('requirements.'.$recipient->ulid, true)
        ->assertJsonPath('provider_label', 'Simulador')
        ->assertJsonPath('max_attempts', 3)
        ->assertJsonPath('items', [])
        ->assertJsonPath('notice', 'Quem comparou as imagens foi o provedor; a plataforma enviou as fotos da captura e registrou a resposta.');

    // Com a verificação exigida, as três fotos não podem sair da exigência de captura.
    $this->putJson(route('envelopes.recipients.identity_capture', ['envelope' => $ctx['envelope']->ulid, 'recipient' => $recipient->ulid]), ['kinds' => ['selfie']])
        ->assertStatus(422);
    $this->putJson(route('envelopes.recipients.identity_capture', ['envelope' => $ctx['envelope']->ulid, 'recipient' => $recipient->ulid]), ['kinds' => ['selfie', 'document_front', 'document_back']])
        ->assertOk();

    // Visualizador só recebe cópia.
    $this->putJson(route('envelopes.recipients.identity_verification', ['envelope' => $ctx['envelope']->ulid, 'recipient' => $ctx['recipients']['vera@exemplo.test']->ulid]), ['required' => true])
        ->assertStatus(422);

    // Repetir "ligado" não grava evento novo; desligar remove a linha e deixa as fotos como estão.
    $this->putJson($url, ['required' => true])->assertOk();
    $this->putJson($url, ['required' => false])->assertOk()->assertJsonPath('required', false);

    expect(app(IdentityVerifications::class)->requirementsForEnvelope($ctx['envelope']))->toBe([])
        ->and(app(IdentityCaptures::class)->requirementsForEnvelope($ctx['envelope']))->toBe([$recipient->ulid => ['selfie', 'document_front', 'document_back']]);

    $events = AuditEvent::query()->withoutGlobalScopes()
        ->where('event_type', AuditEventType::IdentityVerificationRequirementUpdated->value)
        ->orderBy('id')->get()->map(fn (AuditEvent $event): array => $event->payload)->all();

    expect($events)->toBe([
        ['recipient_ulid' => $recipient->ulid, 'required' => true],
        ['recipient_ulid' => $recipient->ulid, 'required' => false],
    ]);

    // Outra organização: 404 pelo binding escopado.
    ['organization' => $other, 'owner' => $otherOwner] = createOrganizationWithOwner();
    verificationEnable($other);
    actingAsMember($otherOwner, $other);
    $this->putJson($url, ['required' => true])->assertNotFound();
    $this->getJson(route('envelopes.identity_verifications.index', ['envelope' => $ctx['envelope']->ulid]))->assertNotFound();

    // Depois do envio a lista está congelada.
    actingAsMember($ctx['owner'], $ctx['organization']);
    $ctx['envelope']->forceFill(['status' => 'in_progress', 'sent_at' => now()])->save();
    $this->putJson($url, ['required' => true])->assertStatus(422);

    // Flag desligada: a rota não existe para a organização.
    config()->set('assinavelox.features.identity_verification', false);
    $this->putJson($url, ['required' => true])->assertNotFound();
    $this->getJson(route('envelopes.identity_verifications.index', ['envelope' => $ctx['envelope']->ulid]))->assertNotFound();
});

it('o wizard expõe a exigência por participante e se o recurso vale', function () {
    $ctx = domainEnvelope(['Contrato'], [
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.test', 'fields' => [['doc' => 0, 'type' => FieldType::Signature]]],
    ], sent: false);

    $recipient = $ctx['recipients']['maria@exemplo.test'];
    actingAsMember($ctx['owner'], $ctx['organization']);

    // Flag desligada: nada de verificação no wizard.
    $wizard = $this->get(route('envelopes.edit', ['envelope' => $ctx['envelope']->ulid, 'step' => 2]))->assertOk()->viewData('page')['props'];
    expect($wizard['verification_enabled'])->toBeFalse()
        ->and($wizard['verification_requirements'])->toBeNull()
        ->and($wizard['verification_provider_label'])->toBeNull();

    verificationEnable($ctx['organization']);
    app(IdentityVerifications::class)->setRequirement($ctx['envelope'], $recipient, true, $ctx['owner']);

    $wizard = $this->get(route('envelopes.edit', ['envelope' => $ctx['envelope']->ulid, 'step' => 2]))->assertOk()->viewData('page')['props'];
    expect($wizard['verification_enabled'])->toBeTrue()
        ->and($wizard['verification_requirements'])->toBe([$recipient->ulid => true])
        ->and($wizard['verification_provider_label'])->toBe('Simulador')
        ->and($wizard['capture_requirements'])->toBe([$recipient->ulid => ['selfie', 'document_front', 'document_back']]);
});
