<?php

use App\Enums\AuditEventType;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookEndpointManager;
use App\Services\Webhooks\WebhookSignature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/Support/WebhookHelpers.php';

/*
|--------------------------------------------------------------------------
| Assinatura HMAC e rotação do segredo — docs/fase-2/webhooks.md §4
|--------------------------------------------------------------------------
*/

it('assina HMAC-SHA256 sobre "{timestamp}.{corpo bruto}" e a assinatura muda com 1 byte', function (): void {
    $secret = WebhookSignature::generateSecret();
    $body = '{"id":"01JABCDEFGHJKMNPQRSTVWXYZ0","type":"envelope.sent","data":{}}';
    $timestamp = 1789142400;

    $signature = WebhookSignature::compute($secret, $timestamp, $body);

    expect($signature)->toBe(hash_hmac('sha256', $timestamp.'.'.$body, $secret))
        ->and(strlen($signature))->toBe(64);

    $tampered = $body;
    $tampered[20] = chr(ord($tampered[20]) ^ 0x01);

    expect(WebhookSignature::compute($secret, $timestamp, $tampered))->not->toBe($signature);

    $header = WebhookSignature::header([$secret], $timestamp, $body);

    expect($header)->toBe('v1='.$signature)
        ->and(WebhookSignature::verify($secret, $header, (string) $timestamp, $body, $timestamp + 10))->toBeTrue()
        ->and(WebhookSignature::verify($secret, $header, (string) $timestamp, $tampered, $timestamp))->toBeFalse()
        ->and(WebhookSignature::verify($secret, $header, (string) ($timestamp + 1), $body, $timestamp))->toBeFalse()
        ->and(WebhookSignature::verify(WebhookSignature::generateSecret(), $header, (string) $timestamp, $body, $timestamp))->toBeFalse()
        ->and(WebhookSignature::verify($secret, 'v0='.$signature, (string) $timestamp, $body, $timestamp))->toBeFalse();
});

it('o receptor recusa fora da janela de 5 minutos', function (): void {
    $secret = WebhookSignature::generateSecret();
    $body = '{"ok":true}';
    $timestamp = 1789142400;
    $header = WebhookSignature::header([$secret], $timestamp, $body);

    expect(WebhookSignature::verify($secret, $header, (string) $timestamp, $body, $timestamp + 300))->toBeTrue()
        ->and(WebhookSignature::verify($secret, $header, (string) $timestamp, $body, $timestamp + 301))->toBeFalse()
        ->and(WebhookSignature::verify($secret, $header, (string) $timestamp, $body, $timestamp - 301))->toBeFalse()
        ->and(WebhookSignature::verify($secret, $header, 'abc', $body, $timestamp))->toBeFalse();
});

it('gera segredo com 32 bytes aleatórios e prefixo whsec_', function (): void {
    $a = WebhookSignature::generateSecret();
    $b = WebhookSignature::generateSecret();

    expect($a)->toStartWith('whsec_')
        ->and($a)->not->toBe($b)
        ->and(strlen(base64_decode(strtr(substr($a, 6), '-_', '+/'))))->toBe(32)
        ->and(WebhookSignature::hint($a))->toBe('…'.substr($a, -4));
});

it('a entrega real é verificável pelo receptor com o segredo mostrado no cadastro', function (): void {
    ['organization' => $organization, 'owner' => $owner] = webhookOrg();
    ['secret' => $secret] = makeEndpoint($organization, $owner);
    Http::fake(['*' => Http::response('ok', 200)]);

    ['envelope' => $envelope] = webhookEnvelope($organization, $owner);
    recordAudit($envelope, AuditEventType::EnvelopeSent);

    Http::assertSentCount(1);
    $request = webhookRequests()[0];
    $delivery = WebhookDelivery::withoutOrganizationScope()->sole();

    expect($request->url())->toBe(WEBHOOK_TEST_URL)
        ->and($request->method())->toBe('POST')
        ->and(headerOf($request, 'Content-Type'))->toBe('application/json')
        ->and(headerOf($request, WebhookSignature::HEADER_DELIVERY))->toBe($delivery->ulid)
        ->and(headerOf($request, WebhookSignature::HEADER_EVENT))->toBe('envelope.sent')
        ->and(headerOf($request, WebhookSignature::HEADER_ATTEMPT))->toBe('1')
        ->and($request->body())->toBe($delivery->payload)
        ->and(WebhookSignature::verify(
            $secret,
            (string) headerOf($request, WebhookSignature::HEADER_SIGNATURE),
            (string) headerOf($request, WebhookSignature::HEADER_TIMESTAMP),
            $request->body(),
            Carbon::now()->getTimestamp(),
        ))->toBeTrue();
});

