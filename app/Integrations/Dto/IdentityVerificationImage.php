<?php

namespace App\Integrations\Dto;

/**
 * Uma imagem da captura a caminho do provedor de verificação: os bytes em claro (já
 * decifrados do disco), o tipo e o nome de arquivo do multipart.
 *
 * Vive só na memória do job que faz o envio. Nunca é serializada para a fila, para o log nem
 * para o banco — quem entra na fila é o identificador da tentativa.
 */
final readonly class IdentityVerificationImage
{
    public function __construct(
        public string $bytes,
        public string $mimeType,
        public string $filename,
    ) {}

    public function sizeBytes(): int
    {
        return strlen($this->bytes);
    }
}
