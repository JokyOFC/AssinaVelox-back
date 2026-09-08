<?php

namespace Database\Factories;

use App\Enums\WebhookProcessingStatus;
use App\Models\PaymentWebhookReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentWebhookReceipt>
 */
class PaymentWebhookReceiptFactory extends Factory
{
    public function definition(): array
    {
        $paymentId = fake()->unique()->numberBetween(1_000_000_000, 9_999_999_999);

        return [
            'provider' => 'mercadopago',
            'event_fingerprint' => PaymentWebhookReceipt::fingerprint('payment', $paymentId, 'payment.updated'),
            'topic' => 'payment',
            'action' => 'payment.updated',
            'payload' => [
                'type' => 'payment',
                'action' => 'payment.updated',
                'data' => ['id' => (string) $paymentId],
            ],
            'signature_header' => 'ts=1700000000,v1='.hash('sha256', (string) $paymentId),
            'signature_valid' => true,
            'received_at' => now(),
            'processed_at' => null,
            'processing_status' => WebhookProcessingStatus::Received,
            'error' => null,
        ];
    }

    public function processed(): static
    {
        return $this->state(fn () => [
            'processing_status' => WebhookProcessingStatus::Processed,
            'processed_at' => now(),
        ]);
    }

    public function ignored(): static
    {
        return $this->state(fn () => [
            'processing_status' => WebhookProcessingStatus::Ignored,
            'processed_at' => now(),
        ]);
    }

    public function failed(?string $error = null): static
    {
        return $this->state(fn () => [
            'processing_status' => WebhookProcessingStatus::Failed,
            'processed_at' => now(),
            'error' => $error ?? 'Pagamento não encontrado na API do provedor.',
        ]);
    }

    public function invalidSignature(): static
    {
        return $this->state(fn () => ['signature_valid' => false]);
    }
}
