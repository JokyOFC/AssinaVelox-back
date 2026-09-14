<?php

namespace App\Services\Signing\GovBr\Exceptions;

use RuntimeException;

/**
 * Recusa ou impedimento no fluxo de devolução (P3-GOV). `errorCode` é estável (contrato com
 * o front, docs/fase-3/gov-br.md §6); a mensagem é PT-BR, para o participante, sem dado
 * pessoal nem detalhe interno.
 */
class GovBrReturnException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly string $field = 'file',
    ) {
        parent::__construct($message);
    }
}
