<?php

use App\Enums\AuditEventType;
use App\Enums\AuthMethod;
use App\Enums\DeliveryChannel;
use App\Enums\DeliveryPurpose;
use App\Enums\DeliveryStatus;
use App\Enums\SigningSessionStatus;
use App\Integrations\Sms\SimulatedMessagingProvider;
use App\Models\AuditEvent;
use App\Models\AuthChallenge;
use App\Models\DeliveryAttempt;
use App\Models\SigningSession;
use App\Services\Signing\Challenges;
use App\Services\Signing\Channels\SignerAuthProps;
use App\Services\Signing\Exceptions\SigningRejectedException;
use App\Services\Signing\SignerLinkResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/Support/ChannelHelpers.php';

/*
|--------------------------------------------------------------------------
| Código por SMS e WhatsApp — as MESMAS garantias do código por e-mail
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/channels-otp-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();

    $this->emailCodes = signerCaptureCodes();
    $this->logs = channelsCaptureLogs();
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('envia o código pelo canal, registra a tentativa honesta e nunca guarda o código em claro', function (AuthMethod $method, DeliveryChannel $channel, string $provider) {
    $ctx = channelsSignerContext($method);
    channelsEnable($ctx['organization']);

    $this->post(route('sign.otp.send', ['token' => $ctx['token']]))
        ->assertRedirect(route('sign.show', ['token' => $ctx['token']]))
        ->assertSessionHas('info', 'Enviamos um código para +55 •••••••5678.');

    /** @var AuthChallenge $challenge */
    $challenge = AuthChallenge::query()->sole();
    $code = channelsLastCode($channel);

    expect($this->emailCodes)->toHaveCount(0)
        ->and($challenge->channel)->toBe($channel)
        ->and($code)->toMatch('/^\d{6}$/')
        ->and(hash_equals($challenge->code_hash, Challenges::hashCode($challenge->ulid, $code)))->toBeTrue()
        ->and($challenge->code_hash)->not->toBe($code)
        ->and($challenge->max_attempts)->toBe(5)
        ->and($challenge->expires_at->diffInMinutes(now(), absolute: true))->toBeLessThanOrEqual(10);

    /** @var DeliveryAttempt $attempt */
    $attempt = DeliveryAttempt::query()->where('purpose', DeliveryPurpose::Otp->value)->sole();

    // Simulador: "registrado" não é "enviado", e muito menos "entregue".
    expect($attempt->channel)->toBe($channel)
        ->and($attempt->provider)->toBe($provider)
        ->and($attempt->to_address)->toBe('+5511912345678')
        ->and($attempt->status)->toBe(DeliveryStatus::Unknown)
        ->and($attempt->delivered_at)->toBeNull()
        ->and($attempt->meta['simulated'])->toBeTrue()
        ->and($challenge->delivery_attempt_id)->toBe($attempt->id);

    $sent = AuditEvent::query()->where('event_type', AuditEventType::ChallengeSent->value)->sole();

    expect($sent->payload['channel'])->toBe($channel->value)
        ->and($sent->payload['simulated'])->toBeTrue()
        ->and($sent->payload['delivery_status'])->toBe('unknown');

    $context = app(SignerLinkResolver::class)->resolve($ctx['token']);
    $auth = app(SignerAuthProps::class)->for($context, Request::create('/'));

    expect($auth)->toMatchArray([
        'method' => $method->value,
        'channel' => $channel->value,
        'destination' => '+55 •••••••5678',
        'simulated' => true,
        'available' => true,
        'step' => 'code',
        'pin' => null,
    ]);

    $this->post(route('sign.otp.verify', ['token' => $ctx['token']]), ['code' => $code])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(SigningSession::query()->sole()->status)->toBe(SigningSessionStatus::Authenticated)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::SessionStarted->value)->sole()->payload['auth_method'])->toBe($method->value);

    // Nunca em claro: banco, trilha e log.
    expect(channelsDatabaseDump())->not->toContain($code);
    expect(implode("\n", $this->logs->getArrayCopy()))->toContain('[SIMULADO]')->not->toContain($code);
})->with(channelsDataset());

