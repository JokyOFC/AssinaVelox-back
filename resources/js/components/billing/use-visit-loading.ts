import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * `true` enquanto uma navegação GET para `pathnamePrefix` está em voo — é o
 * estado de carregamento da tabela de pagamentos ao trocar de página, já que
 * a paginação é uma visita do Inertia e não um fetch próprio da tela.
 */
export function useVisitLoading(pathnamePrefix: string): boolean {
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        const stopStart = router.on('start', (event) => {
            const visit = event.detail.visit;

            if (
                visit.method === 'get' &&
                visit.url.pathname.startsWith(pathnamePrefix)
            ) {
                setLoading(true);
            }
        });

        const stopFinish = router.on('finish', () => setLoading(false));

        return () => {
            stopStart();
            stopFinish();
        };
    }, [pathnamePrefix]);

    return loading;
}
