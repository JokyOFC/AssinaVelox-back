<?php

namespace App\Services\Webhooks;

/**
 * Resultado de UMA tentativa de entrega.
 *
 *  - `succeeded`: resposta 2xx;
 *  - `failed`: conexão recusada, 3xx (não seguimos redirecionamento), 4xx ou 5xx;
 *  - `unknown`: tempo esgotado — o receptor PODE ter recebido (T5). Conta como falha para a
 *    retentativa; o receptor deduplica pelo id da entrega;
 *  - `blocked`: a proteção contra SSRF recusou o destino nesta tentativa (o DNS mudou para
 *    um endereço interno, por exemplo). Nenhum byte saiu.
 */
final class TransportResult
{
    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const UNKNOWN = 'unknown';

    public const BLOCKED = 'blocked';

    public function __construct(
        public readonly string $outcome,
        public readonly ?int $statusCode = null,
        public readonly ?int $durationMs = null,
        public readonly ?string $excerpt = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $remoteIp = null,
    ) {}

    public static function blocked(string $reason): self
    {
        return new self(self::BLOCKED, errorCode: 'ssrf_'.$reason);
    }

    public function succeeded(): bool
    {
        return $this->outcome === self::SUCCEEDED;
    }
}
