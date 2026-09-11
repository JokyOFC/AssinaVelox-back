<?php

use App\Enums\AuditEventType;
use App\Enums\AuthMethod;
use App\Enums\DeliveryChannel;
use App\Enums\SigningSessionStatus;
use App\Models\AuditEvent;
use App\Models\RecipientPin;
use App\Models\SigningSession;
use App\Services\Signing\Channels\SenderPins;
use App\Services\Signing\Channels\SignerAuthProps;
use App\Services\Signing\SignerLinkResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/Support/ChannelHelpers.php';

/*
|--------------------------------------------------------------------------
| PIN do remetente — só hash, depois do código, bloqueio por tentativas
|--------------------------------------------------------------------------
*/

const CHANNELS_PIN = '48291573';

beforeEach(function () {
    $this->work = storage_path('framework/testing/channels-pin-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();

    $this->emailCodes = signerCaptureCodes();
    $this->logs = channelsCaptureLogs();
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

if (! function_exists('channelsPinCode')) {
    /**
     * Pede e confirma o código por e-mail; devolve a resposta da confirmação.
     */
    function channelsPinCode(object $test, string $token): void
    {
        $test->post(route('sign.otp.send', ['token' => $token]))->assertSessionHasNoErrors();
        $codes = $test->emailCodes;
        $test->post(route('sign.otp.verify', ['token' => $token]), ['code' => $codes[count($codes) - 1]])->assertSessionHasNoErrors();
    }
}

if (! function_exists('channelsSignerRequest')) {
    function channelsSignerRequest(): Request
    {
        $request = Request::create('/');
        $request->setLaravelSession(app('session')->driver());

        return $request;
    }
}

it('o remetente define o PIN no sync: só o hash é guardado e a trilha não tem o valor', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    channelsEnable($organization, smsWhatsapp: false, pin: true);
    $envelope = draftWithDocument($organization, $owner);
    actingAsMember($owner, $organization);

    $this->put(route('envelopes.recipients.sync', $envelope), [
        'signing_order' => 'sequential',
        'recipients' => [['id' => null, 'name' => 'Maria Souza', 'email' => 'maria@exemplo.com', 'pin' => CHANNELS_PIN]],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $recipient = $envelope->fresh()->recipients()->sole();
    /** @var RecipientPin $record */
    $record = RecipientPin::query()->sole();

    expect($record->recipient_id)->toBe($recipient->id)
        ->and($record->pin_hash)->not->toBe(CHANNELS_PIN)
        ->and(password_get_info($record->pin_hash)['algoName'])->not->toBe('unknown')
        ->and(SenderPins::check($record, $recipient->ulid, CHANNELS_PIN))->toBeTrue()
        ->and(SenderPins::check($record, $recipient->ulid, '48291574'))->toBeFalse()
        ->and($record->toArray())->not->toHaveKey('pin_hash');

    $event = AuditEvent::query()->where('event_type', AuditEventType::RecipientPinUpdated->value)->sole();
    expect($event->payload)->toBe(['recipient' => $recipient->ulid, 'action' => 'set']);

    // Remoção explícita.
    $this->put(route('envelopes.recipients.sync', $envelope), [
        'signing_order' => 'sequential',
        'recipients' => [['id' => $recipient->ulid, 'name' => 'Maria Souza', 'email' => 'maria@exemplo.com', 'remove_pin' => true]],
    ])->assertSessionHasNoErrors();

    expect(RecipientPin::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::RecipientPinUpdated->value)->latest('id')->first()->payload['action'])->toBe('removed');

    expect(channelsDatabaseDump())->not->toContain(CHANNELS_PIN);
    expect(implode("\n", $this->logs->getArrayCopy()))->not->toContain(CHANNELS_PIN);
});

it('recusa PIN sem a flag, fora do formato ou previsível — sem guardar o PIN na sessão', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = draftWithDocument($organization, $owner);
    actingAsMember($owner, $organization);

    $sync = fn (string $pin) => $this->put(route('envelopes.recipients.sync', $envelope), [
        'signing_order' => 'sequential',
        'recipients' => [['id' => null, 'name' => 'Maria Souza', 'email' => 'maria@exemplo.com', 'pin' => $pin]],
    ]);

    $sync(CHANNELS_PIN)->assertSessionHasErrors(['recipients.0.pin' => 'O PIN do remetente não está habilitado para esta organização.']);
    expect(session('_old_input.recipients.0.name'))->toBe('Maria Souza')
        ->and(session('_old_input.recipients.0.pin'))->toBeNull();

    channelsEnable($organization, smsWhatsapp: false, pin: true);

    $sync('123')->assertSessionHasErrors(['recipients.0.pin' => 'O PIN deve ter de 4 a 8 dígitos.']);
    $sync('1234')->assertSessionHasErrors(['recipients.0.pin' => 'Escolha um PIN menos previsível (evite sequências como 1234 e dígitos repetidos como 0000).']);
    $sync('0000')->assertSessionHasErrors('recipients.0.pin');
    $sync('98765')->assertSessionHasErrors('recipients.0.pin');
    $sync('12ab')->assertSessionHasErrors('recipients.0.pin');

    expect(session('_old_input.recipients.0.pin'))->toBeNull()
        ->and(RecipientPin::query()->count())->toBe(0);
});

it('com PIN, o código só abre a etapa do PIN; o PIN certo autentica', function () {
    $ctx = channelsSignerContext(AuthMethod::EmailOtp, null, CHANNELS_PIN);
    channelsEnable($ctx['organization'], smsWhatsapp: false, pin: true);

    $this->post(route('sign.otp.send', ['token' => $ctx['token']]))->assertSessionHasNoErrors();
    $this->post(route('sign.otp.verify', ['token' => $ctx['token']]), ['code' => $this->emailCodes[0]])
        ->assertRedirect(route('sign.show', ['token' => $ctx['token']]))
        ->assertSessionHas('info', 'Código confirmado. Agora informe o PIN que quem enviou o documento combinou com você.');

    // O código sozinho não autentica: nada de documento.
    expect(SigningSession::query()->sole()->status)->toBe(SigningSessionStatus::PendingAuth);
    $this->get(route('sign.document', ['token' => $ctx['token']]))->assertNotFound();

    $context = app(SignerLinkResolver::class)->resolve($ctx['token']);
    $auth = app(SignerAuthProps::class)->for($context, channelsSignerRequest());

    expect($auth['step'])->toBe('pin')
        ->and($auth['pin'])->toMatchArray(['required' => true, 'step_active' => true, 'attempts_left' => 5, 'blocked' => false])
        ->and(app(SignerAuthProps::class)->authMethods($context))->toBe(['email_otp', 'sender_pin'])
        ->and(AuditEvent::query()->where('event_type', AuditEventType::ChallengeVerified->value)->sole()->payload['next_step'])->toBe('sender_pin');

    $this->post(route('sign.pin.verify', ['token' => $ctx['token']]), ['pin' => '99999999'])
        ->assertSessionHasErrors(['pin' => 'PIN incorreto (4 tentativas restantes).']);

    $this->post(route('sign.pin.verify', ['token' => $ctx['token']]), ['pin' => CHANNELS_PIN])
        ->assertRedirect(route('sign.show', ['token' => $ctx['token']]))
        ->assertSessionHas('success', 'PIN confirmado. Revise o documento e registre seu aceite.');

    expect(SigningSession::query()->sole()->status)->toBe(SigningSessionStatus::Authenticated);
    $this->get(route('sign.document', ['token' => $ctx['token']]))->assertOk();

    $started = AuditEvent::query()->where('event_type', AuditEventType::SessionStarted->value)->sole();

    expect($started->payload['sender_pin'])->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', AuditEventType::ChallengePinVerified->value)->count())->toBe(1)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::ChallengePinFailed->value)->sole()->payload)
        ->toBe(['reason' => 'invalid_pin', 'attempts_left' => 4])
        ->and(RecipientPin::query()->sole()->failed_attempts)->toBe(0);

    expect(channelsDatabaseDump())->not->toContain(CHANNELS_PIN);
    expect(implode("\n", $this->logs->getArrayCopy()))->not->toContain(CHANNELS_PIN);
    expect(json_encode(session()->all()))->not->toContain(CHANNELS_PIN);
});

it('o PIN antes do código é recusado', function () {
    $ctx = channelsSignerContext(AuthMethod::EmailOtp, null, CHANNELS_PIN);
    channelsEnable($ctx['organization'], smsWhatsapp: false, pin: true);

    $this->post(route('sign.pin.verify', ['token' => $ctx['token']]), ['pin' => CHANNELS_PIN])
        ->assertSessionHasErrors(['pin' => 'Confirme primeiro o código enviado a você e, em seguida, informe o PIN.']);

    expect(SigningSession::query()->where('status', SigningSessionStatus::Authenticated->value)->count())->toBe(0);
});

it('bloqueia temporariamente depois de cinco PINs errados e exige um novo código', function () {
    $ctx = channelsSignerContext(AuthMethod::EmailOtp, null, CHANNELS_PIN);
    channelsEnable($ctx['organization'], smsWhatsapp: false, pin: true);

    channelsPinCode($this, $ctx['token']);

    for ($i = 1; $i <= 4; $i++) {
        $this->post(route('sign.pin.verify', ['token' => $ctx['token']]), ['pin' => '99999999'])->assertSessionHasErrors('pin');
    }

    $this->post(route('sign.pin.verify', ['token' => $ctx['token']]), ['pin' => '99999999'])
        ->assertSessionHasErrors(['pin' => 'PIN incorreto. Por segurança, novas tentativas ficam bloqueadas por 15 min; depois disso, peça um novo código.']);

    $record = RecipientPin::query()->sole();

    expect($record->locked_until)->not->toBeNull()
        ->and($record->lockouts)->toBe(1)
        ->and($record->failed_attempts)->toBe(0);

    // Durante o bloqueio, nem o PIN certo passa.
    $this->post(route('sign.pin.verify', ['token' => $ctx['token']]), ['pin' => CHANNELS_PIN])
        ->assertSessionHasErrors(['pin' => 'Muitas tentativas de PIN. Aguarde 15 min e peça um novo código.']);

    // Passado o bloqueio, o portão continua fechado: é preciso outro código.
    $this->travel(16)->minutes();

    $this->post(route('sign.pin.verify', ['token' => $ctx['token']]), ['pin' => CHANNELS_PIN])
        ->assertSessionHasErrors(['pin' => 'Confirme primeiro o código enviado a você e, em seguida, informe o PIN.']);

    channelsPinCode($this, $ctx['token']);

    $this->post(route('sign.pin.verify', ['token' => $ctx['token']]), ['pin' => CHANNELS_PIN])->assertSessionHasNoErrors();

    expect(SigningSession::query()->latest('id')->first()->status)->toBe(SigningSessionStatus::Authenticated)
        ->and(RecipientPin::query()->sole()->lockouts)->toBe(0);
});

it('bloqueios seguidos bloqueiam o PIN de vez', function () {
    config()->set('assinavelox.pin.max_attempts', 2);
    config()->set('assinavelox.pin.max_lockouts', 2);

    $ctx = channelsSignerContext(AuthMethod::EmailOtp, null, CHANNELS_PIN);
    channelsEnable($ctx['organization'], smsWhatsapp: false, pin: true);

    foreach ([1, 2] as $round) {
        channelsPinCode($this, $ctx['token']);

        $this->post(route('sign.pin.verify', ['token' => $ctx['token']]), ['pin' => '99999999'])->assertSessionHasErrors('pin');
        $this->post(route('sign.pin.verify', ['token' => $ctx['token']]), ['pin' => '99999999'])->assertSessionHasErrors('pin');

        $this->travel(16)->minutes();
    }

    $record = RecipientPin::query()->sole();

    expect($record->blocked_at)->not->toBeNull()
        ->and($record->lockouts)->toBe(2);

    channelsPinCode($this, $ctx['token']);

    $this->post(route('sign.pin.verify', ['token' => $ctx['token']]), ['pin' => CHANNELS_PIN])
        ->assertSessionHasErrors(['pin' => 'O PIN foi bloqueado depois de tentativas demais. Fale com quem enviou o documento.']);

    expect(SigningSession::query()->where('status', SigningSessionStatus::Authenticated->value)->count())->toBe(0)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::ChallengePinFailed->value)->where('payload->reason', 'blocked')->exists())->toBeTrue();
});

