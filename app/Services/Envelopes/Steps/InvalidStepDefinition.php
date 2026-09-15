<?php

namespace App\Services\Envelopes\Steps;

use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Definição de etapas recusada pelo esquema fechado. `field` é o caminho do campo
 * (`steps.1.condition.rules.0.recipient`) e a mensagem já é a que a tela mostra.
 */
final class InvalidStepDefinition extends RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    public function toValidationException(): ValidationException
    {
        return ValidationException::withMessages([$this->field => $this->getMessage()]);
    }
}
