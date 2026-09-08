<?php

namespace Database\Factories;

use App\Enums\SigningSessionStatus;
use App\Models\RecipientAccessLink;
use App\Models\SigningSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SigningSession>
 */
class SigningSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'access_link_id' => RecipientAccessLink::factory(),
            'recipient_id' => fn (array $attributes) => self::link($attributes)->recipient_id,
            'envelope_id' => fn (array $attributes) => self::link($attributes)->envelope_id,
            'document_version_id' => fn (array $attributes) => self::link($attributes)->document_version_id,
            'organization_id' => fn (array $attributes) => self::link($attributes)->organization_id,
            'token_digest' => hash('sha256', Str::random(43)),
            'status' => SigningSessionStatus::PendingAuth,
            'authorization_token_digest' => null,
            'authorization_expires_at' => null,
            'snapshot_hash' => null,
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'expires_at' => now()->addMinutes(30),
            'authenticated_at' => null,
            'consumed_at' => null,
            'last_seen_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected static function link(array $attributes): RecipientAccessLink
    {
        return RecipientAccessLink::withoutOrganizationScope()->whereKey($attributes['access_link_id'])->firstOrFail();
    }

    public function forAccessLink(RecipientAccessLink $link): static
    {
        return $this->state(fn () => [
            'access_link_id' => $link->id,
            'recipient_id' => $link->recipient_id,
            'envelope_id' => $link->envelope_id,
            'document_version_id' => $link->document_version_id,
            'organization_id' => $link->organization_id,
        ]);
    }

    public function authenticated(): static
    {
        return $this->state(fn () => [
            'status' => SigningSessionStatus::Authenticated,
            'authenticated_at' => now()->subMinutes(2),
            'authorization_token_digest' => hash('sha256', Str::random(43)),
            'authorization_expires_at' => now()->addMinutes(10),
            'snapshot_hash' => hash('sha256', Str::random(32)),
        ]);
    }

    public function consumed(): static
    {
        return $this->authenticated()->state(fn () => [
            'status' => SigningSessionStatus::Consumed,
            'consumed_at' => now()->subMinute(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => SigningSessionStatus::Expired,
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['status' => SigningSessionStatus::Revoked]);
    }
}
