<?php

namespace App\Services\Billing;

use App\Enums\WebhookProcessingStatus;
use App\Models\PaymentWebhookReceipt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Recibos de webhook — a idempotência é da COLUNA.
 *
 * `payment_webhook_receipts` tem UNIQUE(`provider`, `event_fingerprint`), e o
 * fingerprint é `type:data.id:action`. Uma reentrega do Mercado Pago (ele repete até
 * receber 200: 0, 15 e 30 min, 6 h, 48 h e 96 h) traz exatamente o mesmo trio e colide
 * com a linha existente — o `firstOrCreate` devolve a linha antiga e
 * `$receipt->wasRecentlyCreated` diz se este processo é o primeiro a vê-la. Só o
 * primeiro despacha o job.
 *
 * Um recibo que ficou `failed` **ou** `received` pode ser reprocessado numa reentrega; um
 * `processed` ou `ignored`, nunca. Ver {@see self::shouldProcess()}.
 */
class WebhookReceipts
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $provider,
        string $fingerprint,
        ?string $topic,
        ?string $action,
        array $payload,
        ?string $signatureHeader,
        bool $signatureValid,
    ): PaymentWebhookReceipt {
        $attributes = [
            'topic' => $topic,
            'action' => $action,
            'payload' => $payload,
            'signature_header' => $signatureHeader,
            'signature_valid' => $signatureValid,
            'received_at' => Carbon::now(),
            'processing_status' => WebhookProcessingStatus::Received,
        ];

        try {
            return PaymentWebhookReceipt::query()->firstOrCreate(
                ['provider' => $provider, 'event_fingerprint' => $fingerprint],
                $attributes,
            );
        } catch (QueryException $exception) {
            // Corrida perdida entre duas entregas simultâneas do mesmo evento: a linha
            // da outra vale, e ela é que despachou o job.
            $existing = PaymentWebhookReceipt::query()
                ->where('provider', $provider)
                ->where('event_fingerprint', $fingerprint)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }

    /**
     * Janela, em segundos, depois da qual um recibo ainda em `received` é considerado
     * trabalho perdido e vira alerta operacional no log. O valor não decide o
     * reprocessamento (ver {@see self::shouldProcess()}); ele só separa "chegou agora" de
     * "está parado há tempo demais".
     */
    public const STALE_RECEIVED_SECONDS = 300;

    /**
     * Este recibo deve gerar processamento agora?
     *
     * `processed` e `ignored` são desfechos: uma reentrega deles não produz trabalho. Os
     * outros dois estados, sim:
     *
     * - `failed` é a nova chance óbvia;
     * - **`received` também**. Ele significa "gravamos o aviso e ainda não chegamos a um
     *   desfecho". Tratá-lo como "já em processamento" para sempre era a origem de um
     *   defeito silencioso: quando o job se perdia — descartado por trava de unicidade no
     *   despacho, worker morto por OOM/SIGKILL entre a retirada da fila e o `handle()`,
     *   `queue:flush`, Redis reiniciado sem persistência — todas as reentregas do Mercado
     *   Pago (0, 15 e 30 min, 6 h, 48 h e 96 h, a única rede de segurança que ele oferece)
     *   respondiam `200 {"duplicate": true}` sem produzir nada. Um aviso de aprovação
     *   perdido assim deixa o pagamento local em `pending`, a assinatura sem ativação e
     *   nada em `failed` para alertar.
     *
     * Reprocessar é barato e seguro: o trabalho é uma consulta `GET /v1/payments/{id}` e a
     * aplicação é idempotente por `payments.activated_at` sob lock. Perder o aviso não é
     * nem barato nem seguro.
     */
    public function shouldProcess(PaymentWebhookReceipt $receipt): bool
    {
        if ($receipt->wasRecentlyCreated) {
            return true;
        }

        if ($receipt->processing_status === WebhookProcessingStatus::Failed) {
            return true;
        }

        if ($receipt->processing_status !== WebhookProcessingStatus::Received) {
            return false;
        }

        $age = $receipt->received_at->diffInSeconds(Carbon::now(), true);

        if ($age > self::STALE_RECEIVED_SECONDS) {
            // Um recibo que não virou desfecho nesse prazo é trabalho perdido, não trabalho
            // em curso: fica visível em vez de invisível.
            Log::warning('billing.webhook.receipt_stuck', [
                'alert' => 'billing_webhook_receipt_stuck',
                'receipt_id' => $receipt->getKey(),
                'fingerprint' => $receipt->event_fingerprint,
                'received_at' => $receipt->received_at->toIso8601String(),
                'age_seconds' => (int) $age,
            ]);
        }

        return true;
    }

    public function markProcessed(PaymentWebhookReceipt $receipt, ?string $note = null): void
    {
        $receipt->forceFill([
            'processing_status' => WebhookProcessingStatus::Processed,
            'processed_at' => Carbon::now(),
            'error' => $note,
        ])->save();
    }

    public function markIgnored(PaymentWebhookReceipt $receipt, string $reason): void
    {
        $receipt->forceFill([
            'processing_status' => WebhookProcessingStatus::Ignored,
            'processed_at' => Carbon::now(),
            'error' => $reason,
        ])->save();
    }

    public function markFailed(PaymentWebhookReceipt $receipt, string $reason): void
    {
        $receipt->forceFill([
            'processing_status' => WebhookProcessingStatus::Failed,
            'processed_at' => Carbon::now(),
            'error' => $reason,
        ])->save();
    }
}
