<?php

namespace Database\Factories;

use App\Enums\EnvelopeStatus;
use App\Enums\SigningOrder;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Envelope>
 */
class EnvelopeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'folder_id' => null,
            'created_by_user_id' => User::factory(),
            'title' => fake()->randomElement([
                'Contrato de prestação de serviços',
                'Contrato de locação residencial',
                'Termo de confidencialidade (NDA)',
                'Proposta comercial',
                'Aditivo contratual',
                'Procuração particular',
                'Termo de adesão',
                'Acordo de parceria',
                'Distrato contratual',
                'Termo de responsabilidade',
            ]).' — '.fake()->company(),
            'message' => fake()->optional(0.6)->sentence(12),
            'status' => EnvelopeStatus::Draft,
            'signing_order' => SigningOrder::Sequential,
            'current_order' => 1,
            'terms_version' => '2026-09',
            'settings' => ['otp_required' => true, 'expiration_days' => 30],
        ];
    }

    public function forOrganization(Organization $organization, ?User $creator = null): static
    {
        return $this->state(fn () => array_filter([
            'organization_id' => $organization->id,
            'created_by_user_id' => $creator?->id,
        ]));
    }

    public function parallel(): static
    {
        return $this->state(fn () => ['signing_order' => SigningOrder::Parallel]);
    }

    public function sequential(): static
    {
        return $this->state(fn () => ['signing_order' => SigningOrder::Sequential]);
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => EnvelopeStatus::Draft]);
    }

    public function preparing(): static
    {
        return $this->state(fn () => ['status' => EnvelopeStatus::Preparing]);
    }

    public function ready(): static
    {
        return $this->state(fn () => ['status' => EnvelopeStatus::Ready]);
    }

    /**
     * Enviado: sent_at, expires_at e verification_code definidos.
     */
    public function inProgress(): static
    {
        return $this->state(function () {
            $sentAt = fake()->dateTimeBetween('-20 days', '-1 hour');

            return [
                'status' => EnvelopeStatus::InProgress,
                'sent_at' => $sentAt,
                'expires_at' => (clone $sentAt)->modify('+30 days')->setTime(23, 59, 59),
                'verification_code' => Envelope::generateVerificationCode(),
                'finalization_key' => null,
            ];
        });
    }

    public function finalizing(): static
    {
        return $this->inProgress()->state(fn () => [
            'status' => EnvelopeStatus::Finalizing,
            'finalization_key' => (string) Str::ulid(),
        ]);
    }

    public function completed(): static
    {
        return $this->inProgress()->state(fn (array $attributes) => [
            'status' => EnvelopeStatus::Completed,
            'completed_at' => fake()->dateTimeBetween($attributes['sent_at'], 'now'),
            'finalization_key' => (string) Str::ulid(),
        ]);
    }

    public function refused(): static
    {
        return $this->inProgress()->state(fn (array $attributes) => [
            'status' => EnvelopeStatus::Refused,
            'refused_at' => fake()->dateTimeBetween($attributes['sent_at'], 'now'),
        ]);
    }

    public function expired(): static
    {
        return $this->state(function () {
            $sentAt = fake()->dateTimeBetween('-90 days', '-40 days');
            $expiresAt = (clone $sentAt)->modify('+30 days')->setTime(23, 59, 59);

            return [
                'status' => EnvelopeStatus::Expired,
                'sent_at' => $sentAt,
                'expires_at' => $expiresAt,
                'expired_at' => $expiresAt,
                'verification_code' => Envelope::generateVerificationCode(),
            ];
        });
    }

    public function canceled(): static
    {
        return $this->state(fn () => [
            'status' => EnvelopeStatus::Canceled,
            'canceled_at' => fake()->dateTimeBetween('-10 days', 'now'),
        ]);
    }
}
