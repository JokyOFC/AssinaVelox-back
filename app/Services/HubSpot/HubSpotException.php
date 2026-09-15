<?php

namespace App\Services\HubSpot;

use RuntimeException;

/**
 * Recusa de uma operação do app HubSpot (conectar, renovar, executar a ação). `errorCode` é
 * estável; `userMessage()` é o texto PT-BR da tela. Nenhum dos dois carrega token.
 */
final class HubSpotException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        private readonly string $userMessage,
    ) {
        parent::__construct('HubSpot: '.$errorCode);
    }

    public function userMessage(): string
    {
        return $this->userMessage;
    }
}