it('recusa código do canal expirado', function (AuthMethod $method, DeliveryChannel $channel) {
    $ctx = channelsSignerContext($method);
    channelsEnable($ctx['organization']);

    $this->post(route('sign.otp.send', ['token' => $ctx['token']]))->assertRedirect();
    $code = channelsLastCode($channel);

    $this->travel(11)->minutes();

    $this->post(route('sign.otp.verify', ['token' => $ctx['token']]), ['code' => $code])
        ->assertSessionHasErrors(['code' => 'O código expirou ou já foi usado. Peça um novo código.']);

    expect(SigningSession::query()->sole()->status)->toBe(SigningSessionStatus::PendingAuth);
})->with(channelsDataset());

it('recusa código do canal reutilizado', function (AuthMethod $method, DeliveryChannel $channel) {
    $ctx = channelsSignerContext($method);
    channelsEnable($ctx['organization']);

    $this->post(route('sign.otp.send', ['token' => $ctx['token']]))->assertRedirect();
    $code = channelsLastCode($channel);

    $this->post(route('sign.otp.verify', ['token' => $ctx['token']]), ['code' => $code])->assertSessionHasNoErrors();
    $this->post(route('sign.otp.verify', ['token' => $ctx['token']]), ['code' => $code])->assertSessionHasErrors('code');
})->with(channelsDataset());

it('invalida o código do canal depois de cinco erros', function (AuthMethod $method, DeliveryChannel $channel) {
    $ctx = channelsSignerContext($method);
    channelsEnable($ctx['organization']);

    $this->post(route('sign.otp.send', ['token' => $ctx['token']]))->assertRedirect();
    $right = channelsLastCode($channel);
    $wrong = $right === '000000' ? '111111' : '000000';

    for ($i = 1; $i <= 4; $i++) {
        $this->post(route('sign.otp.verify', ['token' => $ctx['token']]), ['code' => $wrong])->assertSessionHasErrors('code');
    }

    $this->post(route('sign.otp.verify', ['token' => $ctx['token']]), ['code' => $wrong])
        ->assertSessionHasErrors(['code' => 'Código inválido. As tentativas acabaram — peça um novo código.']);

    $context = app(SignerLinkResolver::class)->resolve($ctx['token']);

    expect(fn () => app(Challenges::class)->verify($context, request(), $right))->toThrow(SigningRejectedException::class)
        ->and(SigningSession::query()->sole()->status)->toBe(SigningSessionStatus::PendingAuth);
})->with(channelsDataset());

it('limita o reenvio do código do canal por link e por IP', function (AuthMethod $method, DeliveryChannel $channel) {
    $ctx = channelsSignerContext($method);
    channelsEnable($ctx['organization']);
    $interval = 'signer:otp:interval:'.$ctx['link']->ulid;

    $this->post(route('sign.otp.send', ['token' => $ctx['token']]))->assertSessionHasNoErrors();

    // Intervalo mínimo entre envios.
    $this->post(route('sign.otp.send', ['token' => $ctx['token']]))->assertSessionHasErrors('otp');

    // Teto por link por hora (a partir daqui pelo serviço: a rota tem o próprio freio,
    // `throttle:otp-send`, 3 por 10 min por link e IP).
    config()->set('assinavelox.otp.resend_limit', 2);
    RateLimiter::clear($interval);
    $this->post(route('sign.otp.send', ['token' => $ctx['token']]))->assertSessionHasNoErrors();
    RateLimiter::clear($interval);
    $context = app(SignerLinkResolver::class)->resolve($ctx['token']);

    expect(fn () => app(Challenges::class)->send($context, request()))
        ->toThrow(SigningRejectedException::class, 'Você pediu o código muitas vezes.');

    // Teto por IP, independente do link.
    config()->set('assinavelox.otp.resend_limit', 50);
    config()->set('assinavelox.otp.ip_hourly_limit', 2);

    expect(fn () => app(Challenges::class)->send($context, request()))
        ->toThrow(SigningRejectedException::class, 'Muitos pedidos de código a partir desta conexão.');

    expect(channelsOutbox()->all($channel))->toHaveCount(2)
        ->and(DeliveryAttempt::query()->where('channel', $channel->value)->count())->toBe(2);
})->with(channelsDataset());

