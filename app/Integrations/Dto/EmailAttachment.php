<?php

namespace App\Integrations\Dto;

/**
 * Anexo de e-mail referenciado por caminho local (o provedor lê os bytes no
 * envio; nada de conteúdo binário serializado na fila).
 */
final readonly class EmailAttachment
{
    public function __construct(
        public string $path,
        public string $filename,
        public string $mimeType = 'application/octet-stream',
    ) {}
}
