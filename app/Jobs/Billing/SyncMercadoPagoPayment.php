<?php

namespace App\Jobs\Billing;

use App\Integrations\Payments\Exceptions\PaymentGatewayException;
use App\Models\PaymentWebhookReceipt;
use App\Services\Billing\BillingSettings;
use App\Services\Billing\SyncPaymentFromGateway;
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
 * Consulta o pagamento no provedor depois de um webhook validado.
 *
 * O controller responde 200 em milissegundos (o provedor espera no máximo 22 s e
 * reenvia se não receber); o trabalho real acontece aqui: `GET /v1/payments/{id}`, que é
 * a **fonte da verdade**. O payload do aviso não é usado para decidir nada — só carrega
 * o identificador.
 *
 * ## Por que este job NÃO é `ShouldBeUnique`
 *
 * Ele já foi. A intenção era boa — `payment.created` e `payment.updated` do mesmo
 * pagamento não deveriam virar duas consultas simultâneas —, mas o preço era alto demais:
 * `PendingDispatch::shouldDispatch()` **descarta a mensagem em silêncio** quando o lock de
 * unicidade está tomado. O segundo aviso simplesmente não virava job, o recibo dele ficava
 * em `received` para sempre e nenhuma reentrega do provedor recuperava o trabalho — um
 * aviso de aprovação perdido assim deixa o cliente pago e sem plano, sem nada em `failed`
 * para alertar.
 *
 * Perder trabalho é pior do que repeti-lo: uma consulta a mais ao provedor não muda nada
 * (a aplicação é idempotente em `ActivateSubscription`, guardada por `payments.activated_at`
 * sob `lockForUpdate`). A serialização que a unicidade dava continua existindo, mas onde ela
 * não descarta nada: um lock de cache tomado **dentro** do `handle()`. Sem o lock, o job
 * volta para a fila em vez de sumir.
 *
 * O payload do job carrega apenas identificadores — nunca o cabeçalho de assinatura,
 * nunca a chave secreta, nunca o access token.
 */
class SyncMercadoPagoPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Segundos que o lock de serialização por pagamento vale. */
    public const LOCK_SECONDS = 120;

    public int $tries = 5;

    public int $timeout = 120;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(
        public readonly string $providerPaymentId,
        public readonly ?int $receiptId = null,
    ) {
        $this->onQueue(app(BillingSettings::class)->queue());
    }

    public function handle(SyncPaymentFromGateway $sync, WebhookReceipts $receipts): void
    {
        $lock = Cache::lock('mercadopago-payment:'.$this->providerPaymentId, self::LOCK_SECONDS);

        if (! $lock->get()) {
            // Outro worker está consultando este mesmo pagamento agora. Devolver à fila é o
            // oposto de descartar: o trabalho continua existindo.
            $this->release(30);

            return;
        }

        try {
            $this->sync($sync, $receipts);
        } finally {
            $lock->release();
        }
    }

    private function sync(SyncPaymentFromGateway $sync, WebhookReceipts $receipts): void
    {
        $receipt = $this->receipt();

        try {
            $result = $sync->handle($this->providerPaymentId);
        } catch (PaymentGatewayException $exception) {
            // Resposta inconclusiva: a fila tenta de novo. Nada é aplicado.
            if ($exception->inconclusive) {
                throw $exception;
            }

            if ($receipt !== null) {
                $receipts->markFailed($receipt, 'gateway_'.$exception->errorCode);
            }

            Log::warning('billing.webhook.gateway_error', [
                'provider_payment_id' => $this->providerPaymentId,
                'error_code' => $exception->errorCode,
                'status' => $exception->status,
            ]);

            return;
        }

        if ($receipt === null) {
            return;
        }

        match ($result['outcome']) {
            'processed' => $receipts->markProcessed($receipt),
            'ignored' => $receipts->markIgnored($receipt, (string) $result['reason']),
            default => $receipts->markFailed($receipt, (string) $result['reason']),
        };
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('billing.webhook.sync_failed', [
            'alert' => 'billing_webhook_sync_failed',
            'provider_payment_id' => $this->providerPaymentId,
            'receipt_id' => $this->receiptId,
            'exception' => $exception?->getMessage(),
        ]);

        $receipt = $this->receipt();

        if ($receipt !== null) {
            app(WebhookReceipts::class)->markFailed($receipt, 'job_failed');
        }
    }

    private function receipt(): ?PaymentWebhookReceipt
    {
        return $this->receiptId === null
            ? null
            : PaymentWebhookReceipt::query()->find($this->receiptId);
    }
}
