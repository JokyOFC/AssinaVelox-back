<?php

namespace App\Services\Templates;

use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Recusa de um arquivo/conteúdo de modelo, com mensagem PT-BR pronta para o usuário.
 * `reason` é um código estável (para testes e logs); nunca carrega o conteúdo recusado.
 */
final class TemplateRejectedException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly string $attribute = 'file',
    ) {
        parent::__construct($message);
    }

    public static function make(string $reason, string $message, string $attribute = 'file'): self
    {
        return new self($reason, $message, $attribute);
    }

    public function toValidationException(): ValidationException
    {
        return ValidationException::withMessages([$this->attribute => $this->getMessage()]);
    }
}
