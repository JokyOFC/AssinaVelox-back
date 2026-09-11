<?php

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\SigningOrder;
use App\Enums\SigningSessionStatus;
use App\Events\EnvelopeReadyForFinalization;
use App\Models\AuditEvent;
use App\Models\SignatureAcceptance;
use App\Models\SigningSession;
use App\Services\InPerson\InPersonEvidence;
use App\Services\InPerson\InPersonSessions;
use App\Services\InPerson\Models\InPersonSession;
use App\Services\InPerson\Models\InPersonTurn;
use App\Services\Signing\Channels\SenderPins;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/Support/PresenceHelpers.php';
require_once __DIR__.'/../Channels/Support/ChannelHelpers.php';

/*
|--------------------------------------------------------------------------
| Presencial em tablet — fluxo, evidência e o anfitrião (Fase 2 §2.6, C-PRES)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/in-person-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
    Event::fake([EnvelopeReadyForFinalization::class]);
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('dois participantes registram cada um o próprio aceite no mesmo dispositivo', function () {
    $ctx = presenceTwoSigners();

    $props = presenceStartKiosk($this, $ctx['organization'], $ctx['owner'], $ctx['envelope']);

    // O anfitrião foi desconectado deste navegador antes de entregar o dispositivo.
    $this->assertGuest();

    expect($props['screen'])->toBe('queue')
        ->and($props['kiosk']['device_label'])->toBe('Tablet do balcão')
        ->and($props['kiosk']['host_name'])->toBe($ctx['owner']->name)
        ->and(collect($props['queue'])->pluck('state', 'id')->all())->toBe([
            $ctx['maria']->ulid => 'available',
            $ctx['joao']->ulid => 'available',
        ]);

    $session = InPersonSession::withoutOrganizationScope()->sole();
    expect($session->host_user_id)->toBe($ctx['owner']->id)
        ->and($session->status)->toBe(InPersonSession::STATUS_ACTIVE);

    // Maria: código DELA, documento, aceite.
    $maria = presenceAuthenticate($this, $ctx['maria']);

    expect($maria['screen'])->toBe('participant')
        ->and($maria['participant']['id'])->toBe($ctx['maria']->ulid)
        ->and($maria['participant']['step'])->toBe('sign')
        ->and($maria['participant']['auth']['destination'])->toBe($ctx['maria']->masked_email);

    presenceAccept($this, $maria)
        ->assertRedirect(route('in_person.kiosk.show'))
        ->assertSessionHasNoErrors();

    $locked = presenceKiosk($this);
    expect($locked['screen'])->toBe('queue')
        ->and($locked['done'])->toBeTrue()
        ->and($locked['participant'])->toBeNull()
        ->and(collect($locked['queue'])->firstWhere('id', $ctx['maria']->ulid)['state'])->toBe('done')
        ->and(collect($locked['queue'])->firstWhere('id', $ctx['maria']->ulid)['accepted_here'])->toBeTrue();

    // João: outro código, outra sessão, campo próprio.
    $joao = presenceAuthenticate($this, $ctx['joao']);
    $text = collect($joao['participant']['signing']['my_fields'])->firstWhere('type', 'text');

    presenceAccept($this, $joao, [$text['id'] => 'Vistoria conferida'])->assertSessionHasNoErrors();

    $acceptances = SignatureAcceptance::withoutOrganizationScope()->orderBy('id')->get();

    expect($acceptances)->toHaveCount(2)
        ->and($acceptances->pluck('recipient_id')->all())->toBe([$ctx['maria']->id, $ctx['joao']->id])
        ->and($acceptances[0]->signing_session_id)->not->toBe($acceptances[1]->signing_session_id)
        ->and($acceptances[0]->auth_challenge_id)->not->toBe($acceptances[1]->auth_challenge_id)
        ->and($acceptances[0]->auth_challenge_id)->not->toBeNull();

    $turns = InPersonTurn::withoutOrganizationScope()->orderBy('id')->get();

    expect($turns->pluck('status')->all())->toBe([InPersonTurn::STATUS_ACCEPTED, InPersonTurn::STATUS_ACCEPTED])
        ->and($turns[0]->signature_acceptance_id)->toBe($acceptances[0]->id)
        ->and($turns[1]->signature_acceptance_id)->toBe($acceptances[1]->id)
        ->and($turns[0]->signing_session_id)->toBe($acceptances[0]->signing_session_id);

    // Ninguém mais falta: o envelope foi para a finalização e a sessão presencial se encerra.
    expect($ctx['envelope']->fresh()->status)->toBe(EnvelopeStatus::Finalizing);

    $end = presenceKiosk($this);
    expect($end['screen'])->toBe('none')
        ->and($end['ended']['reason'])->toBe(InPersonSession::END_ENVELOPE_CLOSED)
        ->and($session->fresh()->status)->toBe(InPersonSession::STATUS_ENDED);
});

it('a evidência registra presencial, anfitrião, dispositivo e momento — e o autor do aceite é o participante', function () {
    $ctx = presenceTwoSigners();

    presenceStartKiosk($this, $ctx['organization'], $ctx['owner'], $ctx['envelope'], label: 'Tablet da vistoria');
    $deviceSecret = (string) session(InPersonSessions::DEVICE_KEY);

    $maria = presenceAuthenticate($this, $ctx['maria']);
    presenceAccept($this, $maria)->assertSessionHasNoErrors();

    $acceptance = SignatureAcceptance::withoutOrganizationScope()->sole();

    // O aceite é da Maria, com o método que ELA confirmou.
    $recorded = AuditEvent::withoutGlobalScopes()->where('event_type', AuditEventType::AcceptanceRecorded->value)->sole();
    expect($recorded->actor_type)->toBe(ActorType::Recipient)
        ->and($recorded->actor_id)->toBe($ctx['maria']->id)
        ->and($acceptance->auth_method->value)->toBe('email_otp');

    // O fato presencial: ator ainda é a Maria; o anfitrião entra como quem atestou a presença.
    $inPerson = AuditEvent::withoutGlobalScopes()->where('event_type', AuditEventType::InPersonAcceptanceRecorded->value)->sole();
    expect($inPerson->actor_type)->toBe(ActorType::Recipient)
        ->and($inPerson->actor_id)->toBe($ctx['maria']->id)
        ->and($inPerson->payload['acceptance_ulid'])->toBe($acceptance->ulid)
        ->and($inPerson->payload['host_user_id'])->toBe($ctx['owner']->id)
        ->and($inPerson->payload['device_label'])->toBe('Tablet da vistoria');

    $started = AuditEvent::withoutGlobalScopes()->where('event_type', AuditEventType::InPersonSessionStarted->value)->sole();
    expect($started->actor_type)->toBe(ActorType::User)
        ->and($started->actor_id)->toBe($ctx['owner']->id)
        ->and($started->envelope_id)->toBe($ctx['envelope']->id);

    $evidence = InPersonEvidence::forEnvelope($ctx['envelope']);

    expect($evidence)->toHaveCount(1)
        ->and($evidence[0]['mode'])->toBe('in_person')
        ->and($evidence[0]['label'])->toBe('Presencial, na presença de '.$ctx['owner']->name)
        ->and($evidence[0]['recipient_name'])->toBe('Maria Alves Souza')
        ->and($evidence[0]['host_name'])->toBe($ctx['owner']->name)
        ->and($evidence[0]['device_label'])->toBe('Tablet da vistoria')
        ->and($evidence[0]['acceptance_id'])->toBe($acceptance->ulid)
        ->and($evidence[0]['accepted_at'])->toBe($acceptance->accepted_at->toIso8601String());

    // Nada de segredo na trilha: nem o código, nem o segredo do dispositivo, nem tokens.
    $trail = (string) json_encode(AuditEvent::withoutGlobalScopes()->pluck('payload')->all());

    expect($trail)->not->toContain($deviceSecret)
        ->and($trail)->not->toContain($maria['participant']['signing']['authorization']['token'])
        ->and($trail)->not->toContain('maria@exemplo.test');

    foreach ($this->codes as $code) {
        expect($trail)->not->toContain('"'.$code.'"');
    }
});

it('o anfitrião não consegue registrar aceite pelo participante', function () {
    $ctx = presenceTwoSigners();

    presenceStartKiosk($this, $ctx['organization'], $ctx['owner'], $ctx['envelope'], keepSignedIn: true);
    $this->assertAuthenticatedAs($ctx['owner']);

    $this->post(route('in_person.kiosk.participant'), ['recipient' => $ctx['maria']->ulid])->assertRedirect();

    $props = presenceKiosk($this);
    expect($props['participant']['step'])->toBe('identify')
        ->and($props['participant']['signing'])->toBeNull();

    // Sem o código da Maria: nem o documento, nem o aceite — mesmo logado como dono da conta.
    $this->get(route('in_person.kiosk.document', ['document' => $ctx['document']->ulid]))->assertNotFound();

    $this->post(route('in_person.kiosk.complete'), [
        'authorization' => str_repeat('x', 43),
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ])->assertSessionHasErrors('signature');

    // Um código errado não abre nada.
    $this->post(route('in_person.kiosk.otp.send'))->assertSessionHasNoErrors();
    $real = $this->codes[count($this->codes) - 1];
    $this->post(route('in_person.kiosk.otp.verify'), ['code' => $real === '000000' ? '111111' : '000000'])
        ->assertSessionHasErrors('code');

    expect(presenceKiosk($this)['participant']['step'])->toBe('identify');

    // Nem pelo link individual da Maria com a sessão do anfitrião.
    $this->post(route('sign.complete', ['token' => $ctx['tokens']['maria@exemplo.test']]), [
        'authorization' => str_repeat('y', 43),
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ])->assertRedirect();

    expect(SignatureAcceptance::withoutOrganizationScope()->count())->toBe(0)
        ->and(SigningSession::withoutOrganizationScope()->where('status', SigningSessionStatus::Authenticated->value)->count())->toBe(0);
});

it('no sequencial, só quem está na vez pode ser chamado; o seguinte fica disponível depois', function () {
    $ctx = presenceTwoSigners(SigningOrder::Sequential);

    $props = presenceStartKiosk($this, $ctx['organization'], $ctx['owner'], $ctx['envelope']);

    expect(collect($props['queue'])->pluck('state', 'id')->all())->toBe([
        $ctx['maria']->ulid => 'available',
        $ctx['joao']->ulid => 'waiting',
    ]);

    $this->post(route('in_person.kiosk.participant'), ['recipient' => $ctx['joao']->ulid])
        ->assertSessionHasErrors('participant');

    $maria = presenceAuthenticate($this, $ctx['maria']);
    presenceAccept($this, $maria)->assertSessionHasNoErrors();

    expect(collect(presenceKiosk($this)['queue'])->firstWhere('id', $ctx['joao']->ulid)['state'])->toBe('available');

    $joao = presenceAuthenticate($this, $ctx['joao']);
    $text = collect($joao['participant']['signing']['my_fields'])->firstWhere('type', 'text');
    presenceAccept($this, $joao, [$text['id'] => 'Conferido'])->assertSessionHasNoErrors();

    expect(SignatureAcceptance::withoutOrganizationScope()->count())->toBe(2);
});

it('participante com PIN do remetente confirma o código e o PIN dele no dispositivo', function () {
    $ctx = presenceTwoSigners();
    channelsEnable($ctx['organization'], smsWhatsapp: false, pin: true);
    app(SenderPins::class)->set($ctx['maria'], '4831');

    presenceStartKiosk($this, $ctx['organization'], $ctx['owner'], $ctx['envelope']);

    $this->post(route('in_person.kiosk.participant'), ['recipient' => $ctx['maria']->ulid])->assertSessionHasNoErrors();
    $this->post(route('in_person.kiosk.otp.send'))->assertSessionHasNoErrors();
    $this->post(route('in_person.kiosk.otp.verify'), ['code' => $this->codes[count($this->codes) - 1]])->assertSessionHasNoErrors();

    expect(presenceKiosk($this)['participant']['step'])->toBe('pin');

    // Sem o PIN, nada de documento.
    $this->get(route('in_person.kiosk.document', ['document' => $ctx['document']->ulid]))->assertNotFound();

    $this->post(route('in_person.kiosk.pin.verify'), ['pin' => '9270'])->assertSessionHasErrors('pin');
    $this->post(route('in_person.kiosk.pin.verify'), ['pin' => '4831'])->assertSessionHasNoErrors();

    $props = presenceKiosk($this);
    expect($props['participant']['step'])->toBe('sign');

    $this->get($props['participant']['signing']['documents'][0]['pdf_url'])->assertOk();
    presenceAccept($this, $props)->assertSessionHasNoErrors();

    expect(SignatureAcceptance::withoutOrganizationScope()->where('recipient_id', $ctx['maria']->id)->count())->toBe(1);
});
