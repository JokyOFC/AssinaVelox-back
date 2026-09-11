<?php

namespace Database\Factories;

use App\Enums\AuthMethod;
use App\Enums\RecipientRole;
use App\Enums\RecipientStatus;
use App\Models\Envelope;
use App\Models\Recipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recipient>
 */
class RecipientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'envelope_id' => Envelope::factory(),
            'organization_id' => fn (array $attributes) => Envelope::withoutOrganizationScope()
                ->withTrashed()
                ->whereKey($attributes['envelope_id'])->firstOrFail()
                ->organization_id,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional(0.4)->numerify('+55 11 9####-####'),
            'role' => RecipientRole::Signer,
            'role_label' => null,
            'order_index' => 1,
            // Espelha a vez por padrão: dados de teste antigos continuam na mesma ordem.
            'position' => fn (array $attributes): int => (int) ($attributes['order_index'] ?? 1),
            'status' => RecipientStatus::Pending,
            'auth_method' => AuthMethod::EmailOtp,
            'notification_count' => 0,
        ];
    }

    public function forEnvelope(Envelope $envelope, int $orderIndex = 1): static
    {
        return $this->state(fn () => [
            'envelope_id' => $envelope->id,
            'organization_id' => $envelope->organization_id,
            'order_index' => $orderIndex,
        ]);
    }

    public function order(int $orderIndex): static
    {
        return $this->state(fn () => ['order_index' => $orderIndex]);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => RecipientStatus::Pending]);
    }

    public function notified(): static
    {
        return $this->state(fn () => [
            'status' => RecipientStatus::Notified,
            'notification_count' => 1,
            'last_notified_at' => fake()->dateTimeBetween('-5 days', '-1 hour'),
        ]);
    }

    public function viewed(): static
    {
        return $this->notified()->state(fn () => ['status' => RecipientStatus::Viewed]);
    }

    public function signed(): static
    {
        return $this->notified()->state(fn () => [
            'status' => RecipientStatus::Signed,
            'signed_at' => fake()->dateTimeBetween('-5 days', 'now'),
        ]);
    }

    public function refused(?string $reason = null): static
    {
        return $this->notified()->state(fn () => [
            'status' => RecipientStatus::Refused,
            'refused_at' => fake()->dateTimeBetween('-5 days', 'now'),
            'refusal_reason' => $reason ?? fake()->randomElement([
                'Valores divergentes do combinado.',
                'Dados cadastrais incorretos.',
                'Prefiro revisar com meu advogado antes.',
            ]),
        ]);
    }

    public function expired(): static
    {
        return $this->notified()->state(fn () => ['status' => RecipientStatus::Expired]);
    }

    public function canceled(): static
    {
        return $this->state(fn () => ['status' => RecipientStatus::Canceled]);
    }
}
