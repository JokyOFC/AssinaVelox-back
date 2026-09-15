/** Resposta com `data` e `meta` (ex.: `sendEnvelope` traz `meta.invitations_sent`). */
export interface ApiResult<T> {
    data: T;
    meta: Record<string, unknown>;
}

const record = (value: unknown): Record<string, unknown> =>
    value !== null && typeof value === 'object' && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : {};

/**
 * Uma página de resultados paginados por cursor (`meta.next_cursor`).
 *
 * ```ts
 * for await (const envelope of await client.listEnvelopes()) { ... }
 * ```
 *
 * O `for await` percorre TODAS as páginas, buscando as próximas sob demanda;
 * `page.data` tem só os itens desta página. O cursor é opaco.
 */
export class Page<T> implements AsyncIterable<T> {
    readonly #fetch: (cursor: string) => Promise<Page<T>>;

    constructor(
        readonly data: T[],
        readonly links: Record<string, unknown>,
        readonly meta: Record<string, unknown>,
        fetch: (cursor: string) => Promise<Page<T>>,
    ) {
        this.#fetch = fetch;
    }

    get nextCursor(): string | null {
        const value = this.meta.next_cursor;
        return typeof value === 'string' && value !== '' ? value : null;
    }

    get prevCursor(): string | null {
        const value = this.meta.prev_cursor;
        return typeof value === 'string' && value !== '' ? value : null;
    }

    get perPage(): number | null {
        const value = this.meta.per_page;
        return typeof value === 'number' ? value : null;
    }

    get hasMore(): boolean {
        return this.nextCursor !== null;
    }

    async nextPage(): Promise<Page<T> | null> {
        const cursor = this.nextCursor;
        return cursor === null ? null : this.#fetch(cursor);
    }

    /** Todos os itens, desta página em diante. */
    async *autoPagingIterator(): AsyncGenerator<T, void, undefined> {
        yield* this.data;
        let page = await this.nextPage();
        while (page !== null) {
            yield* page.data;
            page = await page.nextPage();
        }
    }

    [Symbol.asyncIterator](): AsyncGenerator<T, void, undefined> {
        return this.autoPagingIterator();
    }

    static fromJson<T>(
        body: unknown,
        fetch: (cursor: string) => Promise<Page<T>>,
    ): Page<T> {
        const json = record(body);
        const data = Array.isArray(json.data) ? (json.data as T[]) : [];
        return new Page<T>(data, record(json.links), record(json.meta), fetch);
    }
}
