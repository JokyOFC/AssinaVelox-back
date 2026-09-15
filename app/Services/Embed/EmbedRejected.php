<?php

namespace App\Services\Embed;

use RuntimeException;

/**
 * Recusa prevista do widget embutido (docs/fase-3/widget-embutido.md §6). `slug` é estável e
 * vai para o cliente (API: sufixo do `type` do problem+json; widget: `code` do JSON e da
 * mensagem `assinavelox:error`); a mensagem é PT-BR, escrita pela aplicação, sem dado pessoal.
 */
final class EmbedRejected extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extensions
     */
    public function __construct(
        public readonly string $slug,
        string $message,
        public readonly int $status = 409,
        public readonly array $extensions = [],
    ) {
        parent::__construct($message);
    }
}
