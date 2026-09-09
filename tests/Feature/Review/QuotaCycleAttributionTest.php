<?php

/*
|--------------------------------------------------------------------------
| Revisão adversarial final — ledger de cota: crédito sem débito no ciclo
|--------------------------------------------------------------------------
|
| DEFEITO: a liberação de um consumo não olha a QUAL CICLO ele pertence.
|
| `PlanLedger::release()` (app/Services/Plans/PlanLedger.php:216-224) decide a coluna pelo
| estado anterior do consumo e decrementa o contador da assinatura:
|
|     $column = $from === PlanConsumptionStatus::Reserved ? 'envelopes_reserved' : 'envelopes_used';
|     $subscription->forceFill([$column => max(0, (int) $subscription->getAttribute($column) - $quantity)])->save();
|
| Só que `ActivateSubscription::applyCycle()` (Billing/ActivateSubscription.php:158-172)
| ZERA `envelopes_used` a cada renovação — o contador passa a medir apenas o ciclo
| corrente, enquanto `plan_consumptions` continua guardando as linhas de todos os ciclos,
| todas apontando para a MESMA `subscription_id`.
|
| Como `CancelEnvelope::releaseIfUntouched()` (Sending/CancelEnvelope.php:132) libera
| qualquer consumo não-liberado desde que ninguém tenha assinado — e um envelope enviado
| perto do fim do ciclo continua `in_progress` por até 90 dias (`expiration_days.max`) —,
| basta cancelar, JÁ NO CICLO NOVO, um envelope enviado no ciclo anterior para que o
| contador do ciclo novo seja debitado de uma unidade que ele nunca consumiu.
|
| O efeito é cota de graça, repetível: cada envelope deixado aberto na virada vale um
| envelope extra no mês seguinte. Nada no ledger, na trilha ou na tela de cobrança acusa —
| `plan_consumptions` fica coerente consigo mesmo e só o contador desnormalizado mente.
|
| Correção esperada: só creditar `envelopes_used` quando o consumo pertence ao ciclo
| vigente (comparar `reserved_at`/`committed_at` com `current_period_start`, ou recontar a
| partir do ledger), como `openReservations()` já faz na renovação.
*/

use App\Enums\PaymentStatus;
use App\Enums\SigningOrder;
use App\Services\Billing\ActivateSubscription;
use App\Services\Envelopes\Sending\CancelEnvelope;
use App\Services\Envelopes\Sending\SendEnvelope;
use App\Services\Plans\Exceptions\SendingBlockedException;
use Illuminate\Support\Carbon;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Sending/Support/SendingHelpers.php';
require_once __DIR__.'/../Billing/Support/BillingHelpers.php';

beforeEach(function (): void {
    $this->withoutVite();
    fakeEmailProvider();
});

it('não credita ao ciclo novo o cancelamento de um envelope consumido no ciclo anterior', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    // Plano pago com cota de 2 documentos por ciclo.
    $plan = paidPlan();
    $plan->forceFill(['envelope_quota' => 2])->save();

    subscribeOrganization($organization, $plan);

    actingAsMember($owner, $organization);

    // -- Ciclo 1: um envelope enviado, ninguém assina, ele fica aberto na virada ---------
    $doCicloAnterior = readyEnvelope($organization, $owner, [
        ['name' => 'Maria Alves', 'email' => 'maria@exemplo.com'],
    ], SigningOrder::Parallel);

    app(SendEnvelope::class)->handle($doCicloAnterior);

    expect(subscriptionFor($organization)->envelopes_used)->toBe(1);

    // -- Renovação: pagamento aprovado do mesmo plano zera o consumo do ciclo -----------
    $subscription = subscriptionFor($organization);

    $payment = pendingPaymentFor($organization, $plan, $subscription);
    $payment->forceFill([
        'status' => PaymentStatus::Approved,
        'paid_at' => Carbon::now(),
        'provider_payment_id' => 'MP-RENOVACAO-1',
    ])->save();

    app(ActivateSubscription::class)->handle($payment);

    expect(subscriptionFor($organization)->envelopes_used)->toBe(0);

    // -- Ciclo 2: a cota inteira é gasta -------------------------------------------------
    foreach ([['Bruno Lima', 'bruno@exemplo.com'], ['Carla Dias', 'carla@exemplo.com']] as [$nome, $email]) {
        app(SendEnvelope::class)->handle(
            readyEnvelope($organization, $owner, [['name' => $nome, 'email' => $email]], SigningOrder::Parallel)
        );
    }

    expect(subscriptionFor($organization)->envelopes_used)->toBe(2);

    // -- Cancelamento do envelope do CICLO ANTERIOR --------------------------------------
    app(CancelEnvelope::class)->handle($doCicloAnterior->fresh(), 'Não vamos mais assinar.');

    // O ciclo novo continua tendo consumido dois documentos: o cancelamento devolve uma
    // unidade do ciclo em que ela foi gasta, nunca do ciclo corrente.
    expect(subscriptionFor($organization)->envelopes_used)
        ->toBe(2, 'o cancelamento de um envelope do ciclo anterior devolveu cota ao ciclo corrente');

    // E, por consequência, a cota do ciclo continua esgotada.
    $extra = readyEnvelope($organization, $owner, [['name' => 'Diego Reis', 'email' => 'diego@exemplo.com']], SigningOrder::Parallel);

    expect(fn () => app(SendEnvelope::class)->handle($extra))->toThrow(SendingBlockedException::class);
});
