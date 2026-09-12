<?php

namespace App\Services\Api\Exceptions;

use App\Enums\ApiAbility;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Problema RFC 9457 lançado pela API v1 com `type` e título próprios
 * (renderizado por App\Services\Api\ApiProblem). É um HttpException: não vai para o log de
 * erros, como qualquer 4xx.
 */
final class ApiProblemException extends HttpException
{
    /**
     * @param  array<string, mixed>  $extensions
     * @param  array<string, string|int>  $headers
     */
    public function __construct(
        int $status,
        public readonly string $slug,
        public readonly string $title,
        ?string $detail = null,
        public readonly array $extensions = [],
        array $headers = [],
    ) {
        parent::__construct($status, (string) $detail, null, $headers);
    }

    /**
     * @param  array<string, mixed>  $extensions
     */
    public static function conflict(string $slug, string $detail, array $extensions = [], string $title = 'Conflito com o estado atual'): self
    {
        return new self(409, $slug, $title, $detail, $extensions);
    }

    public static function missingAbility(ApiAbility $ability): self
    {
        return new self(
            403,
            'missing-ability',
            'Ability ausente no token',
            sprintf('Este token não tem a ability "%s".', $ability->value),
            ['required_ability' => $ability->value],
        );
    }

    public static function creatorLacksPermission(ApiAbility $ability): self
    {
        return new self(
            403,
            'creator-lacks-permission',
            'Permissão insuficiente',
            sprintf('Quem criou este token não tem mais, nesta organização, a permissão exigida pela ability "%s".', $ability->value),
            ['required_ability' => $ability->value],
        );
    }
}
