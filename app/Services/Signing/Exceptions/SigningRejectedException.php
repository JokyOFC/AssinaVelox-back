<?php

namespace App\Services\Signing\Exceptions;

use RuntimeException;

/**
 * Recusa prevista do fluxo público (código expirado, fora da vez, sessão vencida, aceite já
 * gravado, imagem inválida...).
 *
 * Carrega um `errorCode` estável para teste e log e uma mensagem **em PT-BR já pronta para a
 * tela**. A mensagem nunca contém token, código OTP nem e-mail completo: a exceção pode
 * acabar em um log, e o log não é lugar de segredo.
 */
class SigningRejectedException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $retryAfterSeconds = null,
        public readonly int $status = 422,
        /** @var array<string, mixed> */
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function rateLimited(string $message, int $retryAfterSeconds): self
    {
        return new self('rate_limited', $message, $retryAfterSeconds, 429);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function conflict(string $errorCode, string $message, array $context = []): self
    {
        return new self($errorCode, $message, null, 409, $context);
    }
}
