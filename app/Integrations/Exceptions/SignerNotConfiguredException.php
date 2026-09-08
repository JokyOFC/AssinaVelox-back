<?php

namespace App\Integrations\Exceptions;

/**
 * Nenhum certificado A1 configurado. O envelope deve concluir como "aceite
 * eletrônico com evidências" (signature_status=none); nunca simular assinatura
 * criptográfica.
 */
class SignerNotConfiguredException extends IntegrationException
{
    public static function make(?string $hint = null): self
    {
        return new self('Assinatura criptográfica indisponível: nenhum certificado A1 configurado.'.($hint ? " {$hint}" : ''));
    }
}
