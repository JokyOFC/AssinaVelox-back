<?php

namespace App\Services\Ltv\Exceptions;

use RuntimeException;

/**
 * Falha de negócio do P3-LTV. A mensagem nunca carrega senha, token ou material de chave.
 */
final class LtvException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function disabled(): self
    {
        return new self('A flag pades_ltv está desligada (ou operator_tsa).', 'feature_disabled');
    }
}
