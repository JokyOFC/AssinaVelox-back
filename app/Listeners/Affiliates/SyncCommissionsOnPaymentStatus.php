<?php

namespace App\Listeners\Affiliates;

use App\Models\Payment;
use App\Services\Affiliates\AffiliatesFeature;
use App\Services\Affiliates\CommissionLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pagamento aprovado, estornado ou contestado → razão de comissões (Fase 3 §3.10).
 *
 * Gancho `eloquent.saved` de Payment, registrado por AffiliatesServiceProvider (o método não se
 * chama `handle` para a descoberta automática não registrá-lo de novo). Roda DEPOIS do commit da
 * transação que gravou o pagamento e nunca lança: um problema aqui não pode desfazer nem atrasar
 * a cobrança. O que falhar é refeito pela varredura diária `affiliates:settle`.
 */
final class SyncCommissionsOnPaymentStatus
{
    public function onPaymentSaved(Payment $payment): void
    {
        if (! AffiliatesFeature::enabled()) {
            return;
        }

        if (! $payment->wasRecentlyCreated && ! $payment->wasChanged(['status', 'refunded_cents', 'paid_at'])) {
            return;
        }

        $paymentId = (int) $payment->getKey();

        DB::afterCommit(function () use ($paymentId): void {
            try {
                $fresh = Payment::withoutOrganizationScope()->find($paymentId);

                if ($fresh !== null) {
                    app(CommissionLedger::class)->syncPayment($fresh);
                }
            } catch (Throwable $exception) {
                Log::error('affiliates.commission.sync_failed', [
                    'payment_id' => $paymentId,
                    'exception' => $exception::class,
                    'message' => mb_substr($exception->getMessage(), 0, 300),
                ]);
            }
        });
    }
}
