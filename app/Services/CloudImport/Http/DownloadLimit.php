<?php

namespace App\Services\CloudImport\Http;

/**
 * Teto de bytes de um download, compartilhado entre os callbacks do Guzzle (`on_headers`,
 * `progress`) e quem chamou: uma vez passado, fica marcado.
 */
final class DownloadLimit
{
    private bool $exceeded = false;

    public function __construct(public readonly int $maxBytes) {}

    public function exceededBy(int $bytes): bool
    {
        if ($bytes > $this->maxBytes) {
            $this->exceeded = true;
        }

        return $this->exceeded;
    }

    public function exceeded(): bool
    {
        return $this->exceeded;
    }
}
