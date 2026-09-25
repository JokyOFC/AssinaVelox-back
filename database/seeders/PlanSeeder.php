<?php

namespace Database\Seeders;

use App\Enums\PlanBillingPeriod;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Catálogo inicial de planos (docs/cobranca.md §16 e §18). Idempotente (updateOrCreate por
 * `code`): rodar de novo atualiza os atributos e não duplica nem apaga.
 *
 * A oferta comercial: Básico R$ 149, Profissional R$ 249 e Empresarial R$ 349 por mês. O
 * plano Grátis continua existindo porque é o plano inicial de toda organização nova
 * (`CreateOrganization`), mas fica privado (`is_public = false`): não é anunciado.
 *
 * Depois do primeiro seed, a fonte de verdade é a tela Painel interno › Planos — este
 * arquivo só descreve como o catálogo nasce.
 */
class PlanSeeder extends Seeder
{
    /** Mantida para os testes e para marcar um plano de desenvolvimento pela tela. */
    public const SANDBOX_DESCRIPTION = 'Plano de desenvolvimento — preços fictícios.';

    public function run(): void
    {
        $plans = [
            [
                'code' => Plan::CODE_FREE,
                'name' => 'Grátis',
                'description' => 'Plano inicial de toda conta nova.',
                'price_cents' => 0,
                'currency' => 'BRL',
                'billing_period' => PlanBillingPeriod::Monthly,
                'envelope_quota' => 5,
                'user_quota' => 1,
                'features' => [
                    'email_otp' => true,
                    'evidence_page' => true,
                    'company_signature' => false,
                ],
                'is_active' => true,
                'is_public' => false,
                'is_sandbox' => false,
                'sort_order' => 0,
            ],
            [
                'code' => Plan::CODE_BASIC,
                'name' => 'Básico',
                'description' => 'Para quem está começando',
                'price_cents' => 14_900,
                'currency' => 'BRL',
                'billing_period' => PlanBillingPeriod::Monthly,
                'envelope_quota' => 5,
                'user_quota' => null,
                'features' => [
                    'email_otp' => true,
                    'evidence_page' => true,
                    'company_signature' => false,
                ],
                'is_active' => true,
                'is_public' => true,
                'is_sandbox' => false,
                'sort_order' => 1,
            ],
            [
                'code' => Plan::CODE_PROFESSIONAL,
                'name' => 'Profissional',
                'description' => 'Para profissionais e pequenas equipes',
                'price_cents' => 24_900,
                'currency' => 'BRL',
                'billing_period' => PlanBillingPeriod::Monthly,
                'envelope_quota' => 50,
                'user_quota' => null,
                'features' => [
                    'email_otp' => true,
                    'evidence_page' => true,
                    'company_signature' => false,
                ],
                'is_active' => true,
                'is_public' => true,
                'is_sandbox' => false,
                'sort_order' => 2,
            ],
            [
                'code' => Plan::CODE_ENTERPRISE,
                'name' => 'Empresarial',
                'description' => 'Para operações com alto volume',
                'price_cents' => 34_900,
                'currency' => 'BRL',
                'billing_period' => PlanBillingPeriod::Monthly,
                'envelope_quota' => 100,
                'user_quota' => null,
                'features' => [
                    'email_otp' => true,
                    'evidence_page' => true,
                    'sms_whatsapp' => true,
                    'company_signature' => false,
                ],
                'is_active' => true,
                'is_public' => true,
                'is_sandbox' => false,
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $attributes) {
            Plan::query()->updateOrCreate(['code' => $attributes['code']], $attributes);
        }
    }
}
