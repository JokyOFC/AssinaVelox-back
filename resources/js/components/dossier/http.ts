/**
 * Consulta do estado do dossiê (`GET dossiers.show`) — JSON de polling, fora do Inertia.
 * Rede indisponível ou tempo esgotado viram `network: true`, nunca "pronto" (regra T5).
 */
export async function getJson<T>(
    url: string,
    { timeoutMs = 20000 }: { timeoutMs?: number } = {},
): Promise<{ ok: boolean; status: number; body: T | null; network: boolean }> {
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), timeoutMs);

    try {
        const response = await fetch(url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller.signal,
        });

        let body: T | null = null;

        try {
            body = (await response.json()) as T;
        } catch {
            body = null;
        }

        return {
            ok: response.ok,
            status: response.status,
            body,
            network: body === null && !response.ok,
        };
    } catch {
        return { ok: false, status: 0, body: null, network: true };
    } finally {
        window.clearTimeout(timer);
    }
}
