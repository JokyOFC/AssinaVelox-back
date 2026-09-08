<?php

namespace App\Integrations\Exceptions;

class UnsupportedSourceTypeException extends IntegrationException
{
    public static function for(string $sourceType, string $converter): self
    {
        return new self(sprintf('%s não converte documentos do tipo "%s".', $converter, $sourceType));
    }
}
