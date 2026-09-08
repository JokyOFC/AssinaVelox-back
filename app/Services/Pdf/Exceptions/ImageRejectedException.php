<?php

namespace App\Services\Pdf\Exceptions;

use InvalidArgumentException;

/**
 * Imagem recusada pela normalização em PHP (GD) antes de chegar ao pdftool:
 * formato não suportado (SVG, GIF, BMP...), arquivo inválido, acima dos limites.
 */
class ImageRejectedException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
