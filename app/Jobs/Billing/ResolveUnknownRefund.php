<?php

namespace App\Jobs\Billing;

use App\Models\PaymentRefund;
use App\Services\Billing\BillingSettings;
use App\Services\Billing\RequestRefund;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Resolve um estorno que ficou `unknown` por timeout (Fase 2, onda D): consulta a lista de
 * estornos do pagamento no provedor ANTES de repetir (T5). Continua `unknown` quando a consulta
 * também falha — e tenta de novo mais tarde, com espaçamento crescente.
 */
class ResolveUnknownRefund implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public readonly int $refundId)
    {
        $this->onQueue(app(BillingSettings::class)->queue());
    }

    public function handle(RequestRefund $refunds): void
    {
        $refund = PaymentRefund::withoutOrganizationScope()->find($this->refundId);

        if ($refund === null || $refund->status !== PaymentRefund::STATUS_UNKNOWN) {
            return;
        }

        $refund = $refunds->resolveUnknown($refund);

        if ($refund->status === PaymentRefund::STATUS_UNKNOWN) {
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);

                return;
            }

            Log::error('billing.refund.unresolved', [
                'alert' => 'billing_refund_unresolved',
                'refund' => $refund->ulid,
            ]);
        }
    }
}
