<?php

namespace Database\Factories;

use App\Enums\ActorType;
use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AuditEvent>
 */
class AuditEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'envelope_id' => Envelope::factory(),
            'organization_id' => fn (array $attributes) => Envelope::withoutOrganizationScope()
                ->withTrashed()
                ->whereKey($attributes['envelope_id'])->firstOrFail()
                ->organization_id,
            'recipient_id' => null,
            'actor_type' => ActorType::System,
            'actor_id' => null,
            'event_type' => AuditEventType::EnvelopeCreated,
            'payload' => [],
            'ip_address' => null,
            'user_agent' => null,
            'correlation_id' => (string) Str::ulid(),
            'occurred_at' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }

    public function forEnvelope(Envelope $envelope): static
    {
        return $this->state(fn () => [
            'envelope_id' => $envelope->id,
            'organization_id' => $envelope->organization_id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function ofType(AuditEventType $type, array $payload = []): static
    {
        return $this->state(fn () => ['event_type' => $type, 'payload' => $payload]);
    }

    public function byUser(User $user, ?string $ip = null): static
    {
        return $this->state(fn () => [
            'actor_type' => ActorType::User,
            'actor_id' => $user->id,
            'ip_address' => $ip ?? fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ]);
    }

    public function byRecipient(Recipient $recipient, ?string $ip = null): static
    {
        return $this->state(fn () => [
            'actor_type' => ActorType::Recipient,
            'actor_id' => $recipient->id,
            'recipient_id' => $recipient->id,
            'envelope_id' => $recipient->envelope_id,
            'organization_id' => $recipient->organization_id,
            'ip_address' => $ip ?? fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ]);
    }

    public function bySystem(): static
    {
        return $this->state(fn () => ['actor_type' => ActorType::System, 'actor_id' => null]);
    }

    public function at(\DateTimeInterface $occurredAt): static
    {
        return $this->state(fn () => ['occurred_at' => $occurredAt]);
    }
}
