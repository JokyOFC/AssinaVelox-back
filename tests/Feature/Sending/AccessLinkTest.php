<?php

use App\Enums\AccessLinkPurpose;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\DeliveryAttempt;
use App\Models\Recipient;
use App\Models\RecipientAccessLink;
use App\Services\Envelopes\Sending\AccessLinks;
use App\Services\Envelopes\Sending\SendEnvelope;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/SendingHelpers.php';

beforeEach(function (): void {
    $this->withoutVite();
    $this->provider = fakeEmailProvider();
});

test('o token tem 32 bytes em base64url e só o digest é gravado', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);

    app(SendEnvelope::class)->handle($envelope);

    $recipient = $envelope->recipients()->firstOrFail();
    $issued = app(AccessLinks::class)->issue($recipient, envelope: $envelope->fresh());

    // 32 bytes em base64 sem padding = 43 caracteres do alfabeto URL-safe.
    expect($issued->token)->toHaveLength(43)
        ->and($issued->token)->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($issued->link->token_digest)->toBe(hash('sha256', $issued->token))
        ->and($issued->link->token_digest)->toHaveLength(64);

    // A coluna do token bruto não existe: só há digest.
    expect(RecipientAccessLink::withoutOrganizationScope()
        ->where('token_digest', $issued->token)
        ->exists())->toBeFalse();
});

test('dois tokens seguidos nunca coincidem', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);
    app(SendEnvelope::class)->handle($envelope);

    $recipient = $envelope->recipients()->firstOrFail();
    $links = app(AccessLinks::class);
    $envelope = $envelope->fresh();

    $tokens = collect(range(1, 5))->map(fn (): string => $links->issue($recipient, envelope: $envelope)->token);

    expect($tokens->unique())->toHaveCount(5);
});

test('emitir um link novo revoga o anterior e resolve só o mais recente', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);
    app(SendEnvelope::class)->handle($envelope);
    $envelope = $envelope->fresh();

    $recipient = $envelope->recipients()->firstOrFail();
    $links = app(AccessLinks::class);

    $first = $links->issue($recipient, envelope: $envelope);
    $second = $links->issue($recipient, envelope: $envelope);

    expect($first->link->fresh()->revoked_at)->not->toBeNull()
        ->and($second->link->fresh()->revoked_at)->toBeNull()
        ->and($links->resolve($first->token)?->isUsable())->toBeFalse()
        ->and($links->resolve($second->token)?->isUsable())->toBeTrue();
});

test('o link herda o prazo do envelope e resolve para o destinatário certo', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);
    app(SendEnvelope::class)->handle($envelope);
    $envelope = $envelope->fresh();

    $recipient = $envelope->recipients()->firstOrFail();
    $issued = app(AccessLinks::class)->issue($recipient, envelope: $envelope);

    expect($issued->link->expires_at->toIso8601String())->toBe($envelope->expires_at->toIso8601String())
        ->and($issued->link->document_version_id)->toBe($envelope->sent_document_version_id)
        ->and($issued->link->purpose)->toBe(AccessLinkPurpose::Signing);

    $resolved = app(AccessLinks::class)->resolve($issued->token);

    expect($resolved?->recipient_id)->toBe($recipient->getKey())
        ->and($resolved?->envelope_id)->toBe($envelope->getKey());
});

test('token inexistente ou vazio não resolve', function () {
    $links = app(AccessLinks::class);

    expect($links->resolve(''))->toBeNull()
        ->and($links->resolve(str_repeat('a', 43)))->toBeNull();
});

test('o token do convite chega no corpo do e-mail e em nenhum outro lugar', function () {
    Log::spy();

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);

    app(SendEnvelope::class)->handle($envelope);

    $bodies = $this->provider->bodies();

    // O token existe: alguém precisa conseguir abrir o link. Recuperamos o que está no
    // corpo e conferimos que o digest dele bate com a única linha de link ativa.
    expect($bodies)->toMatch('#/assinar/[A-Za-z0-9_-]{43}#');

    preg_match('#/assinar/([A-Za-z0-9_-]{43})#', $bodies, $matches);
    $token = $matches[1];

    $link = RecipientAccessLink::withoutOrganizationScope()->firstOrFail();

    expect($link->token_digest)->toBe(hash('sha256', $token));

    // E agora o que importa: o token NÃO aparece em nenhum registro persistido.
    $auditPayloads = AuditEvent::withoutOrganizationScope()->get()
        ->map(fn (AuditEvent $event): string => json_encode($event->payload ?? [], JSON_THROW_ON_ERROR))
        ->implode(' ');

    $deliveryDump = DeliveryAttempt::withoutOrganizationScope()->get()
        ->map(fn (DeliveryAttempt $attempt): string => $attempt->to_address
            .$attempt->provider
            .(string) $attempt->provider_message_id
            .(string) $attempt->error_message
            .json_encode($attempt->meta ?? [], JSON_THROW_ON_ERROR))
        ->implode(' ');

    expect($auditPayloads)->not->toContain($token)
        ->and($auditPayloads)->not->toContain($link->token_digest)
        ->and($deliveryDump)->not->toContain($token)
        ->and($deliveryDump)->not->toContain($link->token_digest);

    // Nem em log: nada foi escrito com o token (o provedor de log é outro; aqui o fake).
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});

test('a trilha do convite guarda o e-mail mascarado, nunca o endereço completo', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner, [['name' => 'Maria Alves', 'email' => 'maria@exemplo.com']]);

    app(SendEnvelope::class)->handle($envelope);

    $event = AuditEvent::withoutOrganizationScope()
        ->where('event_type', AuditEventType::InvitationSent->value)
        ->firstOrFail();

    expect($event->payload['to'])->toBe(Recipient::maskEmail('maria@exemplo.com'))
        ->and($event->payload['to'])->not->toBe('maria@exemplo.com')
        ->and($event->payload)->toHaveKey('link')
        ->and($event->payload['link'])->toHaveLength(26);
});

test('markUsed conta os acessos sem invalidar o link', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);
    app(SendEnvelope::class)->handle($envelope);

    $links = app(AccessLinks::class);
    $link = RecipientAccessLink::withoutOrganizationScope()->firstOrFail();

    $links->markUsed($link);
    $links->markUsed($link);

    $link->refresh();

    expect($link->use_count)->toBe(2)
        ->and($link->last_used_at)->not->toBeNull()
        ->and($link->isUsable())->toBeTrue();
});
