<?php

namespace App\Services\Signing\Certificates\Exceptions;

use RuntimeException;

/**
 * A aplicação da assinatura do participante falhou e o pedido foi marcado `failed` (o
 * material cifrado já foi destruído). Não se tenta de novo sozinho: sem o PFX não há como.
 * O participante pode reenviar o certificado dentro do prazo.
 */
class ParticipantSignatureFailed extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
