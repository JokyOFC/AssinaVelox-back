<?php

namespace App\Services\Billing;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\PaymentChargeback;
use App\Models\PaymentRefund;
use App\Models\ReconciliationItem;
use App\Models\Subscription;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Consultas do painel interno › Planos e faturamento (Fase 2, onda D). SOMENTE LEITURA e
 * **sem acesso a documentos**: nada aqui toca envelopes, arquivos, evidências ou signatários —
 * só pagamentos, estornos, contestações, assinaturas e divergências, sempre fora do escopo de
 * organização (o painel não tem organização corrente).
 *
 * Dinheiro sempre em centavos inteiros, agrupado por moeda — nunca somando moedas diferentes.
 */
final class AdminBillingReport
{
    /** Pagamentos que representam dinheiro recebido (mesmo que depois estornado ou contestado). */
    private const RECEIVED = [
        PaymentStatus::Approved,
        PaymentStatus::Refunded,
        PaymentStatus::ChargedBack,
        PaymentStatus::InMediation,
    ];

    /**
     * Receita dos pagamentos RECEBIDOS no período (`paid_at`), por moeda:
     *
     *  - `gross_cents`: soma do valor cobrado;
     *  - `refunded_cents`: quanto desses pagamentos voltou por estorno — por pagamento, o MAIOR
     *    entre `payments.refunded_cents` (espelho de `transaction_amount_refunded`, que inclui o
     *    estorno feito direto no painel do Mercado Pago) e a soma dos estornos aprovados aqui,
     *    limitado ao valor pago;
     *  - `charged_back_cents`: o que ainda restava de pagamentos `charged_back` sem cobertura a favor
     *    da operadora (`payment_chargebacks.coverage_applied = true` mantém o valor);
     *  - `net_cents` = bruto − estornado − contestado. Nunca negativo por pagamento.
     *
     * @return list<array{currency: string, approved_count: int, gross_cents: int, refunded_cents: int, charged_back_cents: int, net_cents: int}>
     */
    public function revenue(CarbonInterface $from, CarbonInterface $to, ?string $environment): array
    {
        $payments = $this->payments($environment)
            ->whereIn('status', array_map(static fn (PaymentStatus $status): string => $status->value, self::RECEIVED))
            ->whereBetween('paid_at', [$from, $to])
            ->get(['id', 'currency', 'amount_cents', 'refunded_cents', 'status']);

        $approvedRefunds = [];
        $covered = [];

        foreach ($payments->pluck('id')->chunk(1000) as $ids) {
            $approvedRefunds += PaymentRefund::withoutOrganizationScope()
                ->whereIn('payment_id', $ids->all())
                ->where('status', PaymentRefund::STATUS_APPROVED)
                ->selectRaw('payment_id, SUM(amount_cents) as total_cents')
                ->groupBy('payment_id')
                ->pluck('total_cents', 'payment_id')
                ->all();

            foreach (PaymentChargeback::withoutOrganizationScope()
                ->whereIn('payment_id', $ids->all())
                ->where('coverage_applied', true)
                ->pluck('payment_id') as $paymentId) {
                $covered[(int) $paymentId] = true;
            }
        }

        $totals = [];

        foreach ($payments as $payment) {
            $currency = (string) $payment->currency;
            $amount = (int) $payment->amount_cents;
            $refunded = min($amount, max((int) $payment->refunded_cents, (int) ($approvedRefunds[$payment->getKey()] ?? 0)));
            $chargedBack = $payment->status === PaymentStatus::ChargedBack && ! isset($covered[(int) $payment->getKey()])
                ? $amount - $refunded
                : 0;

            $totals[$currency] ??= ['approved_count' => 0, 'gross_cents' => 0, 'refunded_cents' => 0, 'charged_back_cents' => 0];
            $totals[$currency]['approved_count']++;
            $totals[$currency]['gross_cents'] += $amount;
            $totals[$currency]['refunded_cents'] += $refunded;
            $totals[$currency]['charged_back_cents'] += $chargedBack;
        }

        ksort($totals);

        $rows = [];

        foreach ($totals as $currency => $line) {
            $rows[] = [
                'currency' => (string) $currency,
                'approved_count' => $line['approved_count'],
                'gross_cents' => $line['gross_cents'],
                'refunded_cents' => $line['refunded_cents'],
                'charged_back_cents' => $line['charged_back_cents'],
                'net_cents' => $line['gross_cents'] - $line['refunded_cents'] - $line['charged_back_cents'],
            ];
        }

        return $rows;
    }

