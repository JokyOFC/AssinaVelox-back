<?php

namespace App\Services\Envelopes\Sending\Dto;

use App\Models\RecipientAccessLink;

/**
 * Link recém-emitido. O `token` em claro existe apenas nesta instância e na mensagem de
 * e-mail — o banco guarda só `token_digest` (SHA-256).
 *
 * NUNCA serialize, registre em log, grave em `audit_events`/`delivery_attempts` nem
 * devolva em resposta HTTP. `__toString`/`__debugInfo` são deliberadamente redigidos para
 * que um `dd()`/`Log::info()` distraído não vaze o token.
 */
final readonly class IssuedLink
{
    public function __construct(
        public RecipientAccessLink $link,
        public string $token,
        public string $url,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'link' => $this->link->ulid,
            'token' => '[REDACTED]',
            'url' => '[REDACTED]',
        ];
    }
}
