<?php

namespace App\Integrations\Contracts\Messaging;

/**
 * Resultado da validação da assinatura de um aviso de status. `reason` é um código estável
 * (missing_signature, invalid_signature, outside_window, secret_not_configured,
 * provider_disabled) — nunca o segredo nem a assinatura esperada.
 */
final readonly class StatusCallbackVerification
{
    private function __construct(
        public bool $valid,
        public ?string $reason,
    ) {}

    public static function valid(): self
    {
        return new self(true, null);
    }

    public static function invalid(string $reason): self
    {
        return new self(false, $reason);
    }
}
