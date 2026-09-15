import { randomUUID } from 'node:crypto';

import {
    ApiError,
    InvalidRequestError,
    NetworkError,
    RequestTimeoutError,
} from './errors.js';
import { toBytes, type FileUpload } from './files.js';
import { API_MAJOR, SDK_VERSION } from './version.js';

/** Opções do cliente. */
export interface ClientOptions {
    /** Endereço da sua instalação, terminando em `/api/v1`. */
    baseUrl: string;
    /** Texto da chave criada em Integrações → Chaves (exibido uma única vez). */
    token: string;
    /** Tempo máximo de cada requisição, em milissegundos (padrão: 30000). */
    timeoutMs?: number;
    /** Cabeçalhos extras em todas as requisições. */
    headers?: Record<string, string>;
    /** `fetch` alternativo (testes, proxies). Padrão: o `fetch` global do Node 18+. */
    fetch?: typeof fetch;
}

/** Opções de uma chamada. */
export interface RequestOptions {
    /**
     * 1 a 255 caracteres ASCII visíveis. Nas criações e no envio, sem chave o
     * SDK gera um UUID v4.
     */
    idempotencyKey?: string;
    /** Milissegundos; ausente usa o do cliente. */
    timeoutMs?: number;
    /** Cabeçalhos extras (não substituem Authorization, Accept nem User-Agent). */
    headers?: Record<string, string>;
    /** Cancela a requisição. */
    signal?: AbortSignal;
}

export interface RawResponse {
    status: number;
    headers: Record<string, string>;
    body: Uint8Array;
}

export interface RequestSpec {
    method: string;
    path: string;
    pathParams?: Record<string, string>;
    query?: object;
    querySpec?: Record<string, readonly [string, boolean]>;
    json?: unknown;
    hasBody?: boolean;
    file?: FileUpload;
    fileField?: string;
    idempotency?: 'required' | 'optional';
    options?: RequestOptions;
    accept?: string;
}

const IDEMPOTENCY_KEY = /^[\x21-\x7e]{1,255}$/;
const UNSAFE = /[\r\n\0]/;
const RESERVED = new Set([
    'authorization',
    'accept',
    'user-agent',
    'idempotency-key',
    'content-type',
    'content-length',
    'host',
]);

function checkHeaders(
    headers: Record<string, string> | undefined,
): Record<string, string> {
    const result: Record<string, string> = {};
    for (const [name, value] of Object.entries(headers ?? {})) {
        if (UNSAFE.test(name) || UNSAFE.test(String(value))) {
            throw new InvalidRequestError(
                `Cabeçalho "${name}" com quebra de linha.`,
            );
        }
        result[name] = String(value);
    }
    return result;
}

function scalar(name: string, value: unknown): string {
    if (typeof value === 'boolean') {
        return value ? 'true' : 'false';
    }
    if (typeof value === 'number' || typeof value === 'string') {
        return String(value);
    }
    throw new InvalidRequestError(
        `Parâmetro de consulta "${name}" com tipo não suportado.`,
    );
}

function concat(parts: Uint8Array[]): Uint8Array {
    const total = parts.reduce((sum, part) => sum + part.byteLength, 0);
    const result = new Uint8Array(total);
    let offset = 0;
    for (const part of parts) {
        result.set(part, offset);
        offset += part.byteLength;
    }
    return result;
}

function multipart(
    field: string,
    file: FileUpload,
): { body: Uint8Array; contentType: string } {
    const contentType = file.contentType ?? 'application/octet-stream';
    if (UNSAFE.test(contentType)) {
        throw new InvalidRequestError(
            'contentType do arquivo com quebra de linha.',
        );
    }
    const boundary = `AssinaVeloxSdk${randomUUID().replaceAll('-', '')}`;
    const quote = (value: string): string =>
        value
            .replaceAll('"', '%22')
            .replaceAll('\r', '%0D')
            .replaceAll('\n', '%0A');
    const encoder = new TextEncoder();
    const head =
        `--${boundary}\r\n` +
        `Content-Disposition: form-data; name="${quote(field)}"; filename="${quote(file.filename)}"\r\n` +
        `Content-Type: ${contentType}\r\n\r\n`;
    return {
        body: concat([
            encoder.encode(head),
            toBytes(file.content),
            encoder.encode(`\r\n--${boundary}--\r\n`),
        ]),
        contentType: `multipart/form-data; boundary=${boundary}`,
    };
}

