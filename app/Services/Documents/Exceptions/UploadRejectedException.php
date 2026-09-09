<?php

namespace App\Services\Documents\Exceptions;

use RuntimeException;

/**
 * Arquivo recusado na entrada (App\Services\Documents\UploadInspector).
 *
 * `errorCode` é estável (snake_case) para log/auditoria; a mensagem é PT-BR e vai
 * direto para o usuário (erro de validação do campo `file`). Nunca contém caminhos
 * locais nem detalhes internos.
 */
class UploadRejectedException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context  dados numéricos para log (nunca conteúdo do arquivo)
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function make(string $errorCode, string $message, array $context = []): self
    {
        return new self($errorCode, $message, $context);
    }
}
