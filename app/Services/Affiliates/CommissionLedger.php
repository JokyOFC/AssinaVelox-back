<?php

namespace App\Services\Affiliates;

use App\Enums\PaymentEnvironment;
use App\Enums\PaymentStatus;
use App\Models\Commission;
use App\Models\Payment;
use App\Models\Referral;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Razão de comissões (Fase 3 §3.10). O sistema CALCULA; o repasse é manual (PayoutBatches).
 *
 * `syncPayment()` é IDEMPOTENTE por pagamento e pode rodar quantas vezes for preciso (gancho
 * de gravação do pagamento + varredura diária `affiliates:settle`):
 *
 * | estado do pagamento   | comissão ausente            | pendente                      | aprovada / paga                                  |
 * |-----------------------|-----------------------------|-------------------------------|--------------------------------------------------|
 * | `approved`            | cria `pending` (se elegível)| recalcula sobre o líquido     | estorno parcial → `adjustment` (diferença)       |
 * | `refunded`            | nada                        | → `reversed`                  | cria `reversal` NEGATIVA (entra no próximo lote) |
 * | `charged_back`        | nada                        | → `reversed`                  | cria `reversal` NEGATIVA (entra no próximo lote) |
 * | demais (`in_mediation`, `pending`…) | nada          | fica pendente (não aprova)    | nada                                             |
 *
 * Só pagamentos APROVADOS geram comissão. Base = valor − estornado; comissão =
 * ⌊base × taxa_bp ÷ 10 000⌋ (arredondamento para baixo, em centavos). A taxa usada é a do
 * afiliado no momento em que a comissão nasce (fica gravada em `rate_bp`).
 */
final class CommissionLedger
{
    public function __construct(private readonly AffiliateSettings $settings) {}

    public function syncPayment(Payment $payment): void
    {
        if (! AffiliatesFeature::enabled()) {
            return;
        }

        $referral = Referral::query()->with('affiliate')->where('organization_id', $payment->organization_id)->first();

        if ($referral === null) {
            return;
        }

        if ($payment->environment !== PaymentEnvironment::Production && ! $this->settings->includeSandboxPayments()) {
            return;
        }

        try {
            DB::transaction(function () use ($payment, $referral): void {
                /** @var Collection<int, Commission> $rows */
                $rows = Commission::query()->where('payment_id', $payment->getKey())->lockForUpdate()->get();

                match ($payment->status) {
                    PaymentStatus::Approved => $this->onApproved($payment, $referral, $rows),
                    PaymentStatus::Refunded => $this->reverse($payment, $rows, Commission::REASON_REFUND),
                    PaymentStatus::ChargedBack => $this->reverse($payment, $rows, Commission::REASON_CHARGEBACK),
                    default => null,
                };
            });
        } catch (UniqueConstraintViolationException) {
            // Outra execução gravou o mesmo lançamento (mesma chave de idempotência): nada a fazer.
            Log::info('affiliates.commission.concurrent_sync', ['payment' => $payment->ulid]);
        }
    }

    /**
     * Aprova as comissões pendentes cujo prazo de estorno passou, reconsultando antes o estado
     * do pagamento (um estorno que chegou sem gancho é aplicado aqui primeiro).
     */
    public function approveDue(?CarbonInterface $now = null): int
    {
        if (! AffiliatesFeature::enabled()) {
            return 0;
        }

        $now ??= Carbon::now();
        $approved = 0;

        Commission::query()
            ->where('kind', Commission::KIND_COMMISSION)
            ->where('status', Commission::STATUS_PENDING)
            ->where('available_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(200, function ($commissions) use ($now, &$approved): void {
                foreach ($commissions as $commission) {
                    /** @var Commission $commission */
                    $payment = Payment::withoutOrganizationScope()->find($commission->payment_id);

                    if ($payment === null) {
                        continue;
                    }

                    $this->syncPayment($payment);

                    $referral = $commission->referral_id !== null ? Referral::query()->find($commission->referral_id) : null;

                    // Pagamento em disputa (`in_mediation`) ou indicação em revisão: continua pendente.
                    if ($payment->status !== PaymentStatus::Approved || $referral === null || $referral->status !== Referral::STATUS_ACTIVE) {
                        continue;
                    }

                    $approved += Commission::query()
                        ->whereKey($commission->getKey())
                        ->where('status', Commission::STATUS_PENDING)
                        ->update(['status' => Commission::STATUS_APPROVED, 'approved_at' => $now, 'updated_at' => $now]);
                }
            });

