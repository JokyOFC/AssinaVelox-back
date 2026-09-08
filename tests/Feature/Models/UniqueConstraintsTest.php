<?php

use App\Models\Envelope;
use App\Models\PaymentWebhookReceipt;
use App\Models\PlanConsumption;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Models\SigningField;
use App\Models\SigningFieldValue;
use App\Models\Subscription;
use Illuminate\Database\QueryException;

it('permite um único aceite por destinatário', function () {
    $recipient = Recipient::factory()->signed()->create();
    SignatureAcceptance::factory()->forRecipient($recipient)->create();

    expect(fn () => SignatureAcceptance::factory()->forRecipient($recipient)->create())
        ->toThrow(QueryException::class);
});

it('permite um único valor por campo de assinatura', function () {
    $field = SigningField::factory()->create();
    $acceptance = SignatureAcceptance::factory()->forRecipient($field->recipient)->create();

    SigningFieldValue::factory()->forField($field, $acceptance)->create();

    expect(fn () => SigningFieldValue::factory()->forField($field, $acceptance)->create())
        ->toThrow(QueryException::class);
});

it('recusa idempotency_key duplicada no ledger de consumo', function () {
    $subscription = Subscription::factory()->active()->create();
    $envelope = Envelope::factory()->forOrganization($subscription->organization)->create();

    PlanConsumption::factory()->forEnvelope($envelope, $subscription)->create();

    expect(PlanConsumption::sendKeyFor($envelope))->toBe("envelope:{$envelope->id}:send");

    expect(fn () => PlanConsumption::factory()->forEnvelope($envelope, $subscription)->create())
        ->toThrow(QueryException::class);
});

it('recusa fingerprint de webhook duplicado para o mesmo provedor', function () {
    $receipt = PaymentWebhookReceipt::factory()->create();

    expect(fn () => PaymentWebhookReceipt::factory()->create([
        'provider' => $receipt->provider,
        'event_fingerprint' => $receipt->event_fingerprint,
    ]))->toThrow(QueryException::class);

    expect(PaymentWebhookReceipt::fingerprint('payment', 123, 'payment.updated'))->toBe('payment:123:payment.updated');
});

it('recusa verification_code duplicado entre envelopes', function () {
    Envelope::factory()->create(['verification_code' => 'ABCDEFGHJKLM']);

    expect(fn () => Envelope::factory()->create(['verification_code' => 'ABCDEFGHJKLM']))
        ->toThrow(QueryException::class);
});
