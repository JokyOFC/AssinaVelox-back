import { useCallback, useEffect, useRef, useState } from 'react';
import { postJson } from '@/components/identity/http';
import type { DossierExport } from '@/types/signatures';
import { getJson } from './http';

/** Intervalo do polling (contrato: 2–3 s) e teto de tentativas (~20 min). */
const POLL_MS = 2500;
const MAX_POLLS = 480;

type DossierResponse = { export?: DossierExport; message?: string };

/**
 * Pedido + polling de um dossiê ZIP (docs/fase-2/carimbo-e-dossie.md §5.2 e §7).
 *
 * O pedido é idempotente no servidor: pedir de novo o mesmo documento devolve o mesmo
 * dossiê (com link novo), então fechar a janela no meio da preparação e reabrir não gera
 * outro pacote. Só o `download_url` recebido é usado — nunca uma URL montada no cliente.
 */
export function useDossierExport() {
    const [current, setCurrent] = useState<DossierExport | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [requesting, setRequesting] = useState(false);
    const [stalled, setStalled] = useState(false);
    const polls = useRef(0);
    const lastRequest = useRef<{
        url: string;
        body: Record<string, unknown>;
    } | null>(null);

    const request = useCallback(
        async (url: string, body: Record<string, unknown> = {}) => {
            lastRequest.current = { url, body };
            polls.current = 0;
            setStalled(false);
            setError(null);
            setRequesting(true);

            const response = await postJson<DossierResponse>(url, body, {
                timeoutMs: 30000,
            });

            setRequesting(false);

            if (response.ok && response.body?.export) {
                setCurrent(response.body.export);

                return;
            }

            setCurrent(null);
            setError(
                response.network
                    ? 'Não recebemos a resposta do servidor. Confira a conexão e tente de novo.'
                    : response.status === 404
                      ? 'O dossiê não está disponível para este documento.'
                      : response.status === 403
                        ? 'Você não tem permissão para baixar este documento.'
                        : response.status === 429
                          ? `Muitos pedidos seguidos. Aguarde ${response.retryAfter ?? 60} segundos e tente de novo.`
                          : (response.body?.message ??
                            'Não foi possível pedir o dossiê. Tente de novo.'),
            );
        },
        [],
    );

    const retry = useCallback(() => {
        if (lastRequest.current) {
            void request(lastRequest.current.url, lastRequest.current.body);
        }
    }, [request]);

    const reset = useCallback(() => {
        lastRequest.current = null;
        polls.current = 0;
        setCurrent(null);
        setError(null);
        setStalled(false);
    }, []);

    const status = current?.status;
    const statusUrl = current?.status_url;

    useEffect(() => {
        if (!statusUrl || (status !== 'pending' && status !== 'building')) {
            return;
        }

        if (polls.current >= MAX_POLLS) {
            setStalled(true);

            return;
        }

        let cancelled = false;
        const timer = window.setTimeout(async () => {
            polls.current += 1;
            const response = await getJson<DossierResponse>(statusUrl);

            if (cancelled) {
                return;
            }

            if (response.ok && response.body?.export) {
                setCurrent(response.body.export);
            } else if (response.status === 404) {
                setCurrent(null);
                setError('O pedido de dossiê não foi encontrado.');
            } else {
                // Rede instável: tenta de novo no próximo ciclo, sem supor que ficou pronto.
                setCurrent((value) => (value ? { ...value } : value));
            }
        }, POLL_MS);

        return () => {
            cancelled = true;
            window.clearTimeout(timer);
        };
    }, [status, statusUrl, current]);

    return { current, error, requesting, stalled, request, retry, reset };
}
