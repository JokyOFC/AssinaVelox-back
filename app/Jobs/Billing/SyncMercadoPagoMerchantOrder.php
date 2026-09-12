<?php

namespace App\Jobs\Billing;

use App\Integrations\Payments\Exceptions\PaymentGatewayException;
use App\Models\PaymentWebhookReceipt;
use App\Services\Billing\BillingSettings;
use App\Services\Billing\SyncMerchantOrderFromGateway;
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
 * Consulta a ordem comercial depois de um webhook `merchant_order` validado (Fase 2, onda D) e
 * sincroniza cada pagamento nosso pela consulta GET /v1/payments/{id}.
 */
class SyncMercadoPagoMerchantOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(
        public readonly string $merchantOrderId,
        public readonly ?int $receiptId = null,
    ) {
        $this->onQueue(app(BillingSettings::class)->queue());
    }

    public function handle(SyncMerchantOrderFromGateway $sync, WebhookReceipts $receipts): void
    {
        $lock = Cache::lock('mercadopago-merchant-order:'.$this->merchantOrderId, 120);

        if (! $lock->get()) {
            $this->release(30);

            return;
        }

        try {
            $receipt = $this->receipt();

            try {
                $result = $sync->handle($this->merchantOrderId);
            } catch (PaymentGatewayException $exception) {
                if ($exception->inconclusive) {
                    throw $exception;
                }

                if ($receipt !== null) {
                    $receipts->markFailed($receipt, 'gateway_'.$exception->errorCode);
                }

                Log::warning('billing.merchant_order.gateway_error', ['merchant_order' => $this->merchantOrderId, 'error_code' => $exception->errorCode]);

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
        Log::error('billing.merchant_order.sync_failed', [
            'alert' => 'billing_merchant_order_sync_failed',
            'merchant_order' => $this->merchantOrderId,
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
