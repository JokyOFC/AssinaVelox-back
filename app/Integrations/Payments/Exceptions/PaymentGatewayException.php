<?php

namespace App\Integrations\Payments\Exceptions;

use App\Integrations\Exceptions\IntegrationException;

/**
 * Falha ao falar com o gateway de pagamento.
 *
 * `inconclusive = true` marca a **ambiguidade de timeout**: a requisição saiu, mas não
 * sabemos se o provedor a processou. Nesse caso o chamador NUNCA pode tratar como
 * sucesso nem recriar cegamente o recurso — precisa consultar antes (ver
 * App\Services\Billing\StartCheckout).
 *
 * A mensagem nunca carrega credencial: só status HTTP, código de erro do provedor e o
 * identificador de correlação.
 */
class PaymentGatewayException extends IntegrationException
{
    private function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly bool $inconclusive = false,
        public readonly ?int $status = null,
        public readonly ?string $correlationId = null,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(string $gateway = 'mercadopago'): self
    {
        return new self(
            "Gateway de pagamento [{$gateway}] desabilitado: nenhuma credencial configurada.",
            'gateway_not_configured',
        );
    }

    public static function inconclusive(string $operation, ?string $correlationId = null, ?int $status = null): self
    {
        return new self(
            "Resposta inconclusiva do gateway em [{$operation}]: não é possível afirmar sucesso nem falha.",
            'inconclusive',
            true,
            $status,
            $correlationId,
        );
    }

    public static function rejected(string $operation, int $status, ?string $providerCode = null, ?string $correlationId = null): self
    {
        $suffix = $providerCode !== null ? " (código do provedor: {$providerCode})" : '';

        return new self(
            "O gateway recusou [{$operation}] com HTTP {$status}{$suffix}.",
            'rejected',
            false,
            $status,
            $correlationId,
        );
    }

    public static function malformedResponse(string $operation, string $detail, ?string $correlationId = null): self
    {
        return new self(
            "Resposta do gateway em [{$operation}] fora do formato esperado: {$detail}.",
            'malformed_response',
            false,
            null,
            $correlationId,
        );
    }
}