/**
 * Núcleo HTTP do SDK (uso interno). O token fica num campo privado (#): não
 * aparece em `console.log`, `util.inspect` nem em mensagens de erro.
 * Redirecionamentos não são seguidos.
 */
export class HttpClient {
    readonly baseUrl: string;
    readonly timeoutMs: number;
    readonly userAgent: string;
    readonly #token: string;
    readonly #headers: Record<string, string>;
    readonly #fetch: typeof fetch;

    constructor(options: ClientOptions) {
        if (
            typeof options.baseUrl !== 'string' ||
            !/^https?:\/\/[^/\s]+/i.test(options.baseUrl)
        ) {
            throw new InvalidRequestError(
                'baseUrl precisa ser http(s)://…/api/v1 da sua instalação.',
            );
        }
        // Só caracteres visíveis de um byte (a regra de valor de cabeçalho HTTP): NUL, controle,
        // espaço ou acima de 0xFF fariam o `fetch` recusar o cabeçalho com uma mensagem que
        // repete o valor — e o token iria para a exceção.
        if (
            typeof options.token !== 'string' ||
            options.token === '' ||
            /[^\x21-\x7e\x80-\xff]/.test(options.token)
        ) {
            throw new InvalidRequestError(
                'token vazio ou com espaço: use o texto exibido na criação da chave.',
            );
        }
        const timeoutMs = options.timeoutMs ?? 30_000;
        if (!(timeoutMs > 0)) {
            throw new InvalidRequestError(
                'timeoutMs precisa ser maior que zero.',
            );
        }
        const fetchImpl = options.fetch ?? globalThis.fetch;
        if (typeof fetchImpl !== 'function') {
            throw new InvalidRequestError(
                'fetch indisponível: use Node.js 18 ou mais novo.',
            );
        }
        this.baseUrl = options.baseUrl.replace(/\/+$/, '');
        this.timeoutMs = timeoutMs;
        this.#token = options.token;
        this.#headers = checkHeaders(options.headers);
        this.#fetch = fetchImpl;
        this.userAgent = `assinavelox-node/${SDK_VERSION} (api-v${API_MAJOR}; node/${process.version})`;
    }

    url(
        path: string,
        pathParams: Record<string, string>,
        query: object,
        querySpec: Record<string, readonly [string, boolean]>,
    ): string {
        let resolved = path;
        for (const [name, value] of Object.entries(pathParams)) {
            if (typeof value !== 'string' || value === '') {
                throw new InvalidRequestError(
                    `Parâmetro "${name}" obrigatório (texto não vazio).`,
                );
            }
            resolved = resolved.replace(`{${name}}`, encodeURIComponent(value));
        }
        const values = query as Record<string, unknown>;
        const unknown = Object.keys(values).filter(
            (name) => !(name in querySpec),
        );
        if (unknown.length > 0) {
            throw new InvalidRequestError(
                `Parâmetro de consulta desconhecido: ${unknown.join(', ')}.`,
            );
        }
        const pairs: string[] = [];
        for (const [name, [wire, repeat]] of Object.entries(querySpec)) {
            const value = values[name];
            if (value === undefined || value === null) {
                continue;
            }
            const items = repeat && Array.isArray(value) ? value : [value];
            for (const item of items) {
                pairs.push(
                    `${encodeURIComponent(wire)}=${encodeURIComponent(scalar(name, item))}`,
                );
            }
        }
        return (
            this.baseUrl +
            resolved +
            (pairs.length > 0 ? `?${pairs.join('&')}` : '')
        );
    }

