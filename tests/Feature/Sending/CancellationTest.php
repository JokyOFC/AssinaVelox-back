<?php

use App\Enums\AuditEventType;
use App\Enums\DeliveryPurpose;
use App\Enums\EnvelopeStatus;
use App\Enums\PlanConsumptionStatus;
use App\Enums\RecipientStatus;
use App\Enums\SigningOrder;
use App\Models\AuditEvent;
use App\Models\DeliveryAttempt;
use App\Models\PlanConsumption;
use App\Models\RecipientAccessLink;
use App\Services\Envelopes\Sending\CancelEnvelope;
use App\Services\Envelopes\Sending\SendEnvelope;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/SendingHelpers.php';

beforeEach(function (): void {
    $this->withoutVite();
    $this->provider = fakeEmailProvider();
});

test('cancelar revoga os links, encerra os pendentes e avisa quem já tinha sido convidado', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    $envelope = readyEnvelope($organization, $owner, [
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.com'],
        ['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.com'],
    ], SigningOrder::Sequential);

    app(SendEnvelope::class)->handle($envelope);
    $envelope = $envelope->fresh();

    $result = app(CancelEnvelope::class)->handle($envelope, 'Contrato renegociado.');

    expect($result['canceled'])->toBeTrue()
        ->and($result['notified'])->toBe(1); // só Maria foi convidada

    $envelope->refresh();

    expect($envelope->status)->toBe(EnvelopeStatus::Canceled)
        ->and($envelope->canceled_at)->not->toBeNull()
        ->and($envelope->setting('cancel_reason'))->toBe('Contrato renegociado.');

    expect($envelope->recipients()->pluck('status')->unique()->all())->toBe([RecipientStatus::Canceled]);

    expect(RecipientAccessLink::withoutOrganizationScope()->whereNull('revoked_at')->count())->toBe(0);

    expect($this->provider->to('maria@exemplo.com'))->toHaveCount(2)   // convite + cancelamento
        ->and($this->provider->to('carlos@exemplo.com'))->toHaveCount(0);

    $attempt = DeliveryAttempt::withoutOrganizationScope()->latest('id')->firstOrFail();

    expect($attempt->purpose)->toBe(DeliveryPurpose::Canceled);
});

test('cancelar antes de qualquer assinatura libera o consumo do plano', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);

    app(SendEnvelope::class)->handle($envelope);

    expect(subscriptionFor($organization)->envelopes_used)->toBe(1);

    $result = app(CancelEnvelope::class)->handle($envelope->fresh());

    expect($result['consumption_released'])->toBeTrue()
        ->and(PlanConsumption::withoutOrganizationScope()->firstOrFail()->status)->toBe(PlanConsumptionStatus::Released)
        ->and(subscriptionFor($organization)->envelopes_used)->toBe(0);

    expect(AuditEvent::withoutOrganizationScope()
        ->where('event_type', AuditEventType::PlanConsumptionReleased->value)
        ->count())->toBe(1);
});

test('cancelar depois de uma assinatura NÃO devolve a cota', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner, [
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.com'],
        ['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.com'],
    ], SigningOrder::Parallel);

    app(SendEnvelope::class)->handle($envelope);
    $envelope = $envelope->fresh();

    $envelope->recipients()->where('email', 'maria@exemplo.com')->firstOrFail()
        ->forceFill(['status' => RecipientStatus::Signed, 'signed_at' => now()])->save();

    $result = app(CancelEnvelope::class)->handle($envelope);

    expect($result['consumption_released'])->toBeFalse()
        ->and(PlanConsumption::withoutOrganizationScope()->firstOrFail()->status)->toBe(PlanConsumptionStatus::Committed)
        ->and(subscriptionFor($organization)->envelopes_used)->toBe(1);
});

test('cancelar é idempotente', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);
    app(SendEnvelope::class)->handle($envelope);

    $cancellation = app(CancelEnvelope::class);

    $cancellation->handle($envelope->fresh());
    $second = $cancellation->handle($envelope->fresh());

    expect($second['canceled'])->toBeFalse()
        ->and($second['notified'])->toBe(0)
        ->and(AuditEvent::withoutOrganizationScope()->where('event_type', AuditEventType::EnvelopeCanceled->value)->count())->toBe(1);
});

test('cancelar pela rota HTTP responde com a contagem de avisados', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);
    app(SendEnvelope::class)->handle($envelope);

    actingAsMember($owner, $organization);

    $this->post(route('envelopes.cancel', $envelope), ['reason' => 'Não vamos mais assinar.'])
        ->assertRedirect()
        ->assertSessionHas('success', 'Documento cancelado. 1 signatário(s) avisado(s).');

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Canceled);
});
