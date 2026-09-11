<?php

namespace App\Integrations\Contracts\Messaging;

use App\Enums\DeliveryStatus;
use DateTimeImmutable;

/**
 * Situação de uma mensagem informada pelo provedor — por webhook de status ou por consulta.
 *
 * `delivered` só vale como evidência quando veio de um aviso com assinatura válida ou de uma
 * consulta ao provedor (nunca do retorno síncrono do envio). `unknown` nunca é sucesso.
 */
final readonly class ChannelStatusEvent
{
    public function __construct(
        public string $providerMessageId,
        public DeliveryStatus $status,
        public ?string $eventId = null,
        public ?DateTimeImmutable $occurredAt = null,
        public ?string $detail = null,
    ) {}
}
