<?php

namespace Database\Factories;

use App\Enums\PaymentEnvironment;
use App\Enums\PaymentStatus;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'subscription_id' => null,
            'plan_id' => Plan::factory(),
            'provider' => 'mercadopago',
            'external_reference' => (string) Str::ulid(),
            'provider_preference_id' => fake()->numerify('##########-').fake()->uuid(),
            'provider_payment_id' => null,
            'status' => PaymentStatus::Pending,
            'status_detail' => 'pending_waiting_payment',
            'amount_cents' => fn (array $attributes) => Plan::whereKey($attributes['plan_id'])->firstOrFail()->price_cents,
            'currency' => 'BRL',
            'payment_method_id' => null,
            'payer_email_masked' => 'p***@exemplo.com',
            'checkout_url' => 'https://sandbox.mercadopago.com.br/checkout/v1/redirect?pref_id='.fake()->uuid(),
            'paid_at' => null,
            'activated_at' => null,
            'environment' => PaymentEnvironment::Sandbox,
        ];
    }

    public function forSubscription(Subscription $subscription): static
    {
        return $this->state(fn () => [
            'organization_id' => $subscription->organization_id,
            'subscription_id' => $subscription->id,
            'plan_id' => $subscription->plan_id,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => PaymentStatus::Pending, 'status_detail' => 'pending_waiting_payment']);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Approved,
            'status_detail' => 'accredited',
            'provider_payment_id' => (string) fake()->unique()->numberBetween(1_000_000_000, 9_999_999_999),
            'payment_method_id' => fake()->randomElement(['pix', 'master', 'visa', 'bolbradesco']),
            'paid_at' => now()->subMinutes(10),
            'activated_at' => now()->subMinutes(9),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Rejected,
            'status_detail' => 'cc_rejected_insufficient_amount',
            'provider_payment_id' => (string) fake()->unique()->numberBetween(1_000_000_000, 9_999_999_999),
            'payment_method_id' => 'master',
        ]);
    }

    public function refunded(): static
    {
        return $this->approved()->state(fn () => [
            'status' => PaymentStatus::Refunded,
            'status_detail' => 'refunded',
        ]);
    }

    public function production(): static
    {
        return $this->state(fn () => ['environment' => PaymentEnvironment::Production]);
    }
}
