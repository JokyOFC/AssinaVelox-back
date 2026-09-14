import { xsrfToken } from '@/components/identity/http';

/**
 * Pedidos JSON das assinaturas externas na página pública (`sign.external.*`,
 * `sign.govbr.*`).
 *
 * Fora do roteador do Inertia de propósito: a resposta é JSON, e uma visita Inertia
 * remontaria a página pública (o signatário perderia o que já preencheu).
 *
 * Regra T5: tempo esgotado e falha de rede viram `network: true` — nunca sucesso. Depois de
 * um envio sem resposta, a tela consulta o estado de novo em vez de supor o resultado.
 *
 * Segredos: o corpo pode levar o digest e a assinatura, que só existem na memória do cartão;
 * nada disto é registrado em console, armazenamento local ou URL.
 */
export interface SignerResponse<T> {
    ok: boolean;
    status: number;
    body: T | null;
    network: boolean;
    retryAfter: number | null;
}

export async function signerRequest<T>(
    method: 'GET' | 'POST',
    url: string,
    body?: FormData | Record<string, unknown>,
    { timeoutMs = 45000 }: { timeoutMs?: number } = {},
): Promise<SignerResponse<T>> {
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), timeoutMs);
    const isForm = body instanceof FormData;

    const init: RequestInit = {
        method,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(method === 'POST' ? { 'X-XSRF-TOKEN': xsrfToken() } : {}),
            ...(method === 'POST' && !isForm
                ? { 'Content-Type': 'application/json' }
                : {}),
        },
        credentials: 'same-origin',
        // Nunca em cache: o estado muda a cada passo e a resposta é pessoal.
        cache: 'no-store',
        signal: controller.signal,
    };

    if (method === 'POST') {
        init.body = isForm ? body : JSON.stringify(body ?? {});
    }

    try {
        const response = await fetch(url, init);
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
            network: parsed === null && !response.ok && response.status !== 404,
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
