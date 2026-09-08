<?php

namespace Database\Factories;

use App\Enums\MembershipRole;
use App\Models\MembershipInvitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MembershipInvitation>
 */
class MembershipInvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => MembershipRole::Member,
            'token_digest' => hash('sha256', Str::random(43)),
            'invited_by_user_id' => User::factory(),
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
            'revoked_at' => null,
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn () => ['accepted_at' => now()->subHour()]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()->subHour()]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => MembershipRole::Admin]);
    }
}
