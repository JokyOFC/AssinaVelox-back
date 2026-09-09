<?php

use App\Enums\AuditEventType;
use App\Enums\DeliveryPurpose;
use App\Enums\SigningOrder;
use App\Enums\SigningSessionStatus;
use App\Models\AuditEvent;
use App\Models\AuthChallenge;
use App\Models\DeliveryAttempt;
use App\Models\DocumentVersion;
use App\Models\SigningSession;
use App\Services\Signing\Challenges;
use App\Services\Signing\Exceptions\SigningRejectedException;
use App\Services\Signing\SignerLinkResolver;
use App\Services\Signing\SignerTokens;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/SignerHelpers.php';

/*
|--------------------------------------------------------------------------
| Código por e-mail e sessão do signatário (arquitetura §4.2 e §4.3)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/signer-otp-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();

    $this->codes = signerCaptureCodes();
    $this->ctx = signerEnvelope();
    $this->token = $this->ctx['tokens']['maria@exemplo.test'];
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

it('envia o código, registra a tentativa de entrega e nunca grava o código em claro', function () {
    $this->post(route('sign.otp.send', ['token' => $this->token]))
        ->assertRedirect(route('sign.show', ['token' => $this->token]));

    /** @var AuthChallenge $challenge */
    $challenge = AuthChallenge::query()->sole();

    expect($this->codes)->toHaveCount(1);
    $code = $this->codes[0];

    // O código enviado confere com o HMAC guardado — e o HMAC não é o código.
    expect(hash_equals($challenge->code_hash, Challenges::hashCode($challenge->ulid, $code)))->toBeTrue()
        ->and($code)->toMatch('/^\d{6}$/')
        ->and($challenge->code_hash)->not->toBe($code)
        ->and(strlen($challenge->code_hash))->toBe(64)
        ->and($challenge->attempts)->toBe(0)
        ->and($challenge->max_attempts)->toBe(5)
        ->and($challenge->expires_at->diffInMinutes(now(), absolute: true))->toBeLessThanOrEqual(10);

    // O código não aparece em NENHUMA coluna de auth_challenges nem na trilha.
    $linhaCrua = json_encode(AuthChallenge::query()->first()?->getAttributes());
    expect($linhaCrua)->not->toContain($code);

    $trilha = AuditEvent::query()->get()->map(fn (AuditEvent $e) => json_encode($e->payload))->implode(' ');
    expect($trilha)->not->toContain($code)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::ChallengeSent->value)->exists())->toBeTrue();

    // Sessão `pending_auth` criada para hospedar o desafio; ainda não autentica nada.
    expect(SigningSession::query()->sole()->status)->toBe(SigningSessionStatus::PendingAuth);
});

it('registra delivery_attempt com propósito otp e sem o código nos metadados', function () {
    $this->post(route('sign.otp.send', ['token' => $this->token]))->assertRedirect();

    /** @var DeliveryAttempt $attempt */
    $attempt = DeliveryAttempt::query()->where('purpose', DeliveryPurpose::Otp->value)->sole();
    $code = $this->codes[0];

    expect($attempt->to_address)->toBe('maria@exemplo.test')
        ->and(json_encode($attempt->meta))->not->toContain($code)
        ->and($attempt->correlation_id)->not->toBeNull();
});

