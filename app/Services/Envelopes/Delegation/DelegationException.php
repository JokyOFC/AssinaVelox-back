<?php

namespace App\Services\Envelopes\Delegation;

use RuntimeException;

/**
 * Delegação recusada. A mensagem é a que a tela mostra (PT-BR, sem caminho interno nem dado
 * de outra pessoa); `errorCode` é estável para testes e para a interface; `status` é o HTTP.
 */
final class DelegationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly string $field = 'email',
    ) {
        parent::__construct($message);
    }
}
