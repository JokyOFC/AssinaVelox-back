<?php

namespace App\Services\Branding\Exceptions;

use RuntimeException;

/**
 * Logo recusado. `$reason` é um código estável (para testes e métricas); a mensagem é
 * PT-BR e pode ir para a tela como erro do campo `logo`.
 */
final class LogoRejectedException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
