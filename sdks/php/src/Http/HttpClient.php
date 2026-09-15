<?php

declare(strict_types=1);

namespace AssinaVelox\Sdk\Http;

use AssinaVelox\Sdk\Exception\ApiException;
use AssinaVelox\Sdk\Exception\InvalidRequestException;
use AssinaVelox\Sdk\FileUpload;
use AssinaVelox\Sdk\RequestOptions;
use AssinaVelox\Sdk\Version;

/**
 * Núcleo HTTP do SDK (uso interno): URL, cabeçalhos, corpo, idempotência e erros RFC 9457.
 *
 * O token nunca aparece em mensagens de erro, em var_dump()/print_r() ({@see __debugInfo()})
 * nem em rastros de pilha no PHP 8.2+ (#[\SensitiveParameter]).
 */
final class HttpClient
{
    public const IDEMPOTENCY_KEY_PATTERN = '/^[\x21-\x7E]{1,255}$/D';

    private const RESERVED_HEADERS = ['authorization', 'accept', 'user-agent', 'idempotency-key', 'content-type', 'content-length', 'host'];

    private readonly string $baseUrl;

    private readonly string $token;

    private readonly string $userAgent;

    /** @var array<string, string> */
    private readonly array $headers;

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        string $baseUrl,
        #[\SensitiveParameter] string $token,
        private readonly float $timeout,
        array $headers,
        private readonly Transport $transport,
    ) {
        if (preg_match('#^https?://[^/\s]+#i', $baseUrl) !== 1) {
            throw new InvalidRequestException('baseUrl precisa ser http(s)://…/api/v1 da sua instalação.');
        }

        if ($token === '' || preg_match('/\s/', $token) === 1) {
            throw new InvalidRequestException('token vazio ou com espaço: use o texto exibido na criação da chave.');
        }

        if ($timeout <= 0) {
            throw new InvalidRequestException('timeout precisa ser maior que zero.');
        }

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
        $this->headers = self::checkHeaders($headers);
        $this->userAgent = sprintf('assinavelox-php/%s (api-v%d; php/%s)', Version::SDK, Version::API_MAJOR, PHP_VERSION);
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function userAgent(): string
    {
        return $this->userAgent;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'timeout' => $this->timeout];
    }

    /**
     * @param  array<string, string>  $pathParams
     * @param  array<string, mixed>  $query
     * @param  array<string, array{0: string, 1: bool}>  $querySpec  nome => [nome na URL, repetido?]
     * @param  array<mixed>|object|null  $json
     */
    public function request(
        string $method,
        string $path,
        array $pathParams = [],
        array $query = [],
        array $querySpec = [],
        array|object|null $json = null,
        bool $hasBody = false,
        ?FileUpload $file = null,
        string $fileField = 'file',
        ?string $idempotency = null,
        ?RequestOptions $options = null,
        string $accept = 'application/json',
    ): RawResponse {
        $options ??= new RequestOptions;
        $url = $this->url($path, $pathParams, $query, $querySpec);

        $headers = [];

        foreach ([...$this->headers, ...self::checkHeaders($options->headers)] as $name => $value) {
            if (! in_array(strtolower($name), self::RESERVED_HEADERS, true)) {
                $headers[strtolower($name)] = $name.': '.$value;
            }
        }

        $headers['authorization'] = 'Authorization: Bearer '.$this->token;
        $headers['accept'] = 'Accept: '.$accept;
        $headers['user-agent'] = 'User-Agent: '.$this->userAgent;

        $key = $options->idempotencyKey;

        if ($key === null && $idempotency === 'required') {
            $key = self::uuid4();
        }

        if ($key !== null) {
            if (preg_match(self::IDEMPOTENCY_KEY_PATTERN, $key) !== 1) {
                throw new InvalidRequestException('Idempotency-Key: de 1 a 255 caracteres ASCII visíveis, sem espaços.');
            }

            $headers['idempotency-key'] = 'Idempotency-Key: '.$key;
        }

        $body = null;

        if ($file !== null) {
            [$body, $contentType] = self::multipart($fileField, $file);
            $headers['content-type'] = 'Content-Type: '.$contentType;
        } elseif ($hasBody && $json !== null) {
            $body = self::encodeJson($json);
            $headers['content-type'] = 'Content-Type: application/json';
        }

        $response = $this->transport->send($method, $url, array_values($headers), $body, $options->timeout ?? $this->timeout);

        if ($response->status < 200 || $response->status >= 300) {
            throw ApiException::fromResponse($response->status, $response->headers, $response->body);
        }

        return $response;
    }

    /**
     * @param  array<string, string>  $pathParams
     * @param  array<string, mixed>  $query
     * @param  array<string, array{0: string, 1: bool}>  $querySpec
     */
    private function url(string $path, array $pathParams, array $query, array $querySpec): string
    {
        foreach ($pathParams as $name => $value) {
            if ($value === '') {
                throw new InvalidRequestException(sprintf('Parâmetro "%s" obrigatório (texto não vazio).', $name));
            }

            $path = str_replace('{'.$name.'}', rawurlencode($value), $path);
        }

        $unknown = array_diff(array_keys($query), array_keys($querySpec));

        if ($unknown !== []) {
            throw new InvalidRequestException('Parâmetro de consulta desconhecido: '.implode(', ', $unknown).'.');
        }

        $pairs = [];

        foreach ($querySpec as $name => [$wire, $repeat]) {
            $value = $query[$name] ?? null;

            if ($value === null) {
                continue;
            }

            $values = $repeat && is_array($value) ? array_values($value) : [$value];

            foreach ($values as $item) {
                $pairs[] = rawurlencode($wire).'='.rawurlencode(self::scalar($name, $item));
            }
        }

        return $this->baseUrl.$path.($pairs === [] ? '' : '?'.implode('&', $pairs));
    }

    private static function scalar(string $name, mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value), is_string($value) => (string) $value,
            default => throw new InvalidRequestException(sprintf('Parâmetro de consulta "%s" com tipo não suportado.', $name)),
        };
    }

    /**
     * @param  array<mixed>|object  $json
     */
    private static function encodeJson(array|object $json): string
    {
        if ($json === []) {
            return '{}';
        }

        try {
            return json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidRequestException('Corpo não pôde ser convertido em JSON: '.$exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function multipart(string $field, FileUpload $file): array
    {
        if (preg_match('/[\r\n\0]/', $file->contentType) === 1) {
            throw new InvalidRequestException('contentType do arquivo com quebra de linha.');
        }

        $boundary = 'AssinaVeloxSdk'.bin2hex(random_bytes(16));
        $quote = static fn (string $value): string => str_replace(['"', "\r", "\n"], ['%22', '%0D', '%0A'], $value);

        $body = '--'.$boundary."\r\n"
            .'Content-Disposition: form-data; name="'.$quote($field).'"; filename="'.$quote($file->filename).'"'."\r\n"
            .'Content-Type: '.$file->contentType."\r\n\r\n"
            .$file->content."\r\n"
            .'--'.$boundary."--\r\n";

        return [$body, 'multipart/form-data; boundary='.$boundary];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private static function checkHeaders(array $headers): array
    {
        foreach ($headers as $name => $value) {
            if (preg_match('/[\r\n\0]/', $name.$value) === 1) {
                throw new InvalidRequestException(sprintf('Cabeçalho "%s" com quebra de linha.', $name));
            }
        }

        return $headers;
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
