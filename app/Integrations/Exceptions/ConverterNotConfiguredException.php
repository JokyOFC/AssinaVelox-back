<?php

namespace App\Integrations\Exceptions;

/**
 * Conversor sem configuração utilizável (ex.: LIBREOFFICE_BIN vazio). Erro de
 * operação, não do documento: o job deve falhar de forma visível, não marcar o
 * documento como inválido.
 */
class ConverterNotConfiguredException extends IntegrationException
{
    public static function for(string $converter, string $hint): self
    {
        return new self(sprintf('Conversor %s não configurado: %s', $converter, $hint));
    }
}
