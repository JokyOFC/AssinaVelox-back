<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes do programa de afiliados (Fase 3 §3.10, P3-AFF)
|--------------------------------------------------------------------------
| Incluído com require_once. Não contém testes. Nenhum helper acessa a rede.
*/

use App\Enums\PaymentStatus;
use App\Models\Affiliate;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Referral;
use App\Models\User;
use App\Services\Affiliates\AffiliateProgram;
use App\Services\Affiliates\AffiliateRiskSignals;
use App\Services\Affiliates\AffiliatesServiceProvider;
use Carbon\CarbonInterface;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../../Support/OrganizationHelpers.php';

if (! function_exists('registerAffiliatesProvider')) {
    /**
     * O provider do programa registra o gancho de Payment e o comando. Enquanto ele não estiver
     * em bootstrap/providers.php (docs/fase-3/afiliados.md §9), os testes o registram aqui.
     */
    function registerAffiliatesProvider(): void
    {
        if (app()->getProvider(AffiliatesServiceProvider::class) === null) {
            app()->register(AffiliatesServiceProvider::class);
        }
    }
}

if (! function_exists('enableAffiliates')) {
    function enableAffiliates(bool $enabled = true, bool $includeSandbox = true): void
    {
        config()->set('assinavelox.features.affiliates', $enabled);
        config()->set('assinavelox.affiliates.include_sandbox_payments', $includeSandbox);
        config()->set('assinavelox.affiliates.min_payout_cents', 1);
        registerAffiliatesProvider();
    }
}

if (! function_exists('affiliatePayoutInput')) {
    /**
     * @return array{pix_key_type: string, pix_key: string, holder_name: string, holder_tax_id: string}
     */
    function affiliatePayoutInput(): array
    {
        return [
            'pix_key_type' => 'cpf',
            'pix_key' => '529.982.247-25',
            'holder_name' => 'Paula Parceira Souza',
            'holder_tax_id' => '529.982.247-25',
        ];
    }
}

if (! function_exists('makeAffiliate')) {
    /**
     * Afiliado APROVADO, com código, taxa de 10% e dados de repasse. O e-mail fica num domínio
     * corporativo próprio para não disparar a regra de domínio por acaso.
     *
     * @param  array<string, mixed>  $attributes
     */
    function makeAffiliate(array $attributes = [], ?User $user = null): Affiliate
    {
        static $sequence = 0;
        $sequence++;

        $user ??= User::factory()->create(['email' => "parceiro{$sequence}@parceira-{$sequence}.com.br", 'name' => "Parceira {$sequence}"]);

        return Affiliate::query()->create(array_merge([
            'user_id' => $user->id,
            'code' => app(AffiliateProgram::class)->uniqueCode(),
            'commission_rate_bp' => 1000,
            'status' => Affiliate::STATUS_APPROVED,
            'payout_details' => [
                'method' => 'pix',
                'pix_key_type' => 'cpf',
                'pix_key' => '52998224725',
                'holder_name' => 'Paula Parceira Souza',
                'holder_tax_id' => '52998224725',
            ],
            'terms_version' => 'afiliados-teste',
            'terms_accepted_at' => now()->subDays(120),
            'approved_at' => now()->subDays(119),
        ], $attributes));
    }
}

if (! function_exists('fakeAffiliateRisk')) {
    /**
     * Dublê do contrato do antifraude (RiskSignals::record via AffiliateRiskSignals).
     */
    function fakeAffiliateRisk(): AffiliateRiskSignals
    {
        $spy = new class extends AffiliateRiskSignals
        {
            /** @var list<array{organization: Organization, affiliate: Affiliate, referral: Referral, reasons: list<string>}> */
            public array $calls = [];

            public function report(Organization $organization, Affiliate $affiliate, Referral $referral, array $reasons): void
            {
                $this->calls[] = compact('organization', 'affiliate', 'referral', 'reasons');
            }
        };

        app()->instance(AffiliateRiskSignals::class, $spy);

        return $spy;
    }
}

if (! function_exists('referralCookieValue')) {
    function referralCookieValue(string $code, ?CarbonInterface $clickedAt = null): string
    {
        return json_encode(['c' => $code, 't' => ($clickedAt ?? now())->getTimestamp()], JSON_THROW_ON_ERROR);
    }
}