it('PIN combinado com código por SMS: canal primeiro, PIN depois', function () {
    $ctx = channelsSignerContext(AuthMethod::SmsOtp, '+5511912345678', CHANNELS_PIN);
    channelsEnable($ctx['organization'], smsWhatsapp: true, pin: true);

    $this->post(route('sign.otp.send', ['token' => $ctx['token']]))->assertSessionHasNoErrors();
    $this->post(route('sign.otp.verify', ['token' => $ctx['token']]), ['code' => channelsLastCode(DeliveryChannel::Sms)])->assertSessionHasNoErrors();

    expect(SigningSession::query()->sole()->status)->toBe(SigningSessionStatus::PendingAuth);

    $this->post(route('sign.pin.verify', ['token' => $ctx['token']]), ['pin' => CHANNELS_PIN])->assertSessionHasNoErrors();

    expect(SigningSession::query()->sole()->status)->toBe(SigningSessionStatus::Authenticated);
});

it('o formato do PIN é validado e o valor não volta em old input', function () {
    $ctx = channelsSignerContext(AuthMethod::EmailOtp, null, CHANNELS_PIN);
    channelsEnable($ctx['organization'], smsWhatsapp: false, pin: true);

    $this->post(route('sign.pin.verify', ['token' => $ctx['token']]), ['pin' => '12a'])
        ->assertSessionHasErrors(['pin' => 'O PIN tem de 4 a 8 dígitos.']);

    expect(session('_old_input.pin'))->toBeNull();
});
