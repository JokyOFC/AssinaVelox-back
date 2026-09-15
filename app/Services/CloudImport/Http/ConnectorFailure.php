<?php

namespace App\Services\CloudImport\Http;

use RuntimeException;

/**
 * Falha de uma chamada a provedor externo (Google, Dropbox, HubSpot). A mensagem só traz o
 * CÓDIGO estável — nunca URL, cabeçalho, corpo de resposta ou token (T10).
 *
 * Códigos: `blocked_url` (proteção contra SSRF), `timeout`, `connection_failed`,
 * `too_large`, `empty`, `invalid_response`, `http_{status}`.
 */
final class ConnectorFailure extends RuntimeException
{
    public const BLOCKED_URL = 'blocked_url';

    public const TIMEOUT = 'timeout';

    public const CONNECTION_FAILED = 'connection_failed';

    public const TOO_LARGE = 'too_large';

    public const EMPTY = 'empty';

    public const INVALID_RESPONSE = 'invalid_response';

    public function __construct(
        public readonly string $errorCode,
        public readonly ?int $httpStatus = null,
        public readonly ?string $blockedReason = null,
    ) {
        parent::__construct('Chamada ao provedor falhou: '.$errorCode);
    }

    public static function http(int $status): self
    {
        return new self('http_'.$status, $status);
    }

    public function isTimeout(): bool
    {
        return $this->errorCode === self::TIMEOUT;
    }
}