it('segredo é guardado cifrado e nunca aparece na serialização do modelo', function (): void {
    ['organization' => $organization, 'owner' => $owner] = webhookOrg();
    ['endpoint' => $endpoint, 'secret' => $secret] = makeEndpoint($organization, $owner);

    $raw = (string) DB::table('webhook_endpoints')->where('id', $endpoint->id)->value('secret');

    expect($raw)->not->toContain($secret)
        ->and(decrypt($raw, false))->toBe($secret)
        ->and(json_encode($endpoint->fresh()))->not->toContain($secret)
        ->and($endpoint->fresh()->toArray())->not->toHaveKey('secret');
});

it('segredo antigo vale só dentro da janela de rotação', function (): void {
    Carbon::setTestNow('2026-09-11 12:00:00');
    ['organization' => $organization, 'owner' => $owner] = webhookOrg();
    ['endpoint' => $endpoint, 'secret' => $old] = makeEndpoint($organization, $owner);
    Http::fake(['*' => Http::response('ok', 200)]);

    $new = app(WebhookEndpointManager::class)->rotateSecret($endpoint->fresh(), 24);
    expect($new)->not->toBe($old);

    ['envelope' => $envelope] = webhookEnvelope($organization, $owner);

    // Dentro da janela: duas assinaturas; o receptor com o segredo ANTIGO ainda valida.
    recordAudit($envelope, AuditEventType::EnvelopeSent);
    $during = webhookRequests()[0];
    $header = (string) headerOf($during, WebhookSignature::HEADER_SIGNATURE);
    $timestamp = (string) headerOf($during, WebhookSignature::HEADER_TIMESTAMP);

    expect(substr_count($header, 'v1='))->toBe(2)
        ->and(WebhookSignature::verify($old, $header, $timestamp, $during->body(), Carbon::now()->getTimestamp()))->toBeTrue()
        ->and(WebhookSignature::verify($new, $header, $timestamp, $during->body(), Carbon::now()->getTimestamp()))->toBeTrue();

    // Um segundo depois do fim da janela: só o novo assina.
    Carbon::setTestNow(Carbon::now()->addHours(24)->addSecond());
    recordAudit($envelope, AuditEventType::EnvelopeCanceled);
    $after = webhookRequests()[1];
    $header = (string) headerOf($after, WebhookSignature::HEADER_SIGNATURE);
    $timestamp = (string) headerOf($after, WebhookSignature::HEADER_TIMESTAMP);

    expect(substr_count($header, 'v1='))->toBe(1)
        ->and(WebhookSignature::verify($new, $header, $timestamp, $after->body(), Carbon::now()->getTimestamp()))->toBeTrue()
        ->and(WebhookSignature::verify($old, $header, $timestamp, $after->body(), Carbon::now()->getTimestamp()))->toBeFalse();

    // A varredura apaga o segredo anterior vencido.
    $this->artisan('webhooks:retry')->assertSuccessful();
    expect(WebhookEndpoint::withoutOrganizationScope()->find($endpoint->id)->previous_secret)->toBeNull();
});

it('encerrar a convivência invalida o segredo anterior na hora; nova rotação mantém só dois', function (): void {
    ['organization' => $organization, 'owner' => $owner] = webhookOrg();
    ['endpoint' => $endpoint, 'secret' => $first] = makeEndpoint($organization, $owner);
    $manager = app(WebhookEndpointManager::class);

    $second = $manager->rotateSecret($endpoint->fresh(), 24);
    $third = $manager->rotateSecret($endpoint->fresh(), 24);

    expect($endpoint->fresh()->signingSecrets())->toBe([$third, $second]);

    $manager->expirePreviousSecret($endpoint->fresh());

    expect($endpoint->fresh()->signingSecrets())->toBe([$third])
        ->and($endpoint->fresh()->signingSecrets())->not->toContain($first);

    $manager->rotateSecret($endpoint->fresh(), 0);
    expect($endpoint->fresh()->signingSecrets())->toHaveCount(1);
});
