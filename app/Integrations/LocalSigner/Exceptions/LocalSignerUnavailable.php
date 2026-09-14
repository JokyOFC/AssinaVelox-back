<?php

namespace App\Integrations\LocalSigner\Exceptions;

use RuntimeException;

/**
 * O componente não pode ser usado aqui. `reason` é estável (`production_disabled`,
 * `environment_not_allowed`, `disabled`, `pfx_not_configured`, `passphrase_not_configured`,
 * `pfx_unreadable`, `unsupported_key`, `invalid_request`). A mensagem nunca carrega senha,
 * chave ou caminho de arquivo.
 */
class LocalSignerUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function productionDisabled(string $component): self
    {
        return new self('production_disabled', sprintf('O componente %s está com a produção desabilitada nesta versão.', $component));
    }
}
