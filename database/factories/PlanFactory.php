<?php

namespace Database\Factories;

use App\Enums\PlanBillingPeriod;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'plan_'.fake()->unique()->lexify('??????'),
            'name' => 'Plano '.fake()->unique()->word(),
            'description' => 'Plano de desenvolvimento — preços fictícios.',
            'price_cents' => fake()->numberBetween(1_000, 50_000),
            'currency' => 'BRL',
            'billing_period' => PlanBillingPeriod::Monthly,
            'envelope_quota' => fake()->numberBetween(10, 1_000),
            'user_quota' => fake()->numberBetween(1, 50),
            'features' => [],
            'is_active' => true,
            'is_public' => false,
            'is_sandbox' => true,
            'sort_order' => 0,
        ];
    }

    public function free(): static
    {
        return $this->state(fn () => [
            'code' => Plan::CODE_FREE,
            'name' => 'Grátis',
            'description' => 'Para experimentar: 5 documentos por mês e 1 usuário.',
            'price_cents' => 0,
            'envelope_quota' => 5,
            'user_quota' => 1,
            'features' => ['email_otp' => true, 'evidence_page' => true, 'company_signature' => false],
            'is_public' => true,
            'is_sandbox' => false,
            'sort_order' => 1,
        ]);
    }

    public function professional(): static
    {
        return $this->state(fn () => [
            'code' => Plan::CODE_PROFESSIONAL,
            'name' => 'Profissional',
            'description' => 'Plano de desenvolvimento — preços fictícios.',
            'price_cents' => 4_900,
            'envelope_quota' => 500,
            'user_quota' => 10,
            'features' => ['email_otp' => true, 'evidence_page' => true, 'company_signature' => true, 'folders' => true],
            'is_public' => false,
            'is_sandbox' => true,
            'sort_order' => 2,
        ]);
    }

    public function enterprise(): static
    {
        return $this->state(fn () => [
            'code' => Plan::CODE_ENTERPRISE,
            'name' => 'Empresarial',
            'description' => 'Plano de desenvolvimento — preços fictícios.',
            'price_cents' => 39_900,
            'envelope_quota' => 3_000,
            'user_quota' => 50,
            'features' => ['email_otp' => true, 'evidence_page' => true, 'company_signature' => true, 'folders' => true, 'priority_support' => true],
            'is_public' => false,
            'is_sandbox' => true,
            'sort_order' => 3,
        ]);
    }

    public function yearly(): static
    {
        return $this->state(fn () => ['billing_period' => PlanBillingPeriod::Yearly]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
