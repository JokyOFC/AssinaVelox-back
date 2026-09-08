<?php

namespace Database\Factories;

use App\Enums\PlanConsumptionStatus;
use App\Models\Envelope;
use App\Models\PlanConsumption;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanConsumption>
 */
class PlanConsumptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory()->active(),
            'organization_id' => fn (array $attributes) => Subscription::withoutOrganizationScope()
                ->whereKey($attributes['subscription_id'])->firstOrFail()
                ->organization_id,
            'envelope_id' => fn (array $attributes) => Envelope::factory()
                ->forOrganization(Subscription::withoutOrganizationScope()->whereKey($attributes['subscription_id'])->firstOrFail()->organization)
                ->create()
                ->id,
            'idempotency_key' => fn (array $attributes) => PlanConsumption::sendKeyFor($attributes['envelope_id']),
            'quantity' => 1,
            'status' => PlanConsumptionStatus::Reserved,
            'reserved_at' => now(),
            'committed_at' => null,
            'released_at' => null,
        ];
    }

    public function forEnvelope(Envelope $envelope, Subscription $subscription): static
    {
        return $this->state(fn () => [
            'subscription_id' => $subscription->id,
            'organization_id' => $subscription->organization_id,
            'envelope_id' => $envelope->id,
            'idempotency_key' => PlanConsumption::sendKeyFor($envelope),
        ]);
    }

    public function committed(): static
    {
        return $this->state(fn () => [
            'status' => PlanConsumptionStatus::Committed,
            'committed_at' => now(),
        ]);
    }

    public function released(): static
    {
        return $this->state(fn () => [
            'status' => PlanConsumptionStatus::Released,
            'released_at' => now(),
        ]);
    }
}
