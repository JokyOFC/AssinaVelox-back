<?php

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\MembershipRole;
use App\Enums\SigningOrder;
use App\Enums\SigningSessionStatus;
use App\Events\EnvelopeReadyForFinalization;
use App\Models\AuditEvent;
use App\Models\SignatureAcceptance;
use App\Models\SigningSession;
use App\Services\InPerson\Models\InPersonSession;
use App\Services\InPerson\Models\InPersonTurn;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/Support/PresenceHelpers.php';

/*
|--------------------------------------------------------------------------
| Presencial em tablet — isolamento entre participantes, expiração e organizações
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/in-person-iso-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
    Event::fake([EnvelopeReadyForFinalization::class]);
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('a sessão de um participante não serve para o seguinte: cookie, token e autorização', function () {
    $ctx = presenceTwoSigners();
    presenceStartKiosk($this, $ctx['organization'], $ctx['owner'], $ctx['envelope']);

    $maria = presenceAuthenticate($this, $ctx['maria']);
    $mariaAuthorization = $maria['participant']['signing']['authorization']['token'];
    $mariaPdf = $maria['participant']['signing']['documents'][0]['pdf_url'];
    $mariaCode = $this->codes[count($this->codes) - 1];

    // "Cópia do cookie": a sessão inteira do dispositivo durante a vez da Maria.
    $snapshot = session()->all();

    $mariaSession = SigningSession::withoutOrganizationScope()
        ->where('recipient_id', $ctx['maria']->id)
        ->where('status', SigningSessionStatus::Authenticated->value)
        ->sole();

    // Troca de participante sem aceite: João é chamado.
    $this->post(route('in_person.kiosk.participant'), ['recipient' => $ctx['joao']->ulid])->assertSessionHasNoErrors();

    expect($mariaSession->fresh()->status)->toBe(SigningSessionStatus::Revoked)
        ->and(session()->has('signer'))->toBeFalse();

    $mariaTurn = InPersonTurn::withoutOrganizationScope()->where('recipient_id', $ctx['maria']->id)->sole();
    expect($mariaTurn->status)->toBe(InPersonTurn::STATUS_CLOSED)
        ->and($mariaTurn->close_reason)->toBe(InPersonTurn::CLOSE_LOCKED)
        ->and($mariaTurn->turn_secret_digest)->toBeNull();

    // 1. A autorização da Maria na vez do João.
    $this->post(route('in_person.kiosk.complete'), [
        'authorization' => $mariaAuthorization,
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ])->assertSessionHasErrors('signature');

    // 2. O PDF da Maria na vez do João (que ainda nem confirmou o código).
    $this->get($mariaPdf)->assertNotFound();

    // 3. O "cookie" da vez da Maria restaurado por inteiro.
    $this->flushSession();
    $this->withSession($snapshot);

    $this->get($mariaPdf)->assertNotFound();
    $this->post(route('in_person.kiosk.complete'), [
        'authorization' => $mariaAuthorization,
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ])->assertSessionHasErrors('signature');

    // ...nem pelo link individual da Maria: a sessão restaurada foi revogada no banco.
    $this->post(route('sign.complete', ['token' => $ctx['tokens']['maria@exemplo.test']]), [
        'authorization' => $mariaAuthorization,
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
    ])->assertRedirect();

    expect(SignatureAcceptance::withoutOrganizationScope()->count())->toBe(0);

    // 4. O código antigo da Maria não reabre a vez dela: é preciso pedir outro.
    $this->flushSession();
    $this->withSession($snapshot);
    $this->post(route('in_person.kiosk.participant'), ['recipient' => $ctx['maria']->ulid])->assertSessionHasNoErrors();
    $this->post(route('in_person.kiosk.otp.verify'), ['code' => $mariaCode])->assertSessionHasErrors('code');

    expect(presenceKiosk($this)['participant']['step'])->toBe('identify')
        ->and(SignatureAcceptance::withoutOrganizationScope()->count())->toBe(0);
});

it('a tela fica limpa entre participantes: nada do anterior nas props', function () {
    $ctx = presenceTwoSigners();
    presenceStartKiosk($this, $ctx['organization'], $ctx['owner'], $ctx['envelope']);

    $maria = presenceAuthenticate($this, $ctx['maria']);
    $mariaAuthorization = $maria['participant']['signing']['authorization']['token'];
    presenceAccept($this, $maria)->assertSessionHasNoErrors();

    $locked = presenceKiosk($this);
    $json = (string) json_encode($locked);

    expect($locked['screen'])->toBe('queue')
        ->and($locked['participant'])->toBeNull()
        ->and($json)->not->toContain('maria@exemplo.test')
        ->and($json)->not->toContain($ctx['maria']->masked_email)
        ->and($json)->not->toContain($mariaAuthorization)
        ->and($json)->not->toContain('presencial\/documento');

    foreach ($locked['queue'] as $item) {
        expect(array_keys($item))->toEqualCanonicalizing([
            'id', 'name', 'first_name', 'role_label', 'participant_role', 'participant_role_label',
            'order', 'state', 'state_label', 'action_label', 'accepted_here',
        ]);
    }

    // Na vez do João, só dados do João.
    $joao = presenceAuthenticate($this, $ctx['joao']);
    $participant = (string) json_encode($joao['participant']);

    expect($joao['participant']['id'])->toBe($ctx['joao']->ulid)
        ->and($participant)->not->toContain('maria@exemplo.test')
        ->and($participant)->not->toContain($ctx['maria']->masked_email)
        ->and($participant)->not->toContain($mariaAuthorization)
        ->and(collect($joao['participant']['signing']['my_fields'])->pluck('id')->all())
        ->each->toBeIn($ctx['joao']->fields()->withoutGlobalScopes()->pluck('ulid')->all());
});

it('bloquear a tela encerra a vez e revoga a sessão do participante', function () {
    $ctx = presenceTwoSigners();
    presenceStartKiosk($this, $ctx['organization'], $ctx['owner'], $ctx['envelope']);

    $maria = presenceAuthenticate($this, $ctx['maria']);

    $this->post(route('in_person.kiosk.lock'))->assertRedirect(route('in_person.kiosk.show'));

    expect(presenceKiosk($this)['screen'])->toBe('queue')
        ->and(SigningSession::withoutOrganizationScope()->where('recipient_id', $ctx['maria']->id)->value('status'))
        ->toBe(SigningSessionStatus::Revoked)
        ->and(AuditEvent::withoutGlobalScopes()->where('event_type', AuditEventType::InPersonParticipantClosed->value)->value('payload')['reason'])
        ->toBe(InPersonTurn::CLOSE_LOCKED);

    $this->get($maria['participant']['signing']['documents'][0]['pdf_url'])->assertNotFound();
});

it('expira por inatividade: a tela bloqueia e a sessão do participante é revogada', function () {
    $ctx = presenceTwoSigners();
    presenceStartKiosk($this, $ctx['organization'], $ctx['owner'], $ctx['envelope']);
    $maria = presenceAuthenticate($this, $ctx['maria']);

    $this->travel(16)->minutes();

    $after = presenceKiosk($this);

    expect($after['screen'])->toBe('none')
        ->and($after['ended']['reason'])->toBe(InPersonSession::END_IDLE)
        ->and(InPersonSession::withoutOrganizationScope()->sole()->status)->toBe(InPersonSession::STATUS_EXPIRED)
        ->and(SigningSession::withoutOrganizationScope()->where('recipient_id', $ctx['maria']->id)->value('status'))
        ->toBe(SigningSessionStatus::Revoked);

    presenceAccept($this, $maria)->assertNotFound();
    $this->post(route('in_person.kiosk.participant'), ['recipient' => $ctx['joao']->ulid])->assertNotFound();

    expect(SignatureAcceptance::withoutOrganizationScope()->count())->toBe(0);
});

it('o anfitrião encerra de outro dispositivo e o dispositivo presencial bloqueia', function () {
    $ctx = presenceTwoSigners();
    presenceStartKiosk($this, $ctx['organization'], $ctx['owner'], $ctx['envelope']);
    $maria = presenceAuthenticate($this, $ctx['maria']);
    $device = session()->all();
    $session = InPersonSession::withoutOrganizationScope()->sole();

    // O anfitrião, no computador dele.
    $this->flushSession();
    actingAsMember($ctx['owner'], $ctx['organization']);

    $page = $this->get(route('in_person.create'))->assertOk()->viewData('page')['props'];
    expect(collect($page['sessions'])->pluck('id')->all())->toBe([$session->ulid]);

    $this->post(route('in_person.end', ['session' => $session->ulid]))->assertSessionHas('success');

    expect($session->fresh()->status)->toBe(InPersonSession::STATUS_ENDED)
        ->and($session->fresh()->end_reason)->toBe(InPersonSession::END_HOST)
        ->and($session->fresh()->ended_by_user_id)->toBe($ctx['owner']->id)
        ->and(SigningSession::withoutOrganizationScope()->where('recipient_id', $ctx['maria']->id)->value('status'))
        ->toBe(SigningSessionStatus::Revoked);

    // De volta ao dispositivo.
    app('auth')->forgetGuards();
    $this->flushSession();
    $this->withSession($device);

    $after = presenceKiosk($this);
    expect($after['screen'])->toBe('none')->and($after['ended']['reason'])->toBe(InPersonSession::END_HOST);

    presenceAccept($this, $maria)->assertNotFound();
});

it('o documento encerrado no meio da vez encerra a sessão presencial', function () {
    $ctx = presenceTwoSigners();
    presenceStartKiosk($this, $ctx['organization'], $ctx['owner'], $ctx['envelope']);
    $maria = presenceAuthenticate($this, $ctx['maria']);

    $ctx['envelope']->forceFill(['status' => EnvelopeStatus::Canceled, 'canceled_at' => now()])->save();

    $after = presenceKiosk($this);

    expect($after['screen'])->toBe('none')
        ->and($after['ended']['reason'])->toBe(InPersonSession::END_ENVELOPE_CLOSED)
        ->and(SigningSession::withoutOrganizationScope()->where('recipient_id', $ctx['maria']->id)->value('status'))
        ->toBe(SigningSessionStatus::Revoked);

    presenceAccept($this, $maria)->assertNotFound();
});

it('isola as organizações: outra conta não abre, não encerra e não chama participante alheio', function () {
    $a = presenceTwoSigners();
    ['organization' => $orgB, 'owner' => $ownerB] = createOrganizationWithOwner(['name' => 'Outra Conta']);
    presenceEnable($orgB);
    $b = signerEnvelope([['name' => 'Carla Dias', 'email' => 'carla@exemplo.test']], SigningOrder::Parallel, [], $orgB, $ownerB);

    // O dono de B tenta abrir sessão para o envelope de A.
    actingAsMember($ownerB, $orgB);
    $this->post(route('in_person.store'), ['envelope' => $a['envelope']->ulid, 'device_label' => 'Tablet'])->assertNotFound();
    expect(InPersonSession::withoutOrganizationScope()->count())->toBe(0);

    // A abre a sessão dele.
    presenceStartKiosk($this, $a['organization'], $a['owner'], $a['envelope']);
    $sessionA = InPersonSession::withoutOrganizationScope()->sole();

    // O dispositivo de A chama a participante do envelope de B: 404.
    $this->post(route('in_person.kiosk.participant'), ['recipient' => $b['recipients']['carla@exemplo.test']->ulid])->assertNotFound();
    expect(InPersonTurn::withoutOrganizationScope()->count())->toBe(0);

    // B tenta encerrar (e listar) a sessão de A.
    $this->flushSession();
    actingAsMember($ownerB, $orgB);
    $this->post(route('in_person.end', ['session' => $sessionA->ulid]))->assertNotFound();
    expect($sessionA->fresh()->status)->toBe(InPersonSession::STATUS_ACTIVE);

    $page = $this->get(route('in_person.create'))->assertOk()->viewData('page')['props'];
    expect(collect($page['sessions'])->pluck('id')->all())->not->toContain($sessionA->ulid)
        ->and(collect($page['envelopes'])->pluck('id')->all())->not->toContain($a['envelope']->ulid)
        ->and(collect($page['envelopes'])->pluck('id')->all())->toContain($b['envelope']->ulid);
});

it('só quem pode enviar o envelope abre a sessão presencial', function () {
    $ctx = presenceTwoSigners();
    $operator = attachMember($ctx['organization'], MembershipRole::Member);

    actingAsMember($operator, $ctx['organization']);

    $this->post(route('in_person.store'), [
        'envelope' => $ctx['envelope']->ulid,
        'device_label' => 'Tablet',
    ])->assertForbidden();

    expect(InPersonSession::withoutOrganizationScope()->count())->toBe(0);
});
