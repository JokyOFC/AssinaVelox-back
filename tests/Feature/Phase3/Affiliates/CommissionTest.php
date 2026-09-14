<?php

use App\Enums\PaymentEnvironment;
use App\Enums\PaymentStatus;
use App\Models\Affiliate;
use App\Models\Commission;
use App\Models\Referral;
use App\Models\User;
use App\Services\Affiliates\AffiliateProgram;
use App\Services\Affiliates\CommissionLedger;
use App\Services\Affiliates\PayoutBatches;
use App\Services\Billing\SyncPaymentFromGateway;

require_once __DIR__.'/Support/AffiliateHelpers.php';
require_once __DIR__.'/../../Billing/Support/BillingHelpers.php';

/*
|--------------------------------------------------------------------------
| Comissões: só pagamento aprovado; pendente → aprovada após o prazo de estorno;
| estorno e contestação revertem (antes e depois da aprovação); idempotência.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    enableAffiliates();
    fakeAffiliateRisk();
    $this->affiliate = makeAffiliate();
    ['organization' => $this->organization, 'referral' => $this->referral] = referOrganization($this->affiliate);
    $this->ledger = app(CommissionLedger::class);
});

test('só pagamento aprovado gera comissão, em centavos com moeda e prazo de estorno', function () {
    paymentFor($this->organization, status: PaymentStatus::Pending);
    paymentFor($this->organization, status: PaymentStatus::Rejected);
    paymentFor($this->organization, status: PaymentStatus::Cancelled);
    expect(Commission::query()->count())->toBe(0);

    $payment = paymentFor($this->organization, 9_999, paidAt: now()->subHour());

    $commission = Commission::query()->sole();
    expect($commission->payment_id)->toBe($payment->id)
        ->and($commission->kind)->toBe(Commission::KIND_COMMISSION)
        ->and($commission->status)->toBe(Commission::STATUS_PENDING)
        ->and($commission->base_amount_cents)->toBe(9_999)
        ->and($commission->rate_bp)->toBe(1000)
        ->and($commission->amount_cents)->toBe(999) // ⌊9 999 × 10%⌋
        ->and($commission->currency)->toBe('BRL')
        ->and($commission->affiliate_id)->toBe($this->affiliate->id)
        ->and($commission->referral_id)->toBe($this->referral->id)
        ->and($commission->available_at?->toDateTimeString())->toBe($payment->paid_at->copy()->addDays(30)->toDateTimeString());
});

test('pendente vira aprovada depois do prazo de estorno — e não antes', function () {
    paymentFor($this->organization, paidAt: now());

    $this->travel(29)->days();
    expect($this->ledger->approveDue())->toBe(0)
        ->and(Commission::query()->sole()->status)->toBe(Commission::STATUS_PENDING);

    $this->travel(2)->days();
    $this->artisan('affiliates:settle')->assertSuccessful();

    $commission = Commission::query()->sole();
    expect($commission->status)->toBe(Commission::STATUS_APPROVED)
        ->and($commission->approved_at)->not->toBeNull();
});

test('o prazo de estorno é configurável', function () {
    config()->set('assinavelox.affiliates.approval_hold_days', 7);
    paymentFor($this->organization, paidAt: now());

    $this->travel(8)->days();
    $this->ledger->approveDue();

    expect(Commission::query()->sole()->status)->toBe(Commission::STATUS_APPROVED);
});

test('estorno antes da aprovação reverte a comissão sem lançamento negativo', function () {
    $payment = paymentFor($this->organization);

    movePayment($payment, PaymentStatus::Refunded, ['status_detail' => 'refunded', 'refunded_cents' => 10_000]);

    $commission = Commission::query()->sole();
    expect($commission->status)->toBe(Commission::STATUS_REVERSED)
        ->and($commission->reversal_reason)->toBe(Commission::REASON_REFUND)
        ->and($commission->reversed_at)->not->toBeNull();

    $this->travel(40)->days();
    $this->ledger->approveDue();
    expect(Commission::query()->sole()->status)->toBe(Commission::STATUS_REVERSED);
});

test('contestação antes da aprovação reverte a comissão', function () {
    $payment = paymentFor($this->organization);

    movePayment($payment, PaymentStatus::ChargedBack);

    expect(Commission::query()->sole())
        ->status->toBe(Commission::STATUS_REVERSED)
        ->reversal_reason->toBe(Commission::REASON_CHARGEBACK);
});

test('estorno depois da aprovação gera lançamento negativo para o próximo lote', function () {
    $payment = paymentFor($this->organization, paidAt: now()->subDays(31));
    $this->ledger->approveDue();

    movePayment($payment, PaymentStatus::Refunded, ['refunded_cents' => 10_000]);

    $original = Commission::query()->where('kind', Commission::KIND_COMMISSION)->sole();
    $reversal = Commission::query()->where('kind', Commission::KIND_REVERSAL)->sole();

    expect($original->status)->toBe(Commission::STATUS_APPROVED)
        ->and($reversal->amount_cents)->toBe(-1_000)
        ->and($reversal->status)->toBe(Commission::STATUS_APPROVED)
        ->and($reversal->payout_batch_id)->toBeNull()
        ->and($reversal->reversal_reason)->toBe(Commission::REASON_REFUND)
        ->and((int) Commission::query()->sum('amount_cents'))->toBe(0);
});

test('contestação depois de a comissão ter sido PAGA gera lançamento negativo no próximo lote', function () {
    $payment = paymentFor($this->organization, paidAt: now()->subDays(31));
    $this->ledger->approveDue();

    $admin = User::factory()->platformAdmin()->create();
    $batch = app(PayoutBatches::class)->build('BRL', now(), $admin);
    app(PayoutBatches::class)->markPaid($batch, $admin, now(), 'PIX-E2E-123', null);
    expect(Commission::query()->sole()->status)->toBe(Commission::STATUS_PAID);

    movePayment($payment, PaymentStatus::ChargedBack);

    $reversal = Commission::query()->where('kind', Commission::KIND_REVERSAL)->sole();
    expect($reversal->amount_cents)->toBe(-1_000)
        ->and($reversal->status)->toBe(Commission::STATUS_APPROVED)
        ->and($reversal->payout_batch_id)->toBeNull()
        ->and($reversal->reversal_reason)->toBe(Commission::REASON_CHARGEBACK);
});

test('idempotente por pagamento: gravações e varreduras repetidas não duplicam nada', function () {
    $payment = paymentFor($this->organization, paidAt: now()->subDays(31));

    foreach (range(1, 3) as $ignored) {
        $this->ledger->syncPayment($payment->fresh());
        $payment->fresh()->forceFill(['status_detail' => 'accredited'])->save();
        $this->ledger->sweep();
    }

    expect(Commission::query()->count())->toBe(1);

    $this->ledger->approveDue();
    movePayment($payment, PaymentStatus::Refunded, ['refunded_cents' => 10_000]);

    foreach (range(1, 3) as $ignored) {
        $this->ledger->syncPayment($payment->fresh());
        $this->artisan('affiliates:settle')->assertSuccessful();
    }

    expect(Commission::query()->count())->toBe(2)
        ->and(Commission::query()->where('kind', Commission::KIND_REVERSAL)->count())->toBe(1);
});

test('em disputa (in_mediation) a comissão continua pendente além do prazo', function () {
    $payment = paymentFor($this->organization, paidAt: now());
    movePayment($payment, PaymentStatus::InMediation);

    $this->travel(45)->days();
    $this->ledger->approveDue();

    expect(Commission::query()->sole()->status)->toBe(Commission::STATUS_PENDING);
});

test('estorno parcial: recalcula a pendente; depois da aprovação gera ajuste', function () {
    $pending = paymentFor($this->organization, 10_000);
    movePayment($pending, PaymentStatus::Approved, ['refunded_cents' => 4_000, 'status_detail' => 'partially_refunded']);

    expect(Commission::query()->where('payment_id', $pending->id)->sole())
        ->amount_cents->toBe(600)
        ->base_amount_cents->toBe(6_000);

    $approved = paymentFor($this->organization, 10_000, paidAt: now()->subDays(31));
    $this->ledger->approveDue();
    movePayment($approved, PaymentStatus::Approved, ['refunded_cents' => 2_500, 'status_detail' => 'partially_refunded']);
    $this->ledger->syncPayment($approved->fresh());

    $adjustment = Commission::query()->where('payment_id', $approved->id)->where('kind', Commission::KIND_ADJUSTMENT)->sole();
    expect($adjustment->amount_cents)->toBe(-250)
        ->and($adjustment->status)->toBe(Commission::STATUS_APPROVED);
});

test('a taxa da comissão é a do momento: alteração posterior não mexe no que já foi calculado', function () {
    paymentFor($this->organization);

    $admin = User::factory()->platformAdmin()->create();
    app(AffiliateProgram::class)->changeRate($this->affiliate, $admin, 2000, 'Parceria ampliada.');

    paymentFor($this->organization);

    expect(Commission::query()->orderBy('id')->pluck('amount_cents')->all())->toBe([1_000, 2_000])
        ->and(Commission::query()->orderBy('id')->pluck('rate_bp')->all())->toBe([1000, 2000]);
});

test('fora do período de comissão, sem organização indicada, afiliado suspenso ou sandbox em produção: nada', function () {
    // Período de comissão encerrado.
    $this->referral->forceFill(['expires_at' => now()->subDay()])->save();
    paymentFor($this->organization);

    // Organização não indicada.
    ['organization' => $other] = createOrganizationWithOwner();
    paymentFor($other);

    // Afiliado suspenso.
    $suspended = makeAffiliate(['status' => Affiliate::STATUS_SUSPENDED]);
    ['organization' => $suspendedOrg] = referOrganization($suspended);
    paymentFor($suspendedOrg);

    // Sandbox não conta quando a instalação não inclui sandbox.
    config()->set('assinavelox.affiliates.include_sandbox_payments', false);
    $fresh = makeAffiliate();
    ['organization' => $freshOrg] = referOrganization($fresh);
    paymentFor($freshOrg);
    expect(Commission::query()->count())->toBe(0);

    paymentFor($freshOrg, attributes: ['environment' => PaymentEnvironment::Production]);
    expect(Commission::query()->sole()->environment)->toBe('production');
});

test('pelo caminho real da cobrança: consulta ao provedor aprova, depois estorna', function () {
    $gateway = useFakeGateway();
    $payment = pendingPaymentFor($this->organization, affiliatePlan());

    $gateway->pretendPayment('9100000001', $payment->external_reference, (int) $payment->amount_cents, 'approved', 'accredited', 'BRL', 'pix', new DateTimeImmutable('-1 minute'));
    app(SyncPaymentFromGateway::class)->handle('9100000001');

    $commission = Commission::query()->sole();
    expect($commission->status)->toBe(Commission::STATUS_PENDING)
        ->and($commission->payment_id)->toBe($payment->id);

    $gateway->pretendPayment('9100000001', $payment->external_reference, (int) $payment->amount_cents, 'refunded', 'refunded');
    app(SyncPaymentFromGateway::class)->handle('9100000001');

    expect($commission->fresh()->status)->toBe(Commission::STATUS_REVERSED);
});

test('indicação segurada gera comissão pendente que não aprova até a revisão', function () {
    $this->referral->forceFill(['status' => Referral::STATUS_HELD])->save();
    paymentFor($this->organization, paidAt: now()->subDays(40));

    $this->ledger->approveDue();

    expect(Commission::query()->sole()->status)->toBe(Commission::STATUS_PENDING);
});