it('confirma o código, cria a sessão autenticada e leva para a tela de assinatura', function () {
    $this->post(route('sign.otp.send', ['token' => $this->token]))->assertRedirect();

    $code = $this->codes[0];

    $this->post(route('sign.otp.verify', ['token' => $this->token]), ['code' => $code])
        ->assertRedirect(route('sign.show', ['token' => $this->token]));

    /** @var SigningSession $session */
    $session = SigningSession::query()->sole();

    expect($session->status)->toBe(SigningSessionStatus::Authenticated)
        ->and($session->document_version_id)->toBe($this->ctx['version']->id)
        ->and($session->authenticated_at)->not->toBeNull()
        ->and(AuthChallenge::query()->sole()->consumed_at)->not->toBeNull();

    // O token bruto vive na sessão Laravel, não em cookie próprio nem no banco.
    $raw = session(SignerTokens::sessionKey($this->ctx['recipients']['maria@exemplo.test']->ulid));
    expect($raw)->toBeString()
        ->and(SignerTokens::matches($session->token_digest, $raw))->toBeTrue();

    $props = $this->get(route('sign.show', ['token' => $this->token]))->viewData('page')['props'];

    expect($props['screen'])->toBe('sign')
        ->and($props['document']['pdf_url'])->toBe(route('sign.document', ['token' => $this->token]))
        ->and($props['my_fields'])->toHaveCount(1)
        ->and($props['authorization']['token'])->toBeString()
        ->and($props['consent_text'])->toContain($this->ctx['version']->sha256);

    expect(AuditEvent::query()->where('event_type', AuditEventType::ChallengeVerified->value)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', AuditEventType::SessionStarted->value)->exists())->toBeTrue();
});

it('recusa código expirado e pede um novo', function () {
    $this->post(route('sign.otp.send', ['token' => $this->token]))->assertRedirect();

    $challenge = AuthChallenge::query()->sole();
    $code = $this->codes[0];
    $challenge->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->post(route('sign.otp.verify', ['token' => $this->token]), ['code' => $code])
        ->assertSessionHasErrors(['code' => 'O código expirou ou já foi usado. Peça um novo código.']);

    expect(SigningSession::query()->sole()->status)->toBe(SigningSessionStatus::PendingAuth);
});

it('recusa código já consumido (uso único)', function () {
    $this->post(route('sign.otp.send', ['token' => $this->token]))->assertRedirect();

    $code = $this->codes[0];

    $this->post(route('sign.otp.verify', ['token' => $this->token]), ['code' => $code])->assertRedirect();

    // Segunda tentativa com o MESMO código: o desafio já foi consumido.
    $this->post(route('sign.otp.verify', ['token' => $this->token]), ['code' => $code])
        ->assertSessionHasErrors('code');
});

it('invalida o código depois de cinco tentativas erradas', function () {
    $this->post(route('sign.otp.send', ['token' => $this->token]))->assertRedirect();

    $challenge = AuthChallenge::query()->sole();
    $correto = $this->codes[0];
    $errado = $correto === '000000' ? '111111' : '000000';

    for ($i = 1; $i <= 4; $i++) {
        $this->post(route('sign.otp.verify', ['token' => $this->token]), ['code' => $errado])
            ->assertSessionHasErrors('code');
    }

    expect($challenge->fresh()->attempts)->toBe(4);

    // A quinta esgota e mata o código, mesmo dentro da validade.
    $this->post(route('sign.otp.verify', ['token' => $this->token]), ['code' => $errado])
        ->assertSessionHasErrors(['code' => 'Código inválido. As tentativas acabaram — peça um novo código.']);

    expect($challenge->fresh()->consumed_at)->not->toBeNull();

    // Nem o código certo funciona depois disso. A sexta requisição já bateria no
    // `throttle:otp-verify` da rota (5 por 10 min por token) — a asserção aqui é sobre a
    // regra do desafio, então ela é feita no serviço, sem gastar o limitador.
    $context = app(SignerLinkResolver::class)->resolve($this->token);

    expect(fn () => app(Challenges::class)->verify($context, request(), $correto))
        ->toThrow(SigningRejectedException::class);

    expect(SigningSession::query()->sole()->status)->toBe(SigningSessionStatus::PendingAuth)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::ChallengeFailed->value)->count())->toBeGreaterThanOrEqual(5);
});

it('respeita o intervalo mínimo entre reenvios', function () {
    $this->post(route('sign.otp.send', ['token' => $this->token]))->assertRedirect();

    $this->post(route('sign.otp.send', ['token' => $this->token]))
        ->assertSessionHasErrorsIn('default', ['otp']);

    expect(AuthChallenge::query()->count())->toBe(1);
});

