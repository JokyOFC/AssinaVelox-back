<?php

use App\Enums\AuditEventType;
use App\Enums\DeliveryChannel;
use App\Enums\DeliveryPurpose;
use App\Enums\DeliveryStatus;
use App\Enums\EnvelopeStatus;
use App\Enums\PlanConsumptionStatus;
use App\Enums\RecipientStatus;
use App\Enums\SigningOrder;
use App\Enums\SubscriptionStatus;
use App\Models\AuditEvent;
use App\Models\DeliveryAttempt;
use App\Models\Envelope;
use App\Models\PlanConsumption;
use App\Models\Recipient;
use App\Models\RecipientAccessLink;
use App\Services\Envelopes\Sending\AccessLinks;
use App\Services\Envelopes\Sending\Exceptions\SendingException;
use App\Services\Envelopes\Sending\InvitationDispatcher;
use App\Services\Envelopes\Sending\SendEnvelope;
use App\Services\Plans\Exceptions\SendingBlockedException;
use Illuminate\Support\Carbon;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/Support/SendingHelpers.php';

beforeEach(function (): void {
    $this->withoutVite();
    $this->provider = fakeEmailProvider();
});

test('o envio congela a versão do documento, gera código de verificação e define o prazo', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);
    $versionId = $envelope->document->current_version_id;

    actingAsMember($owner, $organization);

    // `?sent=1` é o contrato de ROUTES §2.6: é assim que o detalhe sabe mostrar a tela
    // "Enviado para assinatura" de DESIGN §6.5.
    $this->post(route('envelopes.send', $envelope))
        ->assertRedirect(route('envelopes.show', ['envelope' => $envelope, 'sent' => 1]));

    $envelope->refresh();

    expect($envelope->status)->toBe(EnvelopeStatus::InProgress)
        ->and($envelope->sent_document_version_id)->toBe($versionId)
        ->and($envelope->sent_at)->not->toBeNull()
        ->and($envelope->verification_code)->toHaveLength(12)
        ->and($envelope->verification_code)->toMatch('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{12}$/')
        ->and($envelope->terms_version)->toBe((string) config('assinavelox.terms_version'));

    // Q22: 23:59:59 do fuso da organização, `expiration_days` dias depois do envio.
    $deadline = $envelope->expires_at->setTimezone($organization->timezone);

    expect($deadline->format('H:i:s'))->toBe('23:59:59')
        ->and($deadline->toDateString())->toBe(Carbon::now()->setTimezone($organization->timezone)->addDays(30)->toDateString());
});

test('o código de verificação não repete entre envelopes', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    setPlanQuota($organization, null);

    $codes = [];

    foreach (range(1, 5) as $i) {
        $envelope = readyEnvelope($organization, $owner, [['name' => 'Maria', 'email' => "m{$i}@exemplo.com"]]);
        app(SendEnvelope::class)->handle($envelope);
        $codes[] = $envelope->fresh()->verification_code;
    }

    expect(array_unique($codes))->toHaveCount(5);
});

test('envelope sem completude é recusado e nada é consumido', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    // Rascunho sem documento nem signatários.
    $envelope = Envelope::factory()->forOrganization($organization, $owner)->draft()->create();

    actingAsMember($owner, $organization);

    $this->post(route('envelopes.send', $envelope))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Draft)
        ->and(PlanConsumption::withoutOrganizationScope()->count())->toBe(0)
        ->and($this->provider->count())->toBe(0);
});

test('a completude é revalidada sob lock: signatário removido depois de abrir o wizard bloqueia o envio', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);

    expect($envelope->status)->toBe(EnvelopeStatus::Ready);

    // Outra aba removeu o signatário; o status no banco ainda diz `ready`.
    $envelope->recipients()->delete();

    expect(fn () => app(SendEnvelope::class)->handle($envelope))
        ->toThrow(SendingException::class, 'Não é possível enviar: adicione pelo menos um signatário.');

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Draft);
});

test('sequencial notifica apenas o primeiro; os demais ficam aguardando a vez', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner, [
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.com'],
        ['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.com'],
    ]);

    $result = app(SendEnvelope::class)->handle($envelope);

    expect($result['invitations'])->toBe(1);

    $maria = Recipient::withoutOrganizationScope()->where('email', 'maria@exemplo.com')->firstOrFail();
    $carlos = Recipient::withoutOrganizationScope()->where('email', 'carlos@exemplo.com')->firstOrFail();

    expect($maria->status)->toBe(RecipientStatus::Notified)
        ->and($maria->notification_count)->toBe(1)
        ->and($carlos->status)->toBe(RecipientStatus::Pending)
        ->and($carlos->notification_count)->toBe(0);

    expect(RecipientAccessLink::withoutOrganizationScope()->count())->toBe(1)
        ->and($this->provider->to('maria@exemplo.com'))->toHaveCount(1)
        ->and($this->provider->to('carlos@exemplo.com'))->toHaveCount(0);
});

test('paralelo notifica todos de uma vez', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner, [
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.com'],
        ['name' => 'Carlos Mendes', 'email' => 'carlos@exemplo.com'],
        ['name' => 'Ana Prado', 'email' => 'ana@exemplo.com'],
    ], SigningOrder::Parallel);

    $result = app(SendEnvelope::class)->handle($envelope);

    expect($result['invitations'])->toBe(3)
        ->and(RecipientAccessLink::withoutOrganizationScope()->count())->toBe(3)
        ->and($this->provider->count())->toBe(3);

    expect(Recipient::withoutOrganizationScope()->pluck('status')->unique()->all())
        ->toBe([RecipientStatus::Notified]);
});

