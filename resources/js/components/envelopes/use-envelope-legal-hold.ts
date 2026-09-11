import { router } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { getJson } from '@/components/dossier/http';
import type { EnvelopeLegalHold } from '@/components/retention/types';
import { show as legalHoldShow } from '@/routes/envelopes/legal_hold';

/**
 * Preservação do documento no detalhe (Fase 2 §2.19 — docs/fase-2/retencao-e-preservacao.md
 * §10). Usa a prop `legal_hold` quando o controller a enviar; senão consulta
 * `GET envelopes.legal_hold.show` (JSON, só exige `view`). Recarrega depois de cada visita
 * Inertia bem-sucedida desta página — preservar e liberar voltam por `back()` com flash.
 *
 * Falha na consulta = `null` = nada é mostrado (a exclusão continua barrada no servidor).
 */
export function useEnvelopeLegalHold(
    envelopeId: string,
    initial: EnvelopeLegalHold | null = null,
): EnvelopeLegalHold | null {
    const [data, setData] = useState<EnvelopeLegalHold | null>(initial);

    const load = useCallback(async () => {
        const response = await getJson<EnvelopeLegalHold>(
            legalHoldShow(envelopeId).url,
        );

        if (response.ok && response.body) {
            setData(response.body);
        }
    }, [envelopeId]);

    useEffect(() => {
        if (initial !== null) {
            setData(initial);

            return;
        }

        void load();
    }, [initial, load]);

    useEffect(
        () =>
            router.on('success', (event) => {
                if (event.detail.page.component === 'envelopes/show') {
                    void load();
                }
            }),
        [load],
    );

    return data;
}
