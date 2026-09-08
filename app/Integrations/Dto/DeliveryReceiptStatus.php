<?php

namespace App\Integrations\Dto;

/**
 * Situação informada pelo provedor no momento do envio. Os valores coincidem
 * com App\Enums\DeliveryStatus (delivery_attempts.status); `delivered` e
 * `bounced` só chegam depois, por webhook/consulta. "sent" ≠ "delivered";
 * `unknown` (resposta inconclusiva) nunca é tratado como sucesso.
 */
enum DeliveryReceiptStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Unknown = 'unknown';

    public function isFailure(): bool
    {
        return $this === self::Failed;
    }

    public function isConclusive(): bool
    {
        return $this !== self::Unknown;
    }
}
