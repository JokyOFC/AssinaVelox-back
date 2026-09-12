<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes de pagamentos ampliados e NFS-e (Fase 2, onda D — D-PAY)
|--------------------------------------------------------------------------
| Incluído com require_once. Não contém testes. Nenhum helper acessa a rede: o gateway é o
| dublê identificado (FakePaymentGateway) ou o adaptador real sob Http::fake.
*/

use App\Enums\PaymentEnvironment;
use App\Enums\PaymentStatus;
use App\Integrations\Payments\FakePaymentGateway;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../../Billing/Support/BillingHelpers.php';

if (! function_exists('enableExtendedPayments')) {
    function enableExtendedPayments(bool $enabled = true): void
    {
        config()->set('assinavelox.features.extended_payments', $enabled);
    }
}

if (! function_exists('enableFiscalInvoices')) {
    function enableFiscalInvoices(bool $enabled = true): void
    {
        config()->set('assinavelox.features.fiscal_invoices', $enabled);
    }
}

if (! function_exists('activatedPaymentFor')) {
    /**
     * Pagamento aprovado que já pagou o ciclo vigente da assinatura, e o mesmo pagamento
     * programado no dublê (a consulta GET /v1/payments/{id} devolve `approved`).
     */
    function activatedPaymentFor(
        Organization $organization,
        Plan $plan,
        Subscription $subscription,
        ?FakePaymentGateway $gateway,
        string $providerPaymentId = '9000000001',
        int $paidDaysAgo = 1,
        string $provider = 'fake',
        string $method = 'visa',
    ): Payment {
        $payment = Payment::factory()->create([
            'organization_id' => $organization->id,
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'provider' => $provider,
            'status' => PaymentStatus::Approved,
            'status_detail' => 'accredited',
            'provider_payment_id' => $providerPaymentId,
            'payment_method_id' => $method,
            'amount_cents' => $plan->price_cents,
            'currency' => 'BRL',
            'environment' => PaymentEnvironment::Sandbox,
            'paid_at' => now()->subDays($paidDaysAgo),
            'activated_at' => now()->subDays($paidDaysAgo),
        ]);

        $gateway?->pretendPayment(
            $providerPaymentId,
            $payment->external_reference,
            (int) $plan->price_cents,
            'approved',
            'accredited',
            'BRL',
            $method,
            new DateTimeImmutable('-'.$paidDaysAgo.' days'),
        );

        return $payment;
    }
}

if (! function_exists('extendedBillingContext')) {
    /**
     * Flag ligada, dublê amarrado, organização com plano pago ativo e um pagamento que pagou o
     * ciclo vigente.
     *
     * @return array{gateway: FakePaymentGateway, organization: Organization, owner: User, plan: Plan, subscription: Subscription, payment: Payment, secret: string}
     */
    function extendedBillingContext(): array
    {
        enableExtendedPayments();
        $secret = billingSecret();
        $gateway = useFakeGateway();

        ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
        $plan = paidPlan();
        $subscription = subscribeOrganization($organization, $plan, used: 3);
        $payment = activatedPaymentFor($organization, $plan, $subscription, $gateway);

        return compact('gateway', 'organization', 'owner', 'plan', 'subscription', 'payment', 'secret');
    }
}

if (! function_exists('adminBillingPost')) {
    /**
     * POST de uma ação do painel interno, com a senha já confirmada na sessão.
     *
     * @param  array<string, mixed>  $data
     */
    function adminBillingPost(User $admin, string $route, array $parameters = [], array $data = []): TestResponse
    {
        return test()->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route($route, $parameters), $data);
    }
}

if (! function_exists('ensureFreePlan')) {
    function ensureFreePlan(): Plan
    {
        return Plan::query()->where('code', Plan::CODE_FREE)->first() ?? Plan::factory()->free()->create();
    }
}