it('pedir outro código invalida o anterior', function () {
    $this->post(route('sign.otp.send', ['token' => $this->token]))->assertRedirect();
    $primeiro = AuthChallenge::query()->sole();
    $codigoAntigo = $this->codes[0];

    // Libera o intervalo de reenvio sem mexer no relógio.
    RateLimiter::clear('signer:otp:interval:'.$this->ctx['links']['maria@exemplo.test']->ulid);

    $this->post(route('sign.otp.send', ['token' => $this->token]))->assertRedirect();

    expect($primeiro->fresh()->consumed_at)->not->toBeNull()
        ->and(AuthChallenge::query()->count())->toBe(2);

    $this->post(route('sign.otp.verify', ['token' => $this->token]), ['code' => $codigoAntigo])
        ->assertSessionHasErrors('code');
});

it('a sessão de um destinatário não serve para outro', function () {
    $codes = $this->codes;

    $ctx = signerEnvelope([
        ['name' => 'Maria Alves', 'email' => 'maria2@exemplo.test', 'order' => 1],
        ['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.test', 'order' => 1],
    ], SigningOrder::Parallel);

    $tokenMaria = $ctx['tokens']['maria2@exemplo.test'];
    $tokenCarlos = $ctx['tokens']['carlos@exemplo.test'];

    $this->post(route('sign.otp.send', ['token' => $tokenMaria]))->assertRedirect();
    $code = $codes[0];
    $this->post(route('sign.otp.verify', ['token' => $tokenMaria]), ['code' => $code])->assertRedirect();

    // O MESMO navegador (mesma sessão Laravel) abrindo o link do Carlos:
    $props = $this->get(route('sign.show', ['token' => $tokenCarlos]))->viewData('page')['props'];

    expect($props['screen'])->toBe('identify');

    // E o PDF pelo link do Carlos continua fechado.
    $this->get(route('sign.document', ['token' => $tokenCarlos]))->assertNotFound();

    // Enquanto o da Maria abre.
    $this->get(route('sign.document', ['token' => $tokenMaria]))->assertOk();
});

it('a sessão morre quando o documento apresentado muda de versão', function () {
    $this->post(route('sign.otp.send', ['token' => $this->token]))->assertRedirect();
    $code = $this->codes[0];
    $this->post(route('sign.otp.verify', ['token' => $this->token]), ['code' => $code])->assertRedirect();

    $this->get(route('sign.document', ['token' => $this->token]))->assertOk();

    // O remetente troca a versão congelada (preparação refeita).
    $nova = DocumentVersion::factory()
        ->forDocument($this->ctx['version']->document)
        ->create(['version_number' => 2]);

    $this->ctx['envelope']->forceFill(['sent_document_version_id' => $nova->id])->save();

    $this->get(route('sign.document', ['token' => $this->token]))->assertNotFound();

    expect(SigningSession::query()->sole()->status)->toBe(SigningSessionStatus::Revoked);

    $props = $this->get(route('sign.show', ['token' => $this->token]))->viewData('page')['props'];
    expect($props['screen'])->toBe('identify');
});

it('transmite o PDF inline, sem cache e sem indexação', function () {
    $this->post(route('sign.otp.send', ['token' => $this->token]))->assertRedirect();
    $code = $this->codes[0];
    $this->post(route('sign.otp.verify', ['token' => $this->token]), ['code' => $code])->assertRedirect();

    $response = $this->get(route('sign.document', ['token' => $this->token]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeader('Referrer-Policy', 'no-referrer');

    expect($response->headers->get('Content-Disposition'))->toContain('inline')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('a miniatura de página responde 404 explicando a decisão', function () {
    $this->post(route('sign.otp.send', ['token' => $this->token]))->assertRedirect();
    $code = $this->codes[0];
    $this->post(route('sign.otp.verify', ['token' => $this->token]), ['code' => $code])->assertRedirect();

    $this->get(route('sign.page', ['token' => $this->token, 'page' => 1]))->assertNotFound();
});
