<?php

namespace App\Services\Billing;

use App\Enums\PaymentEnvironment;
use App\Enums\PaymentStatus;
use App\Integrations\Payments\CheckoutProGateway;
use App\Integrations\Payments\Dto\GatewayPaymentSummary;
use App\Integrations\Payments\Exceptions\PaymentGatewayException;
use App\Models\Payment;
use App\Models\ReconciliationItem;
use App\Models\ReconciliationRun;
use App\Models\User;
use App\Services\Billing\Exceptions\BillingActionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Conciliação diária — Fase 2, onda D (roadmap §2.20; docs/integracoes/mercado-pago-fase-2.md §8).
 *
 * Busca os pagamentos da conta em GET /v1/payments/search, janela de `date_last_updated`
 * D-`window_days`..agora, e compara com a base local por `external_reference` (id do provedor como
 * rede de segurança): **status, valor em centavos e moeda**. Cada diferença vira uma linha em
 * `reconciliation_items` e a execução com divergência alerta a equipe.
 *
 * **Não corrige nada.** O roadmap é explícito: a conciliação só aponta; o webhook + a consulta
 * continuam sendo a fonte da verdade. Uma divergência "aprovado no provedor, pendente aqui"
 * (aviso perdido) é resolvida pelo admin com "Reconsultar pagamento", que roda exatamente o
 * caminho do webhook (GET /v1/payments/{id}).
 *
 * Paginação: `limit`/`offset` com teto NÃO CONFIRMADO — páginas de `page_size` e no máximo
 * `max_pages`; passando disso a execução fica `partial`, sem fingir que viu tudo.
 * Pagamentos de outro ambiente (`live_mode`) são ignorados; pagamentos sem par local só viram
 * divergência quando o `external_reference` tem o formato dos nossos (ULID).
 */
class ReconcilePayments
{
    public function __construct(
        private readonly CheckoutProGateway $gateway,
        private readonly BillingSettings $settings,
        private readonly BillingAlerts $alerts,
    ) {}

    /**
     * @param  'schedule'|'admin'  $trigger
     *
     * @throws BillingActionException
     */
    public function run(string $trigger, ?User $actor = null): ReconciliationRun
    {
        if (! $this->settings->extendedPayments()) {
            throw BillingActionException::featureDisabled();
        }

        $end = Carbon::now();
        $begin = $end->copy()->subDays($this->settings->reconciliationWindowDays());
        $environment = $this->gateway->environment();

        $run = ReconciliationRun::query()->create([
            'provider' => $this->gateway->name(),
            'environment' => $environment->value,
            'window_start' => $begin,
            'window_end' => $end,
            'status' => ReconciliationRun::STATUS_RUNNING,
            'trigger' => $trigger,
            'triggered_by_user_id' => $actor?->getKey(),
            'correlation_id' => (string) Str::ulid(),
            'started_at' => Carbon::now(),
        ]);

        try {
            [$remote, $partial] = $this->fetch($begin, $end);
        } catch (PaymentGatewayException $exception) {
            $run->forceFill([
                'status' => ReconciliationRun::STATUS_FAILED,
                'error' => Str::limit($exception->errorCode.($exception->status !== null ? ':'.$exception->status : ''), 191, ''),
                'finished_at' => Carbon::now(),
            ])->save();

            $this->alerts->raise('billing_reconciliation_failed', 'A conciliação diária com o Mercado Pago não conseguiu consultar os pagamentos.', [
                'run' => $run->ulid,
                'error_code' => $exception->errorCode,
                'inconclusive' => $exception->inconclusive,
            ]);

            return $run;
        }

        $expectedLiveMode = $environment === PaymentEnvironment::Production;
        $matched = 0;
        $divergences = 0;

        foreach ($remote as $summary) {
            if ($summary->liveMode !== $expectedLiveMode) {
                continue;
            }

            $local = $this->locate($summary);

            if ($local === null) {
                if ($this->looksLikeOurs($summary->externalReference)) {
                    $divergences += $this->record($run, null, $summary, ReconciliationItem::MISSING_LOCAL, 'Pagamento com referência no formato do AssinaVelox sem registro correspondente nesta instalação.');
                }

                continue;
            }

            $matched++;

            if ($local->status->value !== $summary->status) {
                $note = $summary->status === PaymentStatus::Approved->value && $local->status !== PaymentStatus::Approved
                    ? 'Aprovado no provedor sem confirmação local (aviso não recebido ou não processado).'
                    : null;

                $divergences += $this->record($run, $local, $summary, ReconciliationItem::STATUS_MISMATCH, $note);
            }

            if ((int) $local->amount_cents !== $summary->amountCents) {
                $divergences += $this->record($run, $local, $summary, ReconciliationItem::AMOUNT_MISMATCH);
            }

            if ($local->currency !== $summary->currency) {
                $divergences += $this->record($run, $local, $summary, ReconciliationItem::CURRENCY_MISMATCH);
            }
        }

        $run->forceFill([
            'status' => $partial ? ReconciliationRun::STATUS_PARTIAL : ReconciliationRun::STATUS_COMPLETED,
            'remote_count' => count($remote),
            'matched_count' => $matched,
            'divergence_count' => $divergences,
            'finished_at' => Carbon::now(),
        ])->save();

        Log::info('billing.reconciliation.finished', [
            'run' => $run->ulid,
            'status' => $run->status,
            'remote' => count($remote),
            'matched' => $matched,
            'divergences' => $divergences,
        ]);

        if ($divergences > 0) {
            $this->alerts->raise('billing_reconciliation_divergence', 'A conciliação diária encontrou divergências entre o Mercado Pago e a base local.', [
                'run' => $run->ulid,
                'divergences' => $divergences,
                'window_start' => $begin->toIso8601String(),
                'window_end' => $end->toIso8601String(),
            ]);
        }

        return $run;
    }

