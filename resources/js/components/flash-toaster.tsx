import { useFlash } from '@/hooks/use-flash';

/** Componente sem UI que converte `flash.*` em toasts (sonner). */
export function FlashToaster() {
    useFlash();

    return null;
}
