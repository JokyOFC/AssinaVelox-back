<?php

declare(strict_types=1);

namespace AssinaVelox\Sdk;

/**
 * Uma página de resultados paginados por cursor (`meta.next_cursor`).
 *
 *     foreach ($client->listEnvelopes()->autoPagingIterator() as $envelope) { ... }
 *
 * @template T
 *
 * @implements \IteratorAggregate<int, T>
 */
final class Page implements \Countable, \IteratorAggregate
{
    /**
     * @param  list<T>  $data
     * @param  array<string, mixed>  $links
     * @param  array<string, mixed>  $meta
     * @param  \Closure(string): Page<T>  $fetch
     */
    public function __construct(
        public readonly array $data,
        public readonly array $links,
        public readonly array $meta,
        private readonly \Closure $fetch,
    ) {}

    public function nextCursor(): ?string
    {
        $value = $this->meta['next_cursor'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function prevCursor(): ?string
    {
        $value = $this->meta['prev_cursor'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function perPage(): ?int
    {
        $value = $this->meta['per_page'] ?? null;

        return is_int($value) ? $value : null;
    }

    public function hasMore(): bool
    {
        return $this->nextCursor() !== null;
    }

    /**
     * @return Page<T>|null
     */
    public function nextPage(): ?self
    {
        $cursor = $this->nextCursor();

        return $cursor === null ? null : ($this->fetch)($cursor);
    }

    /**
     * Todos os itens, desta página em diante, buscando as próximas sob demanda.
     *
     * @return \Generator<int, T>
     */
    public function autoPagingIterator(): \Generator
    {
        $page = $this;

        while ($page !== null) {
            foreach ($page->data as $item) {
                yield $item;
            }

            $page = $page->nextPage();
        }
    }

    /**
     * @return \ArrayIterator<int, T>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }

    public function count(): int
    {
        return count($this->data);
    }

    /**
     * @template TItem
     *
     * @param  \Closure(array<string, mixed>): TItem|\Closure(mixed): TItem  $convert
     * @param  \Closure(string): Page<TItem>  $fetch
     * @return Page<TItem>
     */
    public static function fromResponse(mixed $body, \Closure $convert, \Closure $fetch): self
    {
        $body = is_array($body) ? $body : [];
        $items = is_array($body['data'] ?? null) ? $body['data'] : [];
        $data = [];

        foreach ($items as $item) {
            $data[] = $convert(is_array($item) ? $item : []);
        }

        return new self(
            $data,
            is_array($body['links'] ?? null) ? $body['links'] : [],
            is_array($body['meta'] ?? null) ? $body['meta'] : [],
            $fetch,
        );
    }
}
