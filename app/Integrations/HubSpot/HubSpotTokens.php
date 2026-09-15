<?php

namespace App\Integrations\HubSpot;

use LogicException;

/**
 * Tokens devolvidos pelo HubSpot (troca do `code` ou renovação). Só em memória até serem
 * gravados CIFRADOS em `hubspot_connections`. Nunca serializados nem exibidos em dump.
 */
final class HubSpotTokens
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $accessToken,
        #[\SensitiveParameter]
        private readonly ?string $refreshToken,
        public readonly int $expiresIn,
        public readonly ?int $portalId = null,
        public readonly array $scopes = [],
    ) {}

    public function accessToken(): string
    {
        return $this->accessToken;
    }

    public function refreshToken(): ?string
    {
        return $this->refreshToken;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['accessToken' => '[redigido]', 'refreshToken' => $this->refreshToken === null ? null : '[redigido]', 'expiresIn' => $this->expiresIn, 'portalId' => $this->portalId];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('HubSpotTokens não pode ser serializado.');
    }
}
