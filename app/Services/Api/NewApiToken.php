<?php

namespace App\Services\Api;

use App\Models\ApiToken;

/**
 * Resultado da criação de um token: o registro e o TEXTO do token, que só existe aqui.
 * Mostre `plainTextToken` uma única vez; ele não é guardado em lugar nenhum.
 */
final readonly class NewApiToken
{
    public function __construct(
        public ApiToken $token,
        public string $plainTextToken,
    ) {}
}
