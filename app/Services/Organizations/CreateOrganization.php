<?php

namespace App\Services\Organizations;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\TaxId;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cria Organization + Membership(owner, active) + Subscription(plano free, active) em
 * transação (RECONCILIACAO Q4/Q8). Usado pelo cadastro e por "Criar nova organização".
 */
class CreateOrganization
{
    /**
     * @param  array{name: string, legal_name?: string|null, tax_id?: string|null, timezone?: string|null}  $attributes
     */
    public function handle(User $owner, array $attributes, bool $makeCurrent = true): Organization
    {
        return DB::transaction(function () use ($owner, $attributes, $makeCurrent): Organization {
            $plan = Plan::free() ?? $this->createFreePlanFallback();

            $organization = Organization::query()->create([
                'name' => trim($attributes['name']),
                'legal_name' => filled($attributes['legal_name'] ?? null) ? trim((string) $attributes['legal_name']) : null,
                'tax_id' => filled($attributes['tax_id'] ?? null) ? TaxId::digits($attributes['tax_id']) : null,
                'timezone' => $attributes['timezone'] ?? Organization::DEFAULT_TIMEZONE,
                'locale' => Organization::DEFAULT_LOCALE,
                'settings' => Organization::DEFAULT_SETTINGS + [
                    'default_expiration_days' => (int) config('assinavelox.default_expiration_days', 30),
                    'evidence_show_ip' => (string) config('assinavelox.evidence_show_ip', 'masked'),
                ],
                'created_by_user_id' => $owner->getKey(),
            ]);

            Membership::query()->create([
                'organization_id' => $organization->getKey(),
                'user_id' => $owner->getKey(),
                'role' => MembershipRole::Owner,
                'status' => MembershipStatus::Active,
            ]);

            $now = now();

            Subscription::query()->create([
                'organization_id' => $organization->getKey(),
                'plan_id' => $plan->getKey(),
                'status' => SubscriptionStatus::Active,
                'started_at' => $now,
                'current_period_start' => $now->startOfMonth(),
                'current_period_end' => $now->startOfMonth()->addMonth(),
                'provider' => null,
            ]);

            if ($makeCurrent) {
                $owner->forceFill(['current_organization_id' => $organization->getKey()])->save();
            }

            return $organization;
        });
    }

    /**
     * Ambiente sem PlanSeeder (ex.: banco recém-criado): garante o plano free mínimo.
     * O PlanSeeder (idempotente) ajusta descrição/limites depois.
     */
    protected function createFreePlanFallback(): Plan
    {
        $plan = Plan::query()->firstOrCreate(
            ['code' => Plan::CODE_FREE],
            [
                'name' => 'Grátis',
                'description' => 'Para experimentar: 5 documentos por mês e 1 usuário.',
                'price_cents' => 0,
                'currency' => 'BRL',
                'envelope_quota' => 5,
                'user_quota' => 1,
                'features' => ['email_otp' => true, 'evidence_page' => true],
                'is_active' => true,
                'is_public' => true,
                'is_sandbox' => false,
                'sort_order' => 1,
            ],
        );

        if (! $plan->exists) {
            throw new RuntimeException('Não foi possível criar o plano gratuito.');
        }

        return $plan;
    }
}
