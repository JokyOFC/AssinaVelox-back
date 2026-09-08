<?php

namespace Database\Factories;

use App\Enums\DeliveryChannel;
use App\Models\AuthChallenge;
use App\Models\SigningSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AuthChallenge>
 */
class AuthChallengeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'signing_session_id' => SigningSession::factory(),
            'recipient_id' => fn (array $attributes) => self::session($attributes)->recipient_id,
            'envelope_id' => fn (array $attributes) => self::session($attributes)->envelope_id,
            'organization_id' => fn (array $attributes) => self::session($attributes)->organization_id,
            'channel' => DeliveryChannel::Email,
            'code_hash' => hash('sha256', Str::random(32)),
            'attempts' => 0,
            'max_attempts' => AuthChallenge::DEFAULT_MAX_ATTEMPTS,
            'expires_at' => now()->addMinutes(AuthChallenge::TTL_MINUTES),
            'consumed_at' => null,
            'delivery_attempt_id' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected static function session(array $attributes): SigningSession
    {
        return SigningSession::withoutOrganizationScope()->whereKey($attributes['signing_session_id'])->firstOrFail();
    }

    public function forSession(SigningSession $session): static
    {
        return $this->state(fn () => [
            'signing_session_id' => $session->id,
            'recipient_id' => $session->recipient_id,
            'envelope_id' => $session->envelope_id,
            'organization_id' => $session->organization_id,
        ]);
    }

    public function consumed(): static
    {
        return $this->state(fn () => [
            'attempts' => 1,
            'consumed_at' => now()->subMinutes(3),
        ]);
    }

    public function exhausted(): static
    {
        return $this->state(fn () => ['attempts' => AuthChallenge::DEFAULT_MAX_ATTEMPTS]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }
}
