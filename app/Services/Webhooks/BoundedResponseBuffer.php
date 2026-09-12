<?php

namespace App\Services\Webhooks;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Destino (`sink`) do corpo da resposta de um webhook: guarda só os primeiros N bytes e
 * descarta o resto sem acumular — um receptor que responde gigabytes não enche memória nem
 * disco (o tempo total continua limitado pelo `timeout`).
 *
 * Existe porque a alternativa óbvia, `stream => true`, faz o Guzzle trocar o handler cURL pelo
 * StreamHandler — e o StreamHandler não aplica CURLOPT_RESOLVE (no Guzzle 8.2 ele recusa a
 * opção `curl`; em versões anteriores a ignoraria em silêncio, desligando o pino de IP).
 * Achado do teste de integração do risco R6 (docs/fase-2/webhooks.md §6.4).
 */
final class BoundedResponseBuffer implements StreamInterface
{
    private string $buffer = '';

    private int $position = 0;

    private int $discarded = 0;

    public function __construct(private readonly int $maxBytes) {}

    public function __toString(): string
    {
        return $this->buffer;
    }

    public function close(): void
    {
        $this->buffer = '';
        $this->position = 0;
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    public function getSize(): int
    {
        return strlen($this->buffer);
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->position >= strlen($this->buffer);
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => strlen($this->buffer) + $offset,
            default => throw new RuntimeException('whence inválido'),
        };

        if ($target < 0) {
            throw new RuntimeException('posição negativa');
        }

        $this->position = $target;
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function isWritable(): bool
    {
        return true;
    }

    /**
     * Aceita tudo (devolve o tamanho recebido, para o cURL não abortar a transferência), mas
     * só guarda até o limite.
     */
    public function write(string $string): int
    {
        $room = $this->maxBytes - strlen($this->buffer);

        if ($room > 0) {
            $this->buffer .= substr($string, 0, $room);
        }

        $this->discarded += max(0, strlen($string) - max(0, $room));

        return strlen($string);
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        $chunk = substr($this->buffer, $this->position, max(0, $length));
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        $rest = substr($this->buffer, $this->position);
        $this->position = strlen($this->buffer);

        return $rest;
    }

    public function getMetadata(?string $key = null)
    {
        $metadata = ['bounded' => true, 'max_bytes' => $this->maxBytes, 'discarded_bytes' => $this->discarded];

        return $key === null ? $metadata : ($metadata[$key] ?? null);
    }
}
