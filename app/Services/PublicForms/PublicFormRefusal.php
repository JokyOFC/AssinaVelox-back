<?php

namespace App\Services\PublicForms;

use RuntimeException;

/**
 * Recusa de uma etapa pública (confirmação do e-mail). `reason` é estável (testes e tela);
 * a mensagem é a que o público lê, em PT-BR, sem detalhe interno da organização.
 */
final class PublicFormRefusal extends RuntimeException
{
    public const INVALID = 'invalid';

    public const EXPIRED = 'expired';

    public const USED = 'used';

    public const UNAVAILABLE = 'unavailable';

    public const LIMIT = 'limit';

    public const QUOTA = 'quota';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function invalid(): self
    {
        return new self(self::INVALID, 'Link de confirmação inválido. Confira se copiou o endereço inteiro do e-mail.');
    }

    public static function expired(): self
    {
        return new self(self::EXPIRED, 'Este link de confirmação venceu e os dados enviados foram descartados. Preencha o formulário de novo.');
    }

    public static function used(): self
    {
        return new self(self::USED, 'Este link de confirmação já foi usado. Cada link vale uma única vez.');
    }

    public static function unavailable(string $state): self
    {
        return new self(self::UNAVAILABLE, PublicFormAvailability::message($state));
    }

    public static function limit(): self
    {
        return new self(self::LIMIT, 'Este formulário atingiu o limite de respostas do período. Tente mais tarde ou fale com quem enviou o link.');
    }

    public static function quota(string $message): self
    {
        return new self(self::QUOTA, $message);
    }
}
