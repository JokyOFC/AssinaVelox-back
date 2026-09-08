import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';
import type { FlashToast } from '@/types';

type ToastType = 'success' | 'error' | 'warning' | 'info';

const TYPES: ToastType[] = ['success', 'error', 'warning', 'info'];

/**
 * Exibe toasts a partir de `flash.{success,error,warning,info}` (prop
 * compartilhada pelo backend) e do evento `flash` do Inertia 3
 * (`Inertia::flash(['toast' => ...])`).
 */
export function useFlash(): void {
    const { flash } = usePage().props;
    const lastKey = useRef<string>('');

    useEffect(() => {
        if (!flash) {
            return;
        }

        const key = JSON.stringify(flash);

        if (key === lastKey.current) {
            return;
        }

        lastKey.current = key;

        TYPES.forEach((type) => {
            const message = flash[type];

            if (message) {
                toast[type](message);
            }
        });
    }, [flash]);

    useEffect(() => {
        return router.on('flash', (event) => {
            const detail = (event as CustomEvent).detail?.flash as
                | { toast?: FlashToast }
                | undefined;
            const data = detail?.toast;

            if (!data) {
                return;
            }

            toast[data.type](data.message);
        });
    }, []);
}
