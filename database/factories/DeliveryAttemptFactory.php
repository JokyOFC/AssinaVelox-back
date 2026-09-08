<?php

namespace Database\Factories;

use App\Enums\DeliveryChannel;
use App\Enums\DeliveryPurpose;
use App\Enums\DeliveryStatus;
use App\Models\DeliveryAttempt;
use App\Models\Recipient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DeliveryAttempt>
 */
class DeliveryAttemptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'recipient_id' => Recipient::factory(),
            'envelope_id' => fn (array $attributes) => self::recipient($attributes)->envelope_id,
            'organization_id' => fn (array $attributes) => self::recipient($attributes)->organization_id,
            'to_address' => fn (array $attributes) => self::recipient($attributes)->email,
            'channel' => DeliveryChannel::Email,
            'provider' => 'log',
            'purpose' => DeliveryPurpose::Invitation,
            'status' => DeliveryStatus::Queued,
            'provider_message_id' => null,
            'error_message' => null,
            'correlation_id' => (string) Str::ulid(),
            'queued_at' => now(),
            'sent_at' => null,
            'delivered_at' => null,
            'meta' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected static function recipient(array $attributes): Recipient
    {
        return Recipient::withoutOrganizationScope()->whereKey($attributes['recipient_id'])->firstOrFail();
    }

    public function forRecipient(Recipient $recipient): static
    {
        return $this->state(fn () => [
            'recipient_id' => $recipient->id,
            'envelope_id' => $recipient->envelope_id,
            'organization_id' => $recipient->organization_id,
            'to_address' => $recipient->email,
        ]);
    }

    public function purpose(DeliveryPurpose $purpose): static
    {
        return $this->state(fn () => ['purpose' => $purpose]);
    }

    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => DeliveryStatus::Sent,
            'provider_message_id' => (string) Str::uuid(),
            'sent_at' => now()->subMinutes(5),
        ]);
    }

    public function delivered(): static
    {
        return $this->sent()->state(fn () => [
            'status' => DeliveryStatus::Delivered,
            'delivered_at' => now()->subMinutes(4),
        ]);
    }

    public function failed(?string $error = null): static
    {
        return $this->state(fn () => [
            'status' => DeliveryStatus::Failed,
            'error_message' => $error ?? 'Conexão recusada pelo servidor de e-mail.',
        ]);
    }

    public function bounced(): static
    {
        return $this->sent()->state(fn () => [
            'status' => DeliveryStatus::Bounced,
            'error_message' => 'Endereço de e-mail inexistente.',
        ]);
    }
}
