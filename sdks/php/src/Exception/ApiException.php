<?php

declare(strict_types=1);

namespace AssinaVelox\Sdk\Exception;

/**
 * Resposta de erro da API (RFC 9457, `application/problem+json`).
 *
 * `type` é uma URN estável (`urn:assinavelox:problem:{slug}`): compare como texto e trate um
 * `type` desconhecido pelo `status`. Quando a resposta não é RFC 9457 (ex.: um proxy devolveu
 * HTML), `type` é `about:blank` e `title` é genérico.
 */
class ApiException extends AssinaVeloxException
{
    public const PROBLEM_PREFIX = 'urn:assinavelox:problem:';

    /**
     * @param  array<string, list<string>>  $errors  Erros por campo (422).
     * @param  array<string, mixed>  $problem  Corpo RFC 9457 completo (inclui extensões).
     * @param  array<string, string>  $headers  Cabeçalhos da resposta, com nomes em minúsculas.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $type,
        public readonly string $title,
        public readonly ?string $detail = null,
        public readonly ?string $instance = null,
        public readonly ?string $correlationId = null,
        public readonly array $errors = [],
        public readonly array $problem = [],
        public readonly array $headers = [],
    ) {
        $message = $status.' '.$title;

        if ($detail !== null && $detail !== '') {
            $message .= ': '.$detail;
        }

        $message .= ' ('.$type.')';

        if ($correlationId !== null) {
            $message .= ' [correlation_id '.$correlationId.']';
        }

        parent::__construct($message, $status);
    }

    /**
     * Sufixo do `type` (`not-found`, `validation-failed`...), ou null fora do padrão.
     */
    public function slug(): ?string
    {
        return str_starts_with($this->type, self::PROBLEM_PREFIX) ? substr($this->type, strlen(self::PROBLEM_PREFIX)) : null;
    }

    public function hasType(string $slug): bool
    {
        return $this->slug() === $slug;
    }

    /**
     * Segundos do cabeçalho `Retry-After` (429, 409 em processamento, 503).
     */
    public function retryAfter(): ?int
    {
        $value = trim($this->headers['retry-after'] ?? '');

        return ctype_digit($value) ? (int) $value : null;
    }

    /**
     * @param  array<string, string>  $headers  Nomes em minúsculas.
     */
    public static function fromResponse(int $status, array $headers, string $body): self
    {
        $problem = null;

        if ($body !== '' && str_contains($headers['content-type'] ?? '', 'json')) {
            try {
                $problem = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $problem = null;
            }
        }

        $correlation = $headers['x-correlation-id'] ?? null;

        if (is_array($problem) && is_string($problem['type'] ?? null) && is_string($problem['title'] ?? null)) {
            $errors = [];

            if (is_array($problem['errors'] ?? null)) {
                foreach ($problem['errors'] as $field => $messages) {
                    $errors[(string) $field] = array_values(array_map('strval', (array) $messages));
                }
            }

            $problemCorrelation = $problem['correlation_id'] ?? null;

            return new self(
                $status,
                $problem['type'],
                $problem['title'],
                is_string($problem['detail'] ?? null) ? $problem['detail'] : null,
                is_string($problem['instance'] ?? null) ? $problem['instance'] : null,
                is_string($problemCorrelation) ? $problemCorrelation : $correlation,
                $errors,
                $problem,
                $headers,
            );
        }

        return new self($status, 'about:blank', 'Erro HTTP '.$status, null, null, $correlation, [], [], $headers);
    }
}
