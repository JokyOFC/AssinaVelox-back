<?php

declare(strict_types=1);

namespace AssinaVelox\Sdk;

use AssinaVelox\Sdk\Http\RawResponse;

/**
 * Arquivo baixado de `downloadFile` (original, assinado ou evidências).
 */
final class DownloadedFile
{
    public function __construct(
        public readonly string $content,
        public readonly ?string $contentType,
        public readonly ?string $filename,
    ) {}

    public function save(string $path): string
    {
        file_put_contents($path, $this->content);

        return $path;
    }

    public static function fromResponse(RawResponse $response): self
    {
        $disposition = $response->header('content-disposition') ?? '';
        $filename = null;

        if (preg_match("/filename\\*\\s*=\\s*UTF-8''([^;]+)/i", $disposition, $match) === 1) {
            $filename = rawurldecode(trim($match[1]));
        } elseif (preg_match('/filename\s*=\s*"([^"]*)"/', $disposition, $match) === 1 || preg_match('/filename\s*=\s*([^;]+)/', $disposition, $match) === 1) {
            $filename = trim($match[1]);
        }

        $type = $response->header('content-type');

        return new self($response->body, $type === null ? null : trim(explode(';', $type)[0]), $filename);
    }
}
