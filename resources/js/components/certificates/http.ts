import { xsrfToken } from '@/components/identity/http';

/**
 * Pedidos JSON da assinatura com o certificado do participante (`sign.certificate.*`).
 *
 * Fora do roteador do Inertia de propósito: a resposta é JSON, e uma visita Inertia
 * remontaria a página pública (o signatário perderia o que já preencheu).
 *
 * Regra T5: tempo esgotado e falha de rede viram `network: true` — nunca sucesso. Depois
 * de um envio sem resposta, a tela consulta o estado de novo em vez de supor o resultado.
 */
export interface CertificateResponse<T> {
    ok: boolean;
    status: number;
    body: T | null;
    network: boolean;
    retryAfter: number | null;
}

export async function certificateRequest<T>(
    method: 'GET' | 'POST',
    url: string,
    body?: FormData,
    { timeoutMs = 45000 }: { timeoutMs?: number } = {},
): Promise<CertificateResponse<T>> {
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), timeoutMs);

    const init: RequestInit = {
        method,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(method === 'POST' ? { 'X-XSRF-TOKEN': xsrfToken() } : {}),
        },
        credentials: 'same-origin',
        // Nunca em cache: o estado muda a cada passo e a resposta é pessoal.
        cache: 'no-store',
        signal: controller.signal,
    };

    if (method === 'POST') {
        init.body = body ?? new FormData();
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
