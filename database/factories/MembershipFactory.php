<?php

namespace Database\Factories;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'role' => MembershipRole::Member,
            'status' => MembershipStatus::Active,
        ];
    }

    public function owner(): static
    {
        return $this->state(fn () => ['role' => MembershipRole::Owner]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => MembershipRole::Admin]);
    }

    public function member(): static
    {
        return $this->state(fn () => ['role' => MembershipRole::Member]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => MembershipStatus::Suspended]);
    }
}
