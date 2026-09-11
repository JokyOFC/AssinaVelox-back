<?php

namespace App\Services\Identity\Exceptions;

use RuntimeException;

/**
 * Imagem da captura recusada (formato, tamanho, dimensões, limite de envios). A mensagem é
 * para a pessoa que está na página pública, em português, e nunca ecoa o conteúdo enviado.
 */
final class CaptureRejectedException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
