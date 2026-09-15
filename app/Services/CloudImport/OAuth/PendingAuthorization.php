<?php

namespace App\Services\CloudImport\OAuth;

/**
 * Autorização OAuth recém-iniciada: o `state` e o desafio PKCE vão na URL do provedor; o
 * verificador fica cifrado na sessão e só volta no consumo do `state`.
 */
final class PendingAuthorization
{
    public function __construct(
        public readonly string $state,
        #[\SensitiveParameter]
        private readonly ?string $verifier,
        public readonly ?string $challenge,
    ) {}

    public function verifier(): ?string
    {
        return $this->verifier;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['state' => '[redigido]', 'challenge' => $this->challenge];
    }
}
