import { router } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { getJson } from '@/components/dossier/http';
import { show as flowShow } from '@/routes/envelopes/flow';
import type { EnvelopeFlowState } from './types';

/**
 * Estado do fluxo (etapas + delegação) de um envelope — `GET envelopes.flow.show`.
 *
 * 404 (flags `conditional_steps` e `delegation` desligadas, nada gravado) ou 403 = `hidden`:
 * o componente não aparece e a página é a de antes. Recarrega depois de cada visita Inertia
 * bem-sucedida da página indicada (confirmar/recusar voltam com flash por `back()`).
 */
export function useEnvelopeFlow(envelopeId: string, pageComponent: string) {
    const [state, setState] = useState<EnvelopeFlowState | null>(null);
    const [status, setStatus] = useState<'loading' | 'ready' | 'hidden'>(
        'loading',
    );

    const reload = useCallback(async () => {
        const response = await getJson<EnvelopeFlowState>(
            flowShow(envelopeId).url,
        );

        if (response.ok && response.body) {
            setState(response.body);
            setStatus('ready');

            return;
        }

        if (!response.network) {
            setStatus('hidden');
        }
    }, [envelopeId]);

    useEffect(() => {
        void reload();
    }, [reload]);

    useEffect(
        () =>
            router.on('success', (event) => {
                if (event.detail.page.component === pageComponent) {
                    void reload();
                }
            }),
        [reload, pageComponent],
    );

    return { state, setState, status, reload };
}