if (! function_exists('affiliateCookieName')) {
    function affiliateCookieName(): string
    {
        return (string) config('assinavelox.affiliates.cookie_name');
    }
}

if (! function_exists('signupWithReferral')) {
    /**
     * Cadastro real pelo Fortify (POST register.store), com ou sem o cookie de atribuição.
     *
     * @param  array<string, mixed>  $overrides
     */
    function signupWithReferral(string $email, ?string $cookie = null, string $ip = '198.51.100.20', array $overrides = []): TestResponse
    {
        $test = test();

        // Um segundo cadastro no mesmo teste precisa sair da conta criada pelo primeiro.
        if (auth()->check()) {
            auth()->logout();
        }

        if ($cookie !== null) {
            $test->withCookie(affiliateCookieName(), $cookie);
        }

        return $test->withServerVariables(['REMOTE_ADDR' => $ip])->post(route('register.store'), array_merge([
            'name' => 'Cliente Indicado',
            'email' => $email,
            'organization_name' => 'Empresa Indicada '.substr(md5($email), 0, 6),
            'organization_tax_id' => '',
            'password' => 'Senha@Forte123',
            'password_confirmation' => 'Senha@Forte123',
            'terms' => true,
        ], $overrides));
    }
}

if (! function_exists('referOrganization')) {
    /**
     * Organização já indicada (sem passar pelo cadastro).
     *
     * @return array{organization: Organization, owner: User, referral: Referral}
     */
    function referOrganization(Affiliate $affiliate, string $status = Referral::STATUS_ACTIVE, ?CarbonInterface $attributedAt = null, ?CarbonInterface $expiresAt = null): array
    {
        static $sequence = 0;
        $sequence++;

        $owner = User::factory()->create(['email' => "dono{$sequence}@cliente-{$sequence}.com.br"]);
        ['organization' => $organization] = createOrganizationWithOwner(['name' => "Cliente Indicado {$sequence}"], $owner);

        $attributedAt ??= now()->subDays(90);

        $referral = Referral::query()->create([
            'affiliate_id' => $affiliate->id,
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'source' => Referral::SOURCE_LINK,
            'status' => $status,
            'clicked_at' => $attributedAt->copy()->subHour(),
            'attributed_at' => $attributedAt,
            'expires_at' => $expiresAt ?? $attributedAt->copy()->addMonths(12),
        ]);

        return ['organization' => $organization, 'owner' => $owner, 'referral' => $referral];
    }
}

if (! function_exists('affiliatePlan')) {
    function affiliatePlan(int $priceCents = 10_000): Plan
    {
        return Plan::query()->where('code', Plan::CODE_PROFESSIONAL)->first()
            ?? Plan::factory()->professional()->create(['price_cents' => $priceCents]);
    }
}

if (! function_exists('paymentFor')) {
    /**
     * Pagamento da organização. `approved` grava `paid_at` (padrão: agora).
     *
     * @param  array<string, mixed>  $attributes
     */
    function paymentFor(Organization $organization, int $cents = 10_000, PaymentStatus $status = PaymentStatus::Approved, ?CarbonInterface $paidAt = null, array $attributes = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'organization_id' => $organization->id,
            'plan_id' => affiliatePlan()->id,
            'amount_cents' => $cents,
            'currency' => 'BRL',
            'status' => $status,
            'status_detail' => $status === PaymentStatus::Approved ? 'accredited' : null,
            'provider_payment_id' => (string) fake()->unique()->numberBetween(1_000_000_000, 9_999_999_999),
            'paid_at' => $status === PaymentStatus::Approved ? ($paidAt ?? now()) : null,
        ], $attributes));
    }
}

if (! function_exists('movePayment')) {
    /**
     * Transição do pagamento como a consulta ao provedor faz (forceFill + save).
     *
     * @param  array<string, mixed>  $extra
     */
    function movePayment(Payment $payment, PaymentStatus $status, array $extra = []): Payment
    {
        $payment->forceFill(['status' => $status, ...$extra])->save();

        return $payment;
    }
}