        return $approved;
    }

    /**
     * Rede de segurança: reaplica `syncPayment` a todos os pagamentos relevantes das
     * organizações indicadas (idempotente). Cobre qualquer transição que não passou pelo gancho.
     */
    public function sweep(): int
    {
        if (! AffiliatesFeature::enabled()) {
            return 0;
        }

        $count = 0;

        Payment::withoutOrganizationScope()
            ->whereIn('organization_id', Referral::query()->whereNotNull('organization_id')->select('organization_id'))
            ->whereIn('status', [PaymentStatus::Approved->value, PaymentStatus::Refunded->value, PaymentStatus::ChargedBack->value])
            ->orderBy('id')
            ->chunkById(200, function ($payments) use (&$count): void {
                foreach ($payments as $payment) {
                    /** @var Payment $payment */
                    $this->syncPayment($payment);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Reaplica `syncPayment` aos pagamentos de uma organização (ex.: indicação liberada pela
     * revisão humana — os pagamentos aprovados passam a gerar comissão).
     */
    public function syncOrganization(int $organizationId): int
    {
        if (! AffiliatesFeature::enabled()) {
            return 0;
        }

        $count = 0;

        Payment::withoutOrganizationScope()
            ->where('organization_id', $organizationId)
            ->whereIn('status', [PaymentStatus::Approved->value, PaymentStatus::Refunded->value, PaymentStatus::ChargedBack->value])
            ->orderBy('id')
            ->each(function (Payment $payment) use (&$count): void {
                $this->syncPayment($payment);
                $count++;
            });

        return $count;
    }

    /**
     * Revisão humana rejeitou a indicação: pendentes → `reversed`; aprovadas/pagas → estorno
     * negativo no próximo lote. Nunca toca pagamento, plano, envelope ou evidência.
     */
    public function reverseForReferral(Referral $referral): void
    {
        $paymentIds = Commission::query()
            ->where('referral_id', $referral->getKey())
            ->where('kind', Commission::KIND_COMMISSION)
            ->whereNotNull('payment_id')
            ->pluck('payment_id');

        foreach ($paymentIds as $paymentId) {
            DB::transaction(function () use ($paymentId): void {
                /** @var Collection<int, Commission> $rows */
                $rows = Commission::query()->where('payment_id', $paymentId)->lockForUpdate()->get();
                $this->reverseRows((int) $paymentId, $rows, Commission::REASON_REFERRAL_REJECTED);
            });
        }
    }

    /**
     * @param  Collection<int, Commission>  $rows
     */
    private function onApproved(Payment $payment, Referral $referral, Collection $rows): void
    {
        $main = $rows->firstWhere('kind', Commission::KIND_COMMISSION);
        $net = max(0, (int) $payment->amount_cents - (int) ($payment->refunded_cents ?? 0));

        if ($main === null) {
            $this->createCommission($payment, $referral, $net);

            return;
        }

        $target = intdiv($net * $main->rate_bp, 10_000);

        if ($main->status === Commission::STATUS_REVERSED) {
            return;
        }

        if ($main->status === Commission::STATUS_PENDING) {
            if ($main->amount_cents !== $target) {
                $main->forceFill(['amount_cents' => $target, 'base_amount_cents' => $net])->save();
            }

            return;
        }

        // Aprovada ou paga: estorno PARCIAL depois da aprovação vira ajuste (lançamento novo).
        $current = $this->liveTotal($rows);

        if ($current === $target || $rows->firstWhere('kind', Commission::KIND_REVERSAL) !== null) {
            return;
        }

        $key = Commission::adjustmentKey((int) $payment->getKey(), $net);

        if ($rows->firstWhere('idempotency_key', $key) !== null) {
            return;
        }

        $now = Carbon::now();

        Commission::query()->create([
            'affiliate_id' => $main->affiliate_id,
            'referral_id' => $main->referral_id,
            'organization_id' => $main->organization_id,
            'payment_id' => $payment->getKey(),
            'kind' => Commission::KIND_ADJUSTMENT,
            'idempotency_key' => $key,
            'base_amount_cents' => $net,
            'rate_bp' => $main->rate_bp,
            'amount_cents' => $target - $current,
            'currency' => $main->currency,
            'environment' => $main->environment,
            'status' => Commission::STATUS_APPROVED,
            'available_at' => $now,
            'approved_at' => $now,
            'reversal_reason' => Commission::REASON_PARTIAL_REFUND,
        ]);
    }

    private function createCommission(Payment $payment, Referral $referral, int $net): void
    {
        $affiliate = $referral->affiliate;

        // Indicação barrada (autoindicação confirmada) ou afiliado fora do programa: sem comissão.
        if ($referral->status === Referral::STATUS_REJECTED || ! $affiliate->isApproved()) {
            return;
        }

        $paidAt = $payment->paid_at ?? Carbon::now();

        // Pagamento anterior à atribuição ou depois do fim do período de comissão: fora.
        if ($paidAt->lt($referral->attributed_at->copy()->subMinute())
            || ($referral->expires_at !== null && $paidAt->gt($referral->expires_at))) {
            return;
        }

        $rate = $affiliate->commission_rate_bp;
        $amount = intdiv($net * $rate, 10_000);

        if ($amount <= 0) {
            return;
        }

        Commission::query()->create([
            'affiliate_id' => $affiliate->getKey(),
            'referral_id' => $referral->getKey(),
            'organization_id' => $payment->organization_id,
            'payment_id' => $payment->getKey(),
            'kind' => Commission::KIND_COMMISSION,
            'idempotency_key' => Commission::commissionKey((int) $payment->getKey()),
            'base_amount_cents' => $net,
            'rate_bp' => $rate,
            'amount_cents' => $amount,
            'currency' => strtoupper($payment->currency),
            'environment' => $payment->environment->value,
            'status' => Commission::STATUS_PENDING,
            'available_at' => $paidAt->copy()->addDays($this->settings->approvalHoldDays()),
        ]);
    }

    /**
     * @param  Collection<int, Commission>  $rows
     */
    private function reverse(Payment $payment, Collection $rows, string $reason): void
    {
        $this->reverseRows((int) $payment->getKey(), $rows, $reason);
    }

    /**
     * @param  Collection<int, Commission>  $rows
     */
    private function reverseRows(int $paymentId, Collection $rows, string $reason): void
    {
        $main = $rows->firstWhere('kind', Commission::KIND_COMMISSION);

        if ($main === null || $main->status === Commission::STATUS_REVERSED) {
            return;
        }

        $now = Carbon::now();

        if ($main->status === Commission::STATUS_PENDING) {
            $main->forceFill([
                'status' => Commission::STATUS_REVERSED,
                'reversed_at' => $now,
                'reversal_reason' => $reason,
            ])->save();

            return;
        }

        if ($rows->firstWhere('kind', Commission::KIND_REVERSAL) !== null) {
            return;
        }

        $current = $this->liveTotal($rows);

        if ($current === 0) {
            return;
        }

        Commission::query()->create([
            'affiliate_id' => $main->affiliate_id,
            'referral_id' => $main->referral_id,
            'organization_id' => $main->organization_id,
            'payment_id' => $paymentId,
            'kind' => Commission::KIND_REVERSAL,
            'idempotency_key' => Commission::reversalKey($paymentId),
            'base_amount_cents' => $main->base_amount_cents,
            'rate_bp' => $main->rate_bp,
            'amount_cents' => -$current,
            'currency' => $main->currency,
            'environment' => $main->environment,
            'status' => Commission::STATUS_APPROVED,
            'available_at' => $now,
            'approved_at' => $now,
            'reversal_reason' => $reason,
        ]);
    }

    /**
     * Soma do que já está valendo para o pagamento (aprovado ou pago; exclui revertidos).
     *
     * @param  Collection<int, Commission>  $rows
     */
    private function liveTotal(Collection $rows): int
    {
        return (int) $rows
            ->filter(fn (Commission $row): bool => in_array($row->status, [Commission::STATUS_APPROVED, Commission::STATUS_PAID], true))
            ->sum('amount_cents');
    }
}
