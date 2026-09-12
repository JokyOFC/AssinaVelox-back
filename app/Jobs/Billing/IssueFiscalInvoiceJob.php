<?php

namespace App\Jobs\Billing;

use App\Models\Payment;
use App\Services\Billing\BillingSettings;
use App\Services\Fiscal\IssueFiscalInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * NFS-e de um pagamento aprovado (roadmap §2.21, classe B). Idempotente por pagamento; sem
 * provedor configurado não faz nada. O payload leva só o id do pagamento.
 */
class IssueFiscalInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 600];

    public function __construct(public readonly int $paymentId)
    {
        $this->onQueue(app(BillingSettings::class)->queue());
    }

    public function handle(IssueFiscalInvoice $issue): void
    {
        $payment = Payment::withoutOrganizationScope()->find($this->paymentId);

        if ($payment !== null) {
            $issue->handle($payment);
        }
    }
}
