<?php

namespace App\Services\Timestamp\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Falha ao emitir, verificar ou aplicar um carimbo do tempo da operadora.
 *
 * `errorCode` é o código estável (o `error.code` do pdftool ou um código nosso). A mensagem
 * nunca contém senha, chave ou token: o pdftool não os imprime e o runner ainda redige o
 * valor da senha por precaução.
 */
class TsaException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'tsa_error',
        public readonly ?int $exitCode = null,
        public readonly ?string $correlationId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
