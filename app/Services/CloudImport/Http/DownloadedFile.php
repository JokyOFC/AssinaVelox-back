<?php

namespace App\Services\CloudImport\Http;

/**
 * Corpo de uma resposta gravado num temporário local, com o tamanho já conferido contra o teto.
 */
final class DownloadedFile
{
    public function __construct(
        public readonly string $path,
        public readonly int $sizeBytes,
        public readonly ?string $contentType,
    ) {}
}
