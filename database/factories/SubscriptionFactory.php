<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'plan_id' => Plan::factory(),
            'status' => SubscriptionStatus::Pending,
            'started_at' => null,
            'current_period_start' => null,
            'current_period_end' => null,
            'canceled_at' => null,
            'cancel_at_period_end' => false,
            'envelopes_used' => 0,
            'envelopes_reserved' => 0,
            'provider' => null,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn () => ['organization_id' => $organization->id]);
    }

    public function ofPlan(Plan $plan): static
    {
        return $this->state(fn () => ['plan_id' => $plan->id]);
    }

    public function active(): static
    {
        return $this->state(function () {
            $start = now()->startOfMonth();

            return [
                'status' => SubscriptionStatus::Active,
                'started_at' => $start,
                'current_period_start' => $start,
                'current_period_end' => $start->copy()->addMonth(),
            ];
        });
    }

    public function trialing(): static
    {
        return $this->active()->state(fn () => ['status' => SubscriptionStatus::Trialing]);
    }

    public function pastDue(): static
    {
        return $this->state(function () {
            $start = now()->subMonths(2)->startOfMonth();

            return [
                'status' => SubscriptionStatus::PastDue,
                'started_at' => $start,
                'current_period_start' => $start->copy()->addMonth(),
                'current_period_end' => now()->subDays(4),
                'provider' => 'mercadopago',
            ];
        });
    }

    public function canceled(): static
    {
        return $this->active()->state(fn () => [
            'status' => SubscriptionStatus::Canceled,
            'canceled_at' => now()->subDay(),
        ]);
    }

    public function expired(): static
    {
        return $this->pastDue()->state(fn () => [
            'status' => SubscriptionStatus::Expired,
            'current_period_end' => now()->subDays(20),
        ]);
    }

    public function withUsage(int $used, int $reserved = 0): static
    {
        return $this->state(fn () => [
            'envelopes_used' => $used,
            'envelopes_reserved' => $reserved,
        ]);
    }
}
