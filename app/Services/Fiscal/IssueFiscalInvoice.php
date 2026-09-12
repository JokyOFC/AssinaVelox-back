<?php

namespace App\Services\Fiscal;

use App\Integrations\Contracts\FiscalInvoiceProvider;
use App\Integrations\Fiscal\FiscalInvoiceProviderFactory;
use App\Integrations\Fiscal\FiscalProviderUnavailable;
use App\Models\FiscalInvoice;
use App\Models\Payment;
use App\Services\Billing\BillingProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Emissão de NFS-e por pagamento aprovado (roadmap §2.21, classe B) — Fase 2, onda D.
 *
 * Idempotente por pagamento: `fiscal_invoices.payment_id` é UNIQUE e a linha nasce `pending`
 * com a `idempotency_key` ANTES de falar com o provedor. Uma linha já `issued`, `simulated` ou
 * `canceled` nunca gera outra chamada.
 *
 * Sem provedor (`none`) ou com provedor desabilitado (Sefin Nacional sem os dados da operadora),
 * nada é criado: o pagamento mostra "não emitida — integração fiscal pendente". Com o simulador,
 * a linha fica `simulated` (sem número, código, PDF ou XML) e ao simulador só vai a referência do
 * pagamento — nenhum dado do tomador é espalhado por um provedor que não emite nota.
 *
 * Uma exceção do provedor que não seja indisponibilidade é tratada como INCONCLUSIVA (T5): a linha
 * fica `pending` com `error = inconclusive` e **não** é reemitida automaticamente — o contrato atual
 * não tem consulta por identificador da DPS, então repetir poderia duplicar a nota.
 */
class IssueFiscalInvoice
{
    public function __construct(private readonly FiscalInvoiceProviderFactory $providers) {}

    public function handle(Payment $payment): ?FiscalInvoice
    {
        if (! FiscalFeature::enabled() || ! $payment->isPaid()) {
            return null;
        }

        $provider = $this->providers->make();

        if ($provider === null || ! $provider->isConfigured()) {
            Log::info('fiscal.invoice.provider_unavailable', ['payment' => $payment->ulid, 'mode' => $this->providers->mode()]);

            return null;
        }

        $invoice = DB::transaction(function () use ($payment, $provider): FiscalInvoice {
            $existing = FiscalInvoice::withoutOrganizationScope()->where('payment_id', $payment->getKey())->lockForUpdate()->first();

            if ($existing !== null) {
                return $existing;
            }

            $invoice = new FiscalInvoice;
            $invoice->forceFill([
                'organization_id' => $payment->organization_id,
                'payment_id' => $payment->getKey(),
                'provider' => $provider->name(),
                'status' => FiscalInvoice::STATUS_PENDING,
                'idempotency_key' => 'nfse-'.$payment->ulid,
                'correlation_id' => (string) Str::ulid(),
            ]);
            $invoice->save();

            return $invoice;
        });

        if ($invoice->status !== FiscalInvoice::STATUS_PENDING || $invoice->error === 'inconclusive') {
            return $invoice;
        }

        return $this->issue($invoice, $payment, $provider);
    }

    private function issue(FiscalInvoice $invoice, Payment $payment, FiscalInvoiceProvider $provider): FiscalInvoice
    {
        try {
            $result = $provider->issue($this->payload($payment, $provider), $invoice->correlation_id);
        } catch (Throwable $exception) {
            $unavailable = $exception instanceof FiscalProviderUnavailable;

            $invoice->forceFill([
                'status' => $unavailable ? FiscalInvoice::STATUS_FAILED : FiscalInvoice::STATUS_PENDING,
                'error' => $unavailable ? 'provider_unavailable' : 'inconclusive',
            ])->save();

            Log::warning('fiscal.invoice.issue_failed', [
                'payment' => $payment->ulid,
                'invoice' => $invoice->ulid,
                'error' => $invoice->error,
                // Inconclusiva não é reemitida nem consultada: a equipe precisa olhar (rótulo do cliente).
                ...($unavailable ? [] : ['alert' => 'fiscal_invoice_inconclusive']),
            ]);

            return $invoice;
        }

        $status = match ($result['status']) {
            'issued' => FiscalInvoice::STATUS_ISSUED,
            'rejected' => FiscalInvoice::STATUS_FAILED,
            'processing' => FiscalInvoice::STATUS_PENDING,
            default => FiscalInvoice::STATUS_SIMULATED,
        };

        $invoice->forceFill([
            'status' => $status,
            'external_id' => Str::limit($result['invoice_id'], 191, ''),
            // Só uma nota REAL tem número, código e arquivos. O simulador nunca devolve nenhum.
            'number' => $status === FiscalInvoice::STATUS_ISSUED ? ($result['number'] ?? null) : null,
            'verification_code' => $status === FiscalInvoice::STATUS_ISSUED ? ($result['verification_code'] ?? null) : null,
            'issued_at' => $status === FiscalInvoice::STATUS_ISSUED ? Carbon::now() : null,
            'error' => $status === FiscalInvoice::STATUS_FAILED ? 'rejected' : null,
        ])->save();

        return $invoice;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Payment $payment, FiscalInvoiceProvider $provider): array
    {
        $payload = [
            'payment_reference' => $payment->ulid,
            'amount_cents' => (int) $payment->amount_cents,
            'currency' => $payment->currency,
        ];

        if (! $provider->isSimulated()) {
            $organization = $payment->organization()->first();
            $payload['customer'] = $organization !== null ? BillingProfile::for($organization) : null;
        }

        return $payload;
    }
}
