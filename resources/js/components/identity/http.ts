/**
 * `fetch` com a sessão e o token XSRF do cookie (mesmo padrão de `confirms-password.tsx`).
 * Usado onde a resposta é JSON e não deve virar navegação Inertia: consulta de CNPJ e
 * envio de foto da captura simples.
 *
 * Tempo esgotado e falha de rede viram `network: true` — nunca sucesso (regra T5).
 */

export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export interface JsonResponse<T> {
    ok: boolean;
    status: number;
    body: T | null;
    /** Rede indisponível, tempo esgotado ou resposta que não é JSON. */
    network: boolean;
    retryAfter: number | null;
}

export function postJson<T>(
    url: string,
    body: FormData | Record<string, unknown>,
    options: { timeoutMs?: number } = {},
): Promise<JsonResponse<T>> {
    return requestJson<T>('POST', url, body, options);
}

/**
 * Pedido JSON fora do roteador do Inertia. Um `router.put` aqui cancelaria a gravação
 * automática do wizard que estivesse no ar (o Inertia mantém uma visita síncrona por vez).
 */
export async function requestJson<T>(
    method: 'POST' | 'PUT',
    url: string,
    body: FormData | Record<string, unknown>,
    { timeoutMs = 20000 }: { timeoutMs?: number } = {},
): Promise<JsonResponse<T>> {
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), timeoutMs);
    const isForm = body instanceof FormData;

    try {
        const response = await fetch(url, {
            method,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
                ...(isForm ? {} : { 'Content-Type': 'application/json' }),
            },
            credentials: 'same-origin',
            body: isForm ? body : JSON.stringify(body),
            signal: controller.signal,
        });

        const retry = Number(response.headers.get('Retry-After'));
        let parsed: T | null = null;

        try {
            parsed = (await response.json()) as T;
        } catch {
            parsed = null;
        }

        return {
            ok: response.ok,
            status: response.status,
            body: parsed,
            network: parsed === null && !response.ok,
            retryAfter: Number.isFinite(retry) && retry > 0 ? retry : null,
        };
    } catch {
        return {
            ok: false,
            status: 0,
            body: null,
            network: true,
            retryAfter: null,
        };
    } finally {
        window.clearTimeout(timer);
    }
}
