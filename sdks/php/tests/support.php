<?php

declare(strict_types=1);

/*
 * Mini harness dos testes do SDK PHP (sem PHPUnit, para o SDK não ter dependências).
 * Os testes rodam duas vezes: com CurlTransport e com StreamTransport.
 */

use AssinaVelox\Sdk\Client;
use AssinaVelox\Sdk\Http\CurlTransport;
use AssinaVelox\Sdk\Http\StreamTransport;
use AssinaVelox\Sdk\Http\Transport;
use AssinaVelox\Sdk\RequestOptions;

final class AssertionFailed extends RuntimeException {}

final class SdkTest
{
    /** @var array<string, Closure(TestContext): void> */
    public static array $tests = [];

    public static function add(string $name, Closure $test): void
    {
        self::$tests[$name] = $test;
    }
}

final class TestContext
{
    public const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function __construct(public readonly string $url, public readonly string $transport) {}

    public function transport(): Transport
    {
        return $this->transport === 'curl' ? new CurlTransport : new StreamTransport;
    }

    public function client(string $token = 'tok_teste_sdk', float $timeout = 30.0, ?string $url = null): Client
    {
        return new Client($url ?? $this->url, $token, $timeout, [], $this->transport());
    }

    /**
     * @return array{0: RequestOptions, 1: string}
     */
    public function trace(?string $idempotencyKey = null): array
    {
        $trace = bin2hex(random_bytes(12));

        return [new RequestOptions(idempotencyKey: $idempotencyKey, headers: ['X-Fake-Trace' => $trace]), $trace];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function records(string $trace): array
    {
        $origin = substr($this->url, 0, (int) strpos($this->url, '/api/v1'));
        $body = file_get_contents($origin.'/__fake/requests?trace='.$trace, false, stream_context_create(['http' => ['timeout' => 10]]));

        return json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public function last(string $trace): array
    {
        $records = $this->records($trace);
        $this->true($records !== [], 'o servidor falso não recebeu o pedido');

        return $records[count($records) - 1];
    }

    public function checkTrace(string $trace, string $operation, ?string $idempotency): void
    {
        $records = $this->records($trace);
        $this->true($records !== [], 'o servidor falso não recebeu o pedido');
        $this->same($operation, $records[0]['operation']);
        $this->same([], $records[0]['violations']);
        $key = $records[0]['headers']['idempotency-key'] ?? null;

        if ($idempotency === 'required') {
            $this->matches(self::UUID_V4, (string) $key);
        } elseif ($idempotency === 'optional') {
            $this->same(null, $key);
        }
    }

    public function same(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailed(trim($message.' esperado '.var_export($expected, true).', obtido '.var_export($actual, true)));
        }
    }

    public function true(bool $condition, string $message = 'condição falsa'): void
    {
        if (! $condition) {
            throw new AssertionFailed($message);
        }
    }

    public function false(bool $condition, string $message = 'condição verdadeira'): void
    {
        $this->true(! $condition, $message);
    }

    public function instanceOf(string $class, mixed $value): void
    {
        $this->true($value instanceof $class, 'esperado '.$class.', obtido '.get_debug_type($value));
    }

    public function matches(string $pattern, string $value): void
    {
        $this->true(preg_match($pattern, $value) === 1, sprintf('"%s" não casa com %s', $value, $pattern));
    }

    /**
     * @template E of \Throwable
     *
     * @param  class-string<E>  $class
     * @return E
     */
    public function throws(string $class, Closure $callback): Throwable
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            if ($exception instanceof $class) {
                return $exception;
            }

            throw new AssertionFailed('esperado '.$class.', lançado '.get_class($exception).': '.$exception->getMessage());
        }

        throw new AssertionFailed('esperado '.$class.', nada foi lançado');
    }
}
