<?php

declare(strict_types=1);

namespace AssinaVelox\Sdk;

/**
 * Opções de uma chamada: Idempotency-Key, tempo limite e cabeçalhos extras.
 *
 *     $client->createEnvelope($body, new RequestOptions(idempotencyKey: 'pedido-42'));
 */
final class RequestOptions
{
    /**
     * @param  string|null  $idempotencyKey  1 a 255 caracteres ASCII visíveis. Nas criações e no envio, sem chave o SDK gera um UUID v4.
     * @param  float|null  $timeout  Segundos; null usa o do cliente.
     * @param  array<string, string>  $headers  Cabeçalhos extras (não substituem Authorization, Accept nem User-Agent).
     */
    public function __construct(
        public readonly ?string $idempotencyKey = null,
        public readonly ?float $timeout = null,
        public readonly array $headers = [],
    ) {}
}
