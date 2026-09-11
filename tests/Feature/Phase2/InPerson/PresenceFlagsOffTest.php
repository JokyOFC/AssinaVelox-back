<?php

use App\Services\Batch\Models\BatchSigningSession;
use App\Services\InPerson\Models\InPersonSession;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/Support/PresenceHelpers.php';

/*
|--------------------------------------------------------------------------
| Flags `in_person` e `batch_signing` desligadas (o padrão): nada muda (T8)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/presence-off-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('nasce desligada: telas mostram o estado da Fase 2 e as ações respondem 404', function () {
    expect(config('assinavelox.features.in_person'))->toBeFalse()
        ->and(config('assinavelox.features.batch_signing'))->toBeFalse();

    $ctx = signerEnvelope();
    $recipient = $ctx['recipients']['maria@exemplo.test'];

    actingAsMember($ctx['owner'], $ctx['organization']);

    $page = $this->get(route('in_person.create'))->assertOk()->viewData('page');
    expect($page['component'])->toBe('in-person/start')
        ->and($page['props']['enabled'])->toBeFalse();

    $this->post(route('in_person.store'), ['envelope' => $ctx['envelope']->ulid, 'device_label' => 'Tablet'])->assertNotFound();
    $this->post(route('envelopes.recipients.batch', ['envelope' => $ctx['envelope']->ulid, 'recipient' => $recipient->ulid]))->assertNotFound();

    expect($this->get(route('in_person.kiosk.show'))->assertOk()->viewData('page')['props']['screen'])->toBe('unavailable')
        ->and($this->get(route('sign.batch.show'))->assertOk()->viewData('page')['props']['screen'])->toBe('unavailable')
        ->and($this->get(route('sign.batch.show', ['token' => str_repeat('c', 43)]))->assertOk()->viewData('page')['props']['screen'])->toBe('unavailable');

    $this->post(route('in_person.kiosk.participant'), ['recipient' => $recipient->ulid])->assertNotFound();
    $this->post(route('in_person.kiosk.otp.send'))->assertNotFound();
    $this->post(route('sign.batch.otp.send'))->assertNotFound();
    $this->get(route('in_person.kiosk.document'))->assertNotFound();
    $this->get(route('sign.batch.document'))->assertNotFound();

    expect(InPersonSession::withoutOrganizationScope()->count())->toBe(0)
        ->and(BatchSigningSession::withoutOrganizationScope()->count())->toBe(0);
});

it('interruptor global ligado sem o recurso no plano continua desligado para a organização', function () {
    config()->set('assinavelox.features.in_person', true);
    config()->set('assinavelox.features.batch_signing', true);

    $ctx = signerEnvelope();
    $recipient = $ctx['recipients']['maria@exemplo.test'];

    actingAsMember($ctx['owner'], $ctx['organization']);

    expect($this->get(route('in_person.create'))->assertOk()->viewData('page')['props']['enabled'])->toBeFalse();

    $this->post(route('in_person.store'), ['envelope' => $ctx['envelope']->ulid, 'device_label' => 'Tablet'])->assertNotFound();
    $this->post(route('envelopes.recipients.batch', ['envelope' => $ctx['envelope']->ulid, 'recipient' => $recipient->ulid]))->assertNotFound();

    // Sem dispositivo ligado a uma sessão, a página só diz que não há sessão aqui.
    expect($this->get(route('in_person.kiosk.show'))->assertOk()->viewData('page')['props']['screen'])->toBe('none');
});

it('desligar a flag no meio encerra a sessão presencial ativa', function () {
    $ctx = presenceTwoSigners();

    $this->codes = signerCaptureCodes();
    presenceStartKiosk($this, $ctx['organization'], $ctx['owner'], $ctx['envelope']);
    presenceAuthenticate($this, $ctx['maria']);

    presenceEnable($ctx['organization'], inPerson: false);
    config()->set('assinavelox.features.in_person', true);

    $after = presenceKiosk($this);

    expect($after['screen'])->toBe('none')
        ->and($after['ended']['reason'])->toBe('disabled')
        ->and(InPersonSession::withoutOrganizationScope()->sole()->status)->toBe(InPersonSession::STATUS_ENDED);
});
