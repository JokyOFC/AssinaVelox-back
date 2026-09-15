import {
    requestJson,
    xsrfToken,
    type JsonResponse,
} from '@/components/identity/http';

export { requestJson };
export type { JsonResponse };

/**
 * `GET` JSON com a sessão, fora do roteador do Inertia (mesmo padrão de
 * `components/identity/http.ts`): um `router.get` aqui cancelaria a gravação
 * automática do wizard. Rede indisponível vira `network: true`, nunca sucesso.
 */
export async function getJson<T>(
    url: string,
    { timeoutMs = 20000 }: { timeoutMs?: number } = {},
): Promise<JsonResponse<T>> {
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), timeoutMs);

    try {
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            credentials: 'same-origin',
            signal: controller.signal,
        });

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
            retryAfter: null,
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

/** Primeira mensagem de erro de uma resposta 4xx do Laravel (texto puro). */
export function firstError(
    response: JsonResponse<unknown>,
    fallback = 'Não foi possível concluir. Tente de novo.',
): string {
    if (response.network) {
        return 'Sem conexão com o servidor. Tente de novo.';
    }

    if (response.status === 429) {
        return 'Muitas tentativas seguidas. Aguarde um instante.';
    }

    const body = response.body as {
        message?: unknown;
        errors?: Record<string, unknown>;
    } | null;

    if (body?.errors) {
        for (const value of Object.values(body.errors)) {
            const message = Array.isArray(value) ? value[0] : value;

            if (typeof message === 'string' && message !== '') {
                return message;
            }
        }
    }

    return typeof body?.message === 'string' && body.message !== ''
        ? body.message
        : fallback;
}