    async request(spec: RequestSpec): Promise<RawResponse> {
        const options = spec.options ?? {};
        const url = this.url(
            spec.path,
            spec.pathParams ?? {},
            spec.query ?? {},
            spec.querySpec ?? {},
        );

        const headers: Record<string, string> = {};
        for (const [name, value] of Object.entries({
            ...this.#headers,
            ...checkHeaders(options.headers),
        })) {
            if (!RESERVED.has(name.toLowerCase())) {
                headers[name.toLowerCase()] = value;
            }
        }
        headers.authorization = `Bearer ${this.#token}`;
        headers.accept = spec.accept ?? 'application/json';
        headers['user-agent'] = this.userAgent;

        let key = options.idempotencyKey;
        if (key === undefined && spec.idempotency === 'required') {
            key = randomUUID();
        }
        if (key !== undefined) {
            if (!IDEMPOTENCY_KEY.test(key)) {
                throw new InvalidRequestError(
                    'Idempotency-Key: de 1 a 255 caracteres ASCII visíveis, sem espaços.',
                );
            }
            headers['idempotency-key'] = key;
        }

        let body: Uint8Array | undefined;
        if (spec.file !== undefined) {
            const encoded = multipart(spec.fileField ?? 'file', spec.file);
            body = encoded.body;
            headers['content-type'] = encoded.contentType;
        } else if (
            spec.hasBody === true &&
            spec.json !== undefined &&
            spec.json !== null
        ) {
            body = new TextEncoder().encode(JSON.stringify(spec.json));
            headers['content-type'] = 'application/json';
        }

        const limit = options.timeoutMs ?? this.timeoutMs;
        const controller = new AbortController();
        let timedOut = false;
        const timer = setTimeout(() => {
            timedOut = true;
            controller.abort();
        }, limit);
        const external = options.signal;
        const relay = (): void => controller.abort(external?.reason);
        if (external?.aborted === true) {
            controller.abort(external.reason);
        } else {
            external?.addEventListener('abort', relay, { once: true });
        }

        const where = `${spec.method} ${spec.path}`;
        try {
            const response = await this.#fetch(url, {
                method: spec.method,
                headers,
                body,
                redirect: 'manual',
                signal: controller.signal,
            });
            const payload = new Uint8Array(await response.arrayBuffer());
            const responseHeaders: Record<string, string> = {};
            response.headers.forEach((value, name) => {
                responseHeaders[name.toLowerCase()] = value;
            });
            if (response.status < 200 || response.status >= 300) {
                throw ApiError.fromResponse(
                    response.status,
                    responseHeaders,
                    payload,
                );
            }
            return {
                status: response.status,
                headers: responseHeaders,
                body: payload,
            };
        } catch (error) {
            if (error instanceof ApiError) {
                throw error;
            }
            if (timedOut) {
                throw new RequestTimeoutError(
                    `Tempo esgotado (${limit} ms) em ${where}.`,
                );
            }
            if (external?.aborted === true) {
                throw error;
            }
            const cause = (
                error as { cause?: { code?: string; message?: string } }
            ).cause;
            let reason = String(
                cause?.code ?? cause?.message ?? (error as Error).message,
            );
            // Nunca repetir um cabeçalho recusado (o valor seria "Bearer <token>") nem o token:
            // nesses casos a mensagem é genérica e o erro original NÃO vai como `cause`.
            const unsafe =
                (error instanceof TypeError && /header/i.test(reason)) ||
                reason.includes(this.#token);
            if (unsafe) {
                reason = 'cabeçalho inválido';
            }
            throw new NetworkError(
                `Falha de conexão em ${where}: ${reason}`,
                unsafe ? undefined : { cause: error },
            );
        } finally {
            clearTimeout(timer);
            external?.removeEventListener('abort', relay);
        }
    }
}

export function jsonOf(response: RawResponse): unknown {
    if (response.body.byteLength === 0) {
        return null;
    }
    return JSON.parse(new TextDecoder().decode(response.body)) as unknown;
}

export function dataOf(response: RawResponse): unknown {
    const json = jsonOf(response);
    return json !== null && typeof json === 'object'
        ? (json as Record<string, unknown>).data
        : undefined;
}

export function metaOf(response: RawResponse): Record<string, unknown> {
    const json = jsonOf(response);
    const meta =
        json !== null && typeof json === 'object'
            ? (json as Record<string, unknown>).meta
            : undefined;
    return meta !== null && typeof meta === 'object' && !Array.isArray(meta)
        ? (meta as Record<string, unknown>)
        : {};
}
