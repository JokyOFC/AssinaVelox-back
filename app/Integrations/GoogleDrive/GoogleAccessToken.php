<?php

namespace App\Integrations\GoogleDrive;

use LogicException;

/**
 * Access token do Google obtido no retorno do OAuth (escopo `drive.file`, sem refresh token).
 * Vive só na memória da requisição e, cifrado, na sessão durante a importação
 * (App\Services\CloudImport\GoogleImportSession). Nunca serializado, logado ou persistido.
 */
final class GoogleAccessToken
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $accessToken,
        public readonly int $expiresIn,
        public readonly array $scopes,
    ) {}

    public function value(): string
    {
        return $this->accessToken;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['accessToken' => '[redigido]', 'expiresIn' => $this->expiresIn, 'scopes' => $this->scopes];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('GoogleAccessToken não pode ser serializado.');
    }
}
