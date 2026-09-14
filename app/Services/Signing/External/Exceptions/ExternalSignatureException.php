<?php

namespace App\Services\Signing\External\Exceptions;

use RuntimeException;

/**
 * Recusa ou impedimento no fluxo de assinatura externa, com código estável e mensagem em
 * PT-BR para o participante. Nunca carrega digest, assinatura, CMS, caminho de arquivo ou
 * dado do certificado além do que a própria tela já mostra.
 */
class ExternalSignatureException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra  campos adicionais seguros para a resposta (ex.: `retry_after`)
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly string $field = 'signature',
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }
}
