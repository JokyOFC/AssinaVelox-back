<?php

namespace Database\Seeders;

use App\Enums\PlanBillingPeriod;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Planos base. Os planos pagos são placeholders de desenvolvimento (is_sandbox=true,
 * is_public=false): preços e limites reais são configuração comercial, não oferta.
 * Idempotente (updateOrCreate por code).
 */
class PlanSeeder extends Seeder
{
    public const SANDBOX_DESCRIPTION = 'Plano de desenvolvimento — preços fictícios.';

    public function run(): void
    {
        $plans = [
            [
                'code' => Plan::CODE_FREE,
                'name' => 'Grátis',
                'description' => 'Para experimentar: 5 documentos por mês e 1 usuário.',
                'price_cents' => 0,
                'currency' => 'BRL',
                'billing_period' => PlanBillingPeriod::Monthly,
                'envelope_quota' => 5,
                'user_quota' => 1,
                'features' => [
                    'email_otp' => true,
                    'evidence_page' => true,
                    'company_signature' => false,
                    'folders' => false,
                ],
                'is_active' => true,
                'is_public' => true,
                'is_sandbox' => false,
                'sort_order' => 1,
            ],
            [
                'code' => Plan::CODE_PROFESSIONAL,
                'name' => 'Profissional',
                'description' => self::SANDBOX_DESCRIPTION,
                'price_cents' => 4_900,
                'currency' => 'BRL',
                'billing_period' => PlanBillingPeriod::Monthly,
                'envelope_quota' => 500,
                'user_quota' => 10,
                'features' => [
                    'email_otp' => true,
                    'evidence_page' => true,
                    'company_signature' => true,
                    'folders' => true,
                ],
                'is_active' => true,
                'is_public' => false,
                'is_sandbox' => true,
                'sort_order' => 2,
            ],
            [
                'code' => Plan::CODE_ENTERPRISE,
                'name' => 'Empresarial',
                'description' => self::SANDBOX_DESCRIPTION,
                'price_cents' => 39_900,
                'currency' => 'BRL',
                'billing_period' => PlanBillingPeriod::Monthly,
                'envelope_quota' => 3_000,
                'user_quota' => 50,
                'features' => [
                    'email_otp' => true,
                    'evidence_page' => true,
                    'company_signature' => true,
                    'folders' => true,
                    'priority_support' => true,
                ],
                'is_active' => true,
                'is_public' => false,
                'is_sandbox' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $attributes) {
            Plan::query()->updateOrCreate(['code' => $attributes['code']], $attributes);
        }
    }
}
