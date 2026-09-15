<?php

namespace App\Services\CloudImport\OAuth;

/**
 * `state` válido e já consumido: o contexto de quem iniciou e o `code_verifier` do PKCE.
 */
final class ConsumedAuthorization
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        public readonly array $context,
        #[\SensitiveParameter]
        private readonly ?string $verifier,
    ) {}

    public function verifier(): ?string
    {
        return $this->verifier;
    }

    public function contextValue(string $key): string|int|float|bool|null
    {
        return $this->context[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['context' => $this->context, 'verifier' => $this->verifier === null ? null : '[redigido]'];
    }
}
