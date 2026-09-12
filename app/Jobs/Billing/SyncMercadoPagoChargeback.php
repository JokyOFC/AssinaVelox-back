<?php

namespace App\Jobs\Billing;

use App\Integrations\Payments\Exceptions\PaymentGatewayException;
use App\Models\PaymentWebhookReceipt;
use App\Services\Billing\BillingSettings;
use App\Services\Billing\SyncChargebackFromGateway;
use App\Services\Billing\WebhookReceipts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Consulta a contestação depois de um webhook `chargebacks` validado (Fase 2, onda D). Mesmo
 * desenho de `SyncMercadoPagoPayment`: não é `ShouldBeUnique` (nunca descarta trabalho), serializa
 * por lock dentro do `handle()`, inconclusivo volta para a fila e o payload do job leva só ids.
 */
class SyncMercadoPagoChargeback implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(
        public readonly string $chargebackId,
        public readonly ?int $receiptId = null,
    ) {
        $this->onQueue(app(BillingSettings::class)->queue());
    }

    public function handle(SyncChargebackFromGateway $sync, WebhookReceipts $receipts): void
    {
        $lock = Cache::lock('mercadopago-chargeback:'.$this->chargebackId, 120);

        if (! $lock->get()) {
            $this->release(30);

            return;
        }

        try {
            $receipt = $this->receipt();

            try {
                $result = $sync->handle($this->chargebackId);
            } catch (PaymentGatewayException $exception) {
                if ($exception->inconclusive) {
                    throw $exception;
                }

                if ($receipt !== null) {
                    $receipts->markFailed($receipt, 'gateway_'.$exception->errorCode);
                }

                Log::warning('billing.chargeback.gateway_error', ['chargeback' => $this->chargebackId, 'error_code' => $exception->errorCode]);

                return;
            }

            if ($receipt !== null) {
                $result['outcome'] === 'processed'
                    ? $receipts->markProcessed($receipt)
                    : $receipts->markIgnored($receipt, (string) $result['reason']);
            }
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('billing.chargeback.sync_failed', [
            'alert' => 'billing_chargeback_sync_failed',
            'chargeback' => $this->chargebackId,
            'receipt_id' => $this->receiptId,
        ]);

        $receipt = $this->receipt();

        if ($receipt !== null) {
            app(WebhookReceipts::class)->markFailed($receipt, 'job_failed');
        }
    }

    private function receipt(): ?PaymentWebhookReceipt
    {
        return $this->receiptId === null ? null : PaymentWebhookReceipt::query()->find($this->receiptId);
    }
}
