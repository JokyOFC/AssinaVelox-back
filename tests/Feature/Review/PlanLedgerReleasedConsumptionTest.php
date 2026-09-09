<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial — ledger de plano
|--------------------------------------------------------------------------
|
| DEFEITO: quando `InvitationDispatcher::dispatchInitial()` falha,
| `SendEnvelope::handle()` libera o consumo (`PlanLedger::release`) e lança
| `dispatch_failed` — mas a fase 1 já foi COMMITADA: o envelope fica `in_progress`, com
| `sent_at`, `expires_at`, `verification_code` e `sent_document_version_id` congelados.
|
| O envelope, portanto, continua vivo, e o remetente entrega todos os convites pela tela
| de detalhe ("Lembrar pendentes" → `POST envelopes.resend` → `ResendInvitations`, que
| aceita destinatários `pending`). O documento percorre o ciclo inteiro — convite, OTP,
| aceite, finalização — **sem nunca ser descontado do plano**:
|
|  - `plan_consumptions` fica `released` para sempre;
|  - `PlanLedger::commit()` só age sobre `reserved`, então nada o recobra;
|  - `PlanLedger::reserve()` devolve a linha `released` existente (busca por
|    `idempotency_key`) sem reabrir a reserva, de modo que nem um novo envio recobraria;
|  - `subscriptions.envelopes_used` permanece 0.
|
| O teste `SendEnvelopeTest`, "falha no despacho dos convites libera a reserva do plano",
| verifica apenas a liberação da reserva; ninguém verifica o que sobra do envelope.
*/

use App\Enums\EnvelopeStatus;
use App\Enums\PlanConsumptionStatus;
use App\Models\Envelope;
use App\Models\PlanConsumption;
use App\Models\RecipientAccessLink;
use App\Services\Envelopes\Sending\AccessLinks;
use App\Services\Envelopes\Sending\Exceptions\SendingException;
use App\Services\Envelopes\Sending\InvitationDispatcher;
use App\Services\Envelopes\Sending\SendEnvelope;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Sending/Support/SendingHelpers.php';

beforeEach(fn () => $this->withoutVite());

it('não deixa um envelope enviado seguir vivo com o consumo do plano liberado', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);

    // Despacho indisponível no instante do envio (fila fora do ar, provedor recusando).
    $this->instance(InvitationDispatcher::class, new class(app(AccessLinks::class)) extends InvitationDispatcher
    {
        public function dispatchInitial(Envelope $envelope): int
        {
            throw new RuntimeException('fila indisponível');
        }
    });

    expect(fn () => app(SendEnvelope::class)->handle($envelope))->toThrow(SendingException::class);

    $envelope = $envelope->fresh();
    $consumption = PlanConsumption::withoutOrganizationScope()->firstOrFail();

    // Ou o envio foi desfeito, ou a cota continua reservada. As duas coisas ao mesmo
    // tempo — envelope vivo e consumo liberado — deixam o plano sem cobrança.
    expect($envelope->status === EnvelopeStatus::InProgress && $consumption->status === PlanConsumptionStatus::Released)
        ->toBeFalse('envelope ficou in_progress com o consumo do plano `released`');
});

it('não permite entregar os convites de um envelope cujo consumo foi liberado', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);

    $this->instance(InvitationDispatcher::class, new class(app(AccessLinks::class)) extends InvitationDispatcher
    {
        public function dispatchInitial(Envelope $envelope): int
        {
            throw new RuntimeException('fila indisponível');
        }
    });

    try {
        app(SendEnvelope::class)->handle($envelope);
    } catch (SendingException) {
        // esperado
    }

    // Despachante real de volta: o remetente clica em "Lembrar pendentes".
    $this->app->forgetInstance(InvitationDispatcher::class);
    actingAsMember($owner, $organization);

    $this->post(route('envelopes.resend', $envelope->fresh()));

    $links = RecipientAccessLink::withoutOrganizationScope()
        ->where('envelope_id', $envelope->id)
        ->whereNull('revoked_at')
        ->count();

    $subscription = subscriptionFor($organization);

    // Se os convites saíram, o plano tem de ter sido debitado.
    expect($links > 0 && (int) $subscription->envelopes_used === 0 && (int) $subscription->envelopes_reserved === 0)
        ->toBeFalse('convites entregues sem nenhum consumo do plano registrado');
});
