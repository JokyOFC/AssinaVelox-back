<?php

declare(strict_types=1);

namespace AssinaVelox\Sdk;

use AssinaVelox\Sdk\Exception\InvalidRequestException;

/**
 * Arquivo para `uploadDocument` (multipart, campo `file`). A API não aceita upload por URL.
 */
final class FileUpload
{
    private const TYPES = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'doc' => 'application/msword',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
    ];

    public function __construct(
        public readonly string $content,
        public readonly string $filename,
        public readonly string $contentType = 'application/octet-stream',
    ) {}

    public static function fromString(string $content, string $filename, string $contentType = 'application/octet-stream'): self
    {
        return new self($content, $filename, $contentType);
    }

    public static function fromPath(string $path, ?string $contentType = null): self
    {
        $content = is_file($path) ? file_get_contents($path) : false;

        if ($content === false) {
            throw new InvalidRequestException(sprintf('Arquivo não encontrado ou ilegível: %s', basename($path)));
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return new self($content, basename($path), $contentType ?? self::TYPES[$extension] ?? 'application/octet-stream');
    }
}
