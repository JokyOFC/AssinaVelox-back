<?php

namespace App\Integrations\Email;

use App\Enums\DeliveryStatus;
use App\Integrations\Dto\DeliveryReceipt;
use App\Integrations\Dto\DeliveryReceiptStatus;
use App\Models\DeliveryAttempt;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Escrita de `delivery_attempts` (arquitetura §3.1). Um único lugar decide o estado a
 * partir do recibo do provedor.
 *
 * Regra que não se negocia: **"enviado" não é "entregue"**. `delivered` só é gravado por
 * `markDelivered()`, quando existe evidência do provedor (webhook/consulta) — nunca a
 * partir do retorno síncrono do envio. Uma resposta inconclusiva vira `unknown`, que é
 * tratado como não-sucesso em toda a aplicação.
 */
class DeliveryRecorder
{
    /**
     * Tentativa já concluída com sucesso para este correlation_id? Se sim, repetir o envio
     * seria duplicar a mensagem — o chamador devolve a linha existente.
     */
    public function successFor(DeliveryContext $context): ?DeliveryAttempt
    {
        $attempt = $this->existingFor($context);

        if ($attempt === null) {
            return null;
        }

        return in_array($attempt->status, [DeliveryStatus::Sent, DeliveryStatus::Delivered], true)
            ? $attempt
            : null;
    }

    /**
     * Linha `queued` (ou a existente, reaproveitada numa retentativa).
     */
    public function queue(DeliveryContext $context, string $provider): DeliveryAttempt
    {
        $attempt = $this->existingFor($context);

        if ($attempt !== null) {
            $attempt->forceFill([
                'provider' => $provider,
                'status' => DeliveryStatus::Queued,
                'error_message' => null,
                'queued_at' => Carbon::now(),
                'meta' => $this->mergeMeta($attempt, ['retries' => (int) (($attempt->meta['retries'] ?? 0)) + 1]),
            ])->save();

            return $attempt;
        }

        return DeliveryAttempt::query()->create([
            'organization_id' => $context->organizationId,
            'envelope_id' => $context->envelopeId,
            'recipient_id' => $context->recipientId,
            'channel' => $context->channel,
            'provider' => $provider,
            'purpose' => $context->purpose,
            'to_address' => $context->toAddress,
            'status' => DeliveryStatus::Queued,
            'correlation_id' => $context->correlationId,
            'queued_at' => Carbon::now(),
            'meta' => $context->meta === [] ? null : $context->meta,
        ]);
    }

    /**
     * Aplica o recibo do provedor. `sent` é o melhor estado possível aqui.
     */
    public function record(DeliveryAttempt $attempt, DeliveryReceipt $receipt): DeliveryAttempt
    {
        $status = match ($receipt->status) {
            DeliveryReceiptStatus::Queued => DeliveryStatus::Queued,
            DeliveryReceiptStatus::Sent => DeliveryStatus::Sent,
            DeliveryReceiptStatus::Failed => DeliveryStatus::Failed,
            DeliveryReceiptStatus::Unknown => DeliveryStatus::Unknown,
        };

        $attempt->forceFill([
            'provider' => $receipt->provider,
            'status' => $status,
            'provider_message_id' => $receipt->providerMessageId ?? $attempt->provider_message_id,
            'error_message' => $receipt->error !== null ? Str::limit($receipt->error, 1000, '') : null,
            'sent_at' => $status === DeliveryStatus::Sent
                ? ($receipt->sentAt !== null ? Carbon::instance($receipt->sentAt) : Carbon::now())
                : $attempt->sent_at,
            'meta' => $this->mergeMeta($attempt, $receipt->meta),
        ])->save();

        return $attempt;
    }

    /**
     * Confirmação de ENTREGA — só com evidência do provedor (webhook ou consulta à API).
     * Nenhum caminho síncrono chama este método.
     *
     * @param  array<string, mixed>  $evidence
     */
    public function markDelivered(DeliveryAttempt $attempt, array $evidence, ?CarbonInterface $deliveredAt = null): DeliveryAttempt
    {
        $attempt->forceFill([
            'status' => DeliveryStatus::Delivered,
            'delivered_at' => $deliveredAt ?? Carbon::now(),
            'meta' => $this->mergeMeta($attempt, ['delivery_evidence' => $evidence]),
        ])->save();

        return $attempt;
    }

    /**
     * Devolução (bounce) informada pelo provedor.
     */
    public function markBounced(DeliveryAttempt $attempt, string $reason): DeliveryAttempt
    {
        $attempt->forceFill([
            'status' => DeliveryStatus::Bounced,
            'error_message' => Str::limit($reason, 1000, ''),
        ])->save();

        return $attempt;
    }

    private function existingFor(DeliveryContext $context): ?DeliveryAttempt
    {
        return DeliveryAttempt::withoutOrganizationScope()
            ->where('organization_id', $context->organizationId)
            ->where('correlation_id', $context->correlationId)
            ->where('purpose', $context->purpose->value)
            ->where('to_address', $context->toAddress)
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>|null
     */
    private function mergeMeta(DeliveryAttempt $attempt, array $extra): ?array
    {
        $meta = array_merge($attempt->meta ?? [], $extra);

        return $meta === [] ? null : $meta;
    }
}