    /**
     * @return array{0: list<GatewayPaymentSummary>, 1: bool}
     */
    private function fetch(Carbon $begin, Carbon $end): array
    {
        $limit = $this->settings->reconciliationPageSize();
        $maxPages = $this->settings->reconciliationMaxPages();
        $offset = 0;
        $all = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $result = $this->gateway->searchPayments($begin, $end, $offset, $limit);
            array_push($all, ...$result->results);

            if (! $result->hasMore()) {
                return [$all, false];
            }

            $offset += count($result->results);
        }

        return [$all, true];
    }

    private function locate(GatewayPaymentSummary $summary): ?Payment
    {
        if ($summary->externalReference !== null && $summary->externalReference !== '') {
            $payment = Payment::withoutOrganizationScope()->where('external_reference', $summary->externalReference)->first();

            if ($payment !== null) {
                return $payment;
            }
        }

        return Payment::withoutOrganizationScope()->where('provider_payment_id', $summary->providerPaymentId)->first();
    }

    private function looksLikeOurs(?string $externalReference): bool
    {
        return $externalReference !== null && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $externalReference) === 1;
    }

    /**
     * Grava a divergência — uma só vez enquanto a mesma (pagamento, tipo, status remoto) seguir
     * aberta, para a lista não inflar a cada execução diária.
     *
     * @return int 1 (divergência contada)
     */
    private function record(ReconciliationRun $run, ?Payment $local, GatewayPaymentSummary $remote, string $divergence, ?string $note = null): int
    {
        $alreadyOpen = ReconciliationItem::query()
            ->whereNull('resolved_at')
            ->where('provider_payment_id', $remote->providerPaymentId)
            ->where('divergence', $divergence)
            ->where('provider_status', $remote->status)
            ->where('provider_amount_cents', $remote->amountCents)
            ->exists();

        if (! $alreadyOpen) {
            ReconciliationItem::query()->create([
                'reconciliation_run_id' => $run->getKey(),
                'payment_id' => $local?->getKey(),
                'organization_id' => $local?->organization_id,
                'provider_payment_id' => $remote->providerPaymentId,
                'external_reference' => $remote->externalReference !== null ? Str::limit($remote->externalReference, 64, '') : null,
                'divergence' => $divergence,
                'local_status' => $local?->status->value,
                'provider_status' => $remote->status,
                'local_amount_cents' => $local !== null ? (int) $local->amount_cents : null,
                'provider_amount_cents' => $remote->amountCents,
                'local_currency' => $local?->currency,
                'provider_currency' => $remote->currency,
                'note' => $note,
            ]);
        }

        return 1;
    }
}
