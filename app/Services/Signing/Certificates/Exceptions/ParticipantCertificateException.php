<?php

namespace App\Services\Signing\Certificates\Exceptions;

use RuntimeException;

/**
 * Recusa prevista no fluxo do certificado do participante, com mensagem clara em PT-BR para a
 * tela e um `errorCode` estável. Nunca carrega senha, bytes do PFX ou CPF completo.
 */
class ParticipantCertificateException extends RuntimeException
{
    /**
     * @param  string  $field  campo do formulário a que o erro se refere (`certificate` | `password` | `consent`)
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly string $field = 'certificate',
    ) {
        parent::__construct($message);
    }
}
