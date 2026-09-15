<?php

declare(strict_types=1);

namespace AssinaVelox\Sdk\Http;

use AssinaVelox\Sdk\Exception\AssinaVeloxException;

/**
 * Resposta HTTP crua (uso interno e de transportes próprios).
 */
final class RawResponse
{
    /**
     * @param  array<string, string>  $headers  Nomes em minúsculas.
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function json(): mixed
    {
        if ($this->body === '') {
            return null;
        }

        try {
            return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $exception) {
            throw new AssinaVeloxException('A API devolveu um JSON inválido (status '.$this->status.').', 0, $exception);
        }
    }

    public function data(): mixed
    {
        $json = $this->json();

        return is_array($json) ? ($json['data'] ?? null) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        $json = $this->json();

        return is_array($json) && is_array($json['meta'] ?? null) ? $json['meta'] : [];
    }
}
