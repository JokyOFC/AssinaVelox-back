<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'legal_name' => $name.' Ltda.',
            'tax_id' => fake()->numerify('##.###.###/0001-##'),
            'timezone' => Organization::DEFAULT_TIMEZONE,
            'locale' => Organization::DEFAULT_LOCALE,
            'settings' => Organization::DEFAULT_SETTINGS,
            'created_by_user_id' => User::factory(),
        ];
    }

    public function withoutTaxId(): static
    {
        return $this->state(fn () => ['tax_id' => null]);
    }

    public function createdBy(User $user): static
    {
        return $this->state(fn () => ['created_by_user_id' => $user->id]);
    }
}
