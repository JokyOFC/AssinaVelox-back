<?php

use App\Enums\AuditEventType;
use App\Events\EnvelopeReadyForFinalization;
use App\Models\AuditEvent;
use App\Services\Identity\Models\IdentityVerification;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../../Sign/Support/SignerHelpers.php';
require_once __DIR__.'/../../Phase2/Identity/Support/IdentityHelpers.php';
require_once __DIR__.'/Support/VerificationHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 4 §4.1 — webhook da Verifiky (`POST /webhooks/verifiky`)
|--------------------------------------------------------------------------
| Assinatura HMAC do corpo cru, eventos ignorados, referência desconhecida, conclusão de uma
| tentativa pendente e a idempotência (um aviso atrasado nunca vira o resultado do avesso).
*/

beforeEach(function () {
    $this->work = storage_path('app/tmp/tests/'.Str::ulid());
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
    config()->set('inertia.ssr.enabled', false);
    config()->set('assinavelox.identity_verification.verifiky.webhook_secret', 'segredo-do-webhook-de-teste');
    Http::preventStrayRequests();
    Event::fake([EnvelopeReadyForFinalization::class]);
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

/**
 * Tentativa PENDENTE no provedor real (dublê), com protocolo `vk-4821`.
 *
 * @return array{0: array<string, mixed>, 1: IdentityVerification}
 */
function verifikyPendingVerification(object $test): array
{
    $ctx = verificationEnvelope('verifiky');
    authenticateSigner($test, $ctx['token']);
    verificationUploadPhotos($test, $ctx['token']);
    verificationDouble('pending', ['provider_status' => 'pending'], 'vk-4821');

    verificationPost($test, $ctx['token'])->assertCreated()->assertJsonPath('identity_verification.status', 'pending');

    return [$ctx, IdentityVerification::withoutOrganizationScope()->firstOrFail()];
}

/**
 * @return array<string, mixed>
 */
function verifikyCompletedPayload(IdentityVerification $verification, string $status = 'approved'): array
{
    return [
        'event' => 'verification.completed',
        'verification_id' => 4821,
        'user_reference' => $verification->reference,
        'status' => $status,
        'verificado' => $status === 'approved',
        'face_match' => ['match' => $status === 'approved', 'approved' => $status === 'approved', 'similarity' => 0.9312],
        'data' => ['dados_extraidos' => ['nome' => 'NOME QUE NAO PODE FICAR', 'cpf' => '19119119100']],
    ];
}

it('a rota fica fora do CSRF, com limite de taxa, e recusa assinatura inválida ou segredo ausente sem processar nada', function () {
    $route = Route::getRoutes()->getByName('webhooks.verifiky');

    expect($route->excludedMiddleware())->toContain(PreventRequestForgery::class)
        ->and($route->gatherMiddleware())->toContain('throttle:webhook')
        ->and($route->uri())->toBe('webhooks/verifiky');

    [, $verification] = verifikyPendingVerification($this);
    $payload = verifikyCompletedPayload($verification);

    Log::spy();

    verifikyWebhookPost($this, $payload, 'outro-segredo')->assertStatus(401)->assertExactJson(['error' => 'invalid_signature']);
    verifikyWebhookPost($this, $payload, null)->assertStatus(401)->assertExactJson(['error' => 'invalid_signature']);

    // Corpo adulterado depois de assinado.
    $signed = (string) json_encode($payload);
    $this->call('POST', route('webhooks.verifiky'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_VERIFIKY_SIGNATURE' => hash_hmac('sha256', $signed, 'segredo-do-webhook-de-teste'),
    ], str_replace('approved', 'rejected', $signed))->assertStatus(401);

    // Sem segredo configurado: nenhum aviso é aceito, nem o "assinado" com segredo vazio.
    config()->set('assinavelox.identity_verification.verifiky.webhook_secret', '');
    verifikyWebhookPost($this, $payload, 'segredo-do-webhook-de-teste')->assertStatus(401);
    verifikyWebhookPost($this, $payload, '')->assertStatus(401);

    expect($verification->fresh()->status->value)->toBe('pending');

    // O corpo cru (com os dados lidos do documento) nunca vai para o log.
    Log::shouldNotHaveReceived('warning', fn (string $message, array $context = []): bool => str_contains(json_encode($context) ?: '', 'NOME QUE NAO PODE FICAR'));
});

it('ignora eventos que não são a conclusão e referências que não são nossas, sempre com 200', function () {
    [, $verification] = verifikyPendingVerification($this);

    verifikyWebhookPost($this, ['event' => 'background_check.completed', 'verification_id' => 4821, 'user_reference' => $verification->reference, 'status' => 'approved'])
        ->assertOk()->assertExactJson(['status' => 'ignored']);

    verifikyWebhookPost($this, [], rawBody: 'isto não é JSON')->assertOk()->assertExactJson(['status' => 'ignored']);

    // Outro sistema na mesma conta da Verifiky: referência e protocolo desconhecidos.
    verifikyWebhookPost($this, ['event' => 'verification.completed', 'verification_id' => 99999, 'user_reference' => 'outro-sistema-123', 'status' => 'approved'])
        ->assertOk()->assertExactJson(['status' => 'ignored_unknown_reference']);

    expect($verification->fresh()->status->value)->toBe('pending')
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('event_type', AuditEventType::IdentityVerificationCompleted->value)->count())->toBe(0);
});

it('conclui a tentativa pendente pelo webhook, e um aviso atrasado ou repetido nunca muda o resultado', function () {
    [$ctx, $verification] = verifikyPendingVerification($this);

    verifikyWebhookPost($this, verifikyCompletedPayload($verification))->assertOk()->assertExactJson(['status' => 'approved']);

    $verification->refresh();

    expect($verification->status->value)->toBe('approved')
        ->and($verification->provider_verification_id)->toBe('4821')
        ->and($verification->completed_at)->not->toBeNull()
        ->and($verification->provider_result)->toMatchArray(['face_match' => true, 'face_match_approved' => true, 'face_score' => 0.9312])
        // Nada do que o provedor LEU do documento entra na linha.
        ->and(json_encode($verification->provider_result))->not->toContain('NOME QUE NAO PODE FICAR')
        ->and(json_encode($verification->provider_result))->not->toContain('19119119100');

    $completed = AuditEvent::query()->withoutGlobalScopes()->where('event_type', AuditEventType::IdentityVerificationCompleted->value)->get();
    expect($completed)->toHaveCount(1)
        ->and($completed[0]->payload['source'])->toBe('webhook')
        ->and($completed[0]->payload['status'])->toBe('approved');

    // Repetido: mesma resposta, nada gravado de novo.
    verifikyWebhookPost($this, verifikyCompletedPayload($verification))->assertOk()->assertExactJson(['status' => 'approved']);

    // Atrasado com outro resultado: o conclusivo não muda.
    verifikyWebhookPost($this, verifikyCompletedPayload($verification, 'rejected'))->assertOk()->assertExactJson(['status' => 'approved']);

    expect($verification->fresh()->status->value)->toBe('approved')
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('event_type', AuditEventType::IdentityVerificationCompleted->value)->count())->toBe(1);

    // A página do participante reflete o webhook sem consultar o provedor de novo.
    verificationShow($this, $ctx['token'])->assertOk()
        ->assertJsonPath('identity_verification.status', 'approved')
        ->assertJsonPath('identity_verification.message', 'Verifiky informou: aprovado — a foto tirada na hora corresponde à do documento.');
});

it('acha a tentativa pelo protocolo do provedor quando o aviso não traz a nossa referência, e reprova quando o provedor reprova', function () {
    [, $verification] = verifikyPendingVerification($this);

    verifikyWebhookPost($this, [
        'event' => 'verification.completed',
        'verificacao_id' => 'vk-4821',
        'status' => 'approved',
        'face_match' => ['match' => false, 'approved' => false],
    ])->assertOk()->assertExactJson(['status' => 'rejected']);

    $verification->refresh();

    expect($verification->status->value)->toBe('rejected')
        ->and($verification->reason_code)->toBe('face_mismatch')
        ->and($verification->faceMatch())->toBeFalse();
});