    /**
     * @return array{pending_payments: int, open_chargebacks: int, past_due: int, open_divergences: int, open_refunds: int}
     */
    public function counters(?string $environment): array
    {
        return [
            'pending_payments' => $this->payments($environment)
                ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::InProcess->value])
                ->count(),
            'open_chargebacks' => PaymentChargeback::withoutOrganizationScope()
                ->where(fn (Builder $query) => $query->whereNull('coverage_applied')->orWhere('coverage_applied', false))
                ->count(),
            'past_due' => Subscription::withoutOrganizationScope()->where('status', SubscriptionStatus::PastDue->value)->count(),
            'open_divergences' => ReconciliationItem::query()->whereNull('resolved_at')->count(),
            'open_refunds' => PaymentRefund::withoutOrganizationScope()->whereIn('status', PaymentRefund::OPEN_STATUSES)->count(),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, Payment>
     */
    public function paymentList(CarbonInterface $from, CarbonInterface $to, ?string $environment, ?string $status, string $q): LengthAwarePaginator
    {
        return $this->payments($environment)
            ->with(['organization:id,ulid,name', 'plan:id,name,code'])
            ->whereBetween('created_at', [$from, $to])
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($q !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('provider_payment_id', $q)
                ->orWhere('ulid', $q)
                ->orWhereHas('organization', fn (Builder $organization) => $organization->where('name', 'like', '%'.$q.'%'))))
            ->orderByRaw('COALESCE(paid_at, created_at) DESC')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * @return LengthAwarePaginator<int, PaymentRefund>
     */
    public function refundList(CarbonInterface $from, CarbonInterface $to): LengthAwarePaginator
    {
        return PaymentRefund::withoutOrganizationScope()
            ->with(['organization:id,ulid,name', 'requestedBy:id,name', 'payment' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'ulid', 'provider_payment_id'])])
            ->whereBetween('requested_at', [$from, $to])
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * @return LengthAwarePaginator<int, PaymentChargeback>
     */
    public function chargebackList(): LengthAwarePaginator
    {
        return PaymentChargeback::withoutOrganizationScope()
            ->with(['organization:id,ulid,name', 'payment' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'ulid', 'provider_payment_id', 'status', 'status_detail'])])
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * @return LengthAwarePaginator<int, Subscription>
     */
    public function pastDueList(): LengthAwarePaginator
    {
        return Subscription::withoutOrganizationScope()
            ->with(['organization:id,ulid,name', 'plan:id,name,code'])
            ->where('status', SubscriptionStatus::PastDue->value)
            ->orderBy('current_period_end')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * @return LengthAwarePaginator<int, ReconciliationItem>
     */
    public function divergenceList(bool $openOnly): LengthAwarePaginator
    {
        return ReconciliationItem::query()
            ->with(['organization:id,ulid,name', 'run:id,ulid,started_at', 'payment' => fn ($query) => $query->select(['id', 'ulid', 'provider_payment_id'])])
            ->when($openOnly, fn (Builder $query) => $query->whereNull('resolved_at'))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * @return Builder<Payment>
     */
    private function payments(?string $environment): Builder
    {
        return Payment::withoutOrganizationScope()
            ->when($environment !== null, fn (Builder $query) => $query->where('environment', $environment));
    }
}