test('enviar duas vezes não duplica consumo do plano nem convites', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);

    app(SendEnvelope::class)->handle($envelope);

    // Segunda chamada com o MESMO model em memória (status velho), como no clique duplo.
    expect(fn () => app(SendEnvelope::class)->handle($envelope))
        ->toThrow(SendingException::class, 'Este documento já foi enviado para assinatura.');

    expect(PlanConsumption::withoutOrganizationScope()->count())->toBe(1)
        ->and(RecipientAccessLink::withoutOrganizationScope()->count())->toBe(1)
        ->and($this->provider->count())->toBe(1);

    $subscription = subscriptionFor($organization);

    expect($subscription->envelopes_used)->toBe(1)
        ->and($subscription->envelopes_reserved)->toBe(0);
});

test('a chave de idempotência do ledger é envelope:{id}:send e o consumo termina committed', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);

    app(SendEnvelope::class)->handle($envelope);

    $consumption = PlanConsumption::withoutOrganizationScope()->firstOrFail();

    expect($consumption->idempotency_key)->toBe('envelope:'.$envelope->getKey().':send')
        ->and($consumption->status)->toBe(PlanConsumptionStatus::Committed)
        ->and($consumption->reserved_at)->not->toBeNull()
        ->and($consumption->committed_at)->not->toBeNull()
        ->and($consumption->released_at)->toBeNull();

    $types = AuditEvent::withoutOrganizationScope()->pluck('event_type')->all();

    expect($types)->toContain(AuditEventType::PlanConsumptionReserved)
        ->and($types)->toContain(AuditEventType::PlanConsumptionCommitted)
        ->and($types)->toContain(AuditEventType::EnvelopeSent)
        ->and($types)->toContain(AuditEventType::InvitationSent);
});

test('falha no despacho dos convites libera a reserva do plano', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);

    // Despachante que estoura ao emitir o convite (banco fora do ar, fila indisponível…).
    $this->instance(InvitationDispatcher::class, new class(app(AccessLinks::class)) extends InvitationDispatcher
    {
        public function dispatchInitial(Envelope $envelope): int
        {
            throw new RuntimeException('fila indisponível');
        }
    });

    expect(fn () => app(SendEnvelope::class)->handle($envelope))
        ->toThrow(SendingException::class, 'Nenhum documento foi descontado do seu plano');

    $consumption = PlanConsumption::withoutOrganizationScope()->firstOrFail();
    $subscription = subscriptionFor($organization);

    expect($consumption->status)->toBe(PlanConsumptionStatus::Released)
        ->and($consumption->released_at)->not->toBeNull()
        ->and($subscription->envelopes_reserved)->toBe(0)
        ->and($subscription->envelopes_used)->toBe(0);
});

test('assinatura inadimplente bloqueia o envio com mensagem clara', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);

    subscriptionFor($organization)->forceFill(['status' => SubscriptionStatus::PastDue])->save();

    actingAsMember($owner, $organization);

    $this->post(route('envelopes.send', $envelope))
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'pagamento do plano está em atraso'));

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Ready)
        ->and(PlanConsumption::withoutOrganizationScope()->count())->toBe(0);
});

test('cota esgotada bloqueia o envio', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    setPlanQuota($organization, 2);
    subscriptionFor($organization)->forceFill(['envelopes_used' => 2])->save();

    $envelope = readyEnvelope($organization, $owner);

    expect(fn () => app(SendEnvelope::class)->handle($envelope))
        ->toThrow(SendingBlockedException::class, 'Você já usou os 2 documentos do seu plano neste período.');

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Ready);
});

test('a reserva pendente de outro envelope também conta na cota', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    setPlanQuota($organization, 1);
    subscriptionFor($organization)->forceFill(['envelopes_reserved' => 1])->save();

    $envelope = readyEnvelope($organization, $owner);

    expect(fn () => app(SendEnvelope::class)->handle($envelope))
        ->toThrow(SendingBlockedException::class);
});

test('cada convite gera um delivery_attempt sent, com propósito e correlação', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);

    app(SendEnvelope::class)->handle($envelope);

    $attempt = DeliveryAttempt::withoutOrganizationScope()->firstOrFail();

    expect($attempt->status)->toBe(DeliveryStatus::Sent)
        ->and($attempt->delivered_at)->toBeNull()
        ->and($attempt->purpose)->toBe(DeliveryPurpose::Invitation)
        ->and($attempt->channel)->toBe(DeliveryChannel::Email)
        ->and($attempt->to_address)->toBe('maria@exemplo.com')
        ->and($attempt->provider)->toBe('fake_provider')
        ->and($attempt->provider_message_id)->not->toBeNull()
        ->and($attempt->envelope_id)->toBe($envelope->getKey())
        ->and($attempt->correlation_id)->toHaveLength(26);

    // A trilha e a entrega compartilham o mesmo correlation_id.
    $invitation = AuditEvent::withoutOrganizationScope()
        ->where('event_type', AuditEventType::InvitationSent->value)
        ->firstOrFail();

    expect($invitation->correlation_id)->toBe($attempt->correlation_id);
});
