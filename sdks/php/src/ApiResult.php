<?php

declare(strict_types=1);

namespace AssinaVelox\Sdk;

/**
 * Resposta com `data` e `meta` (ex.: `sendEnvelope` traz `meta.invitations_sent`).
 *
 * @template T
 */
final class ApiResult
{
    /**
     * @param  T  $data
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly mixed $data,
        public readonly array $meta = [],
    ) {}
}