it('aceito pelo provedor é "enviado", nunca "entregue"; tempo esgotado é "desconhecido"', function (AuthMethod $method, DeliveryChannel $channel, string $provider, string $class) {
    $ctx = channelsSignerContext($method);
    channelsEnable($ctx['organization']);

    app($class)->simulate(SimulatedMessagingProvider::MODE_SENT);
    $this->post(route('sign.otp.send', ['token' => $ctx['token']]))->assertSessionHasNoErrors();

    $first = DeliveryAttempt::query()->latest('id')->first();

    expect($first->status)->toBe(DeliveryStatus::Sent)
        ->and($first->sent_at)->not->toBeNull()
        ->and($first->delivered_at)->toBeNull();

    app($class)->simulate(SimulatedMessagingProvider::MODE_TIMEOUT);
    RateLimiter::clear('signer:otp:interval:'.$ctx['link']->ulid);
    $this->post(route('sign.otp.send', ['token' => $ctx['token']]))->assertSessionHasNoErrors();

    $second = DeliveryAttempt::query()->latest('id')->first();
    $event = AuditEvent::query()->where('event_type', AuditEventType::ChallengeSent->value)->latest('id')->first();

    expect($second->id)->not->toBe($first->id)
        ->and($second->status)->toBe(DeliveryStatus::Unknown)
        ->and($second->error_message)->toContain('Tempo esgotado')
        ->and($second->sent_at)->toBeNull()
        ->and($event->payload['delivery_status'])->toBe('unknown')
        ->and(AuthChallenge::query()->latest('id')->first()->delivery_attempt_id)->toBe($second->id);
})->with(channelsDataset());

it('sem provedor disponível ou sem celular, o código não é gerado', function () {
    $ctx = channelsSignerContext(AuthMethod::SmsOtp);
    channelsEnable($ctx['organization']);
    config()->set('assinavelox.channels.sms.driver', 'http');

    $this->post(route('sign.otp.send', ['token' => $ctx['token']]))
        ->assertSessionHasErrors(['otp' => 'O envio do código por SMS está indisponível no momento. Fale com quem enviou o documento.']);

    config()->set('assinavelox.channels.sms.driver', 'fake');
    $ctx['recipient']->forceFill(['phone' => null])->save();

    $this->post(route('sign.otp.send', ['token' => $ctx['token']]))
        ->assertSessionHasErrors(['otp' => 'Não há um celular válido cadastrado para você receber o código. Fale com quem enviou o documento.']);

    expect(AuthChallenge::query()->count())->toBe(0)
        ->and(DeliveryAttempt::query()->count())->toBe(0);
});

it('a flag desligada depois do envio não abandona quem já tem código por SMS', function () {
    $ctx = channelsSignerContext(AuthMethod::SmsOtp);
    channelsEnable($ctx['organization'], smsWhatsapp: false);

    $this->post(route('sign.otp.send', ['token' => $ctx['token']]))->assertSessionHasNoErrors();

    expect(AuthChallenge::query()->sole()->channel)->toBe(DeliveryChannel::Sms);
});

it('respeita o limite diário de mensagens da organização', function () {
    $ctx = channelsSignerContext(AuthMethod::SmsOtp);
    channelsEnable($ctx['organization']);
    config()->set('assinavelox.channels.org_daily_limit', 1);

    $this->post(route('sign.otp.send', ['token' => $ctx['token']]))->assertSessionHasNoErrors();
    RateLimiter::clear('signer:otp:interval:'.$ctx['link']->ulid);

    $this->post(route('sign.otp.send', ['token' => $ctx['token']]))
        ->assertSessionHasErrors(['otp' => 'O limite diário de mensagens por SMS e WhatsApp de quem enviou o documento foi atingido. Tente de novo amanhã ou fale com quem enviou.']);

    expect(AuthChallenge::query()->count())->toBe(1);
});
