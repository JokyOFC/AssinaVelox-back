import { useCallback, useEffect, useRef, useState } from 'react';

export type AutosaveStatus = 'idle' | 'pending' | 'saving' | 'saved' | 'error';

/** Chamada que executa o envio; avisa o fim por `done(ok)`. */
export type AutosaveRun = (done: (ok: boolean) => void) => void;

export interface Autosave {
    status: AutosaveStatus;
    /** Momento do último salvamento bem-sucedido nesta sessão de edição. */
    savedAt: Date | null;
    /** Agenda um envio; nova chamada com a mesma chave substitui a anterior. */
    schedule: (key: string, run: AutosaveRun) => void;
    /** Dispara agora tudo o que está agendado (trocar de passo, enviar). */
    flush: () => void;
}

interface Entry {
    timer: ReturnType<typeof setTimeout>;
    run: AutosaveRun;
}

/**
 * Autosave do wizard com debounce (DESIGN §6.5: "Rascunho salvo às 09:41").
 *
 * Cada grupo de alterações tem uma chave própria (`metadata`, `recipients`,
 * `fields`), então digitar o título não atrasa o salvamento dos campos. O
 * debounce padrão é de 800 ms; `flush()` envia o que estiver pendente
 * imediatamente — usado ao avançar de passo e antes de enviar o documento.
 */
export function useWizardAutosave(delay = 800): Autosave {
    const [status, setStatus] = useState<AutosaveStatus>('idle');
    const [savedAt, setSavedAt] = useState<Date | null>(null);
    const entries = useRef(new Map<string, Entry>());
    const inFlight = useRef(0);

    const execute = useCallback((key: string, run: AutosaveRun): void => {
        entries.current.delete(key);
        inFlight.current += 1;
        setStatus('saving');

        run((ok) => {
            inFlight.current = Math.max(0, inFlight.current - 1);

            if (!ok) {
                setStatus('error');

                return;
            }

            if (inFlight.current === 0 && entries.current.size === 0) {
                setSavedAt(new Date());
                setStatus('saved');
            }
        });
    }, []);

    const schedule = useCallback(
        (key: string, run: AutosaveRun): void => {
            const existing = entries.current.get(key);

            if (existing) {
                clearTimeout(existing.timer);
            }

            setStatus('pending');

            const timer = setTimeout(() => execute(key, run), delay);
            entries.current.set(key, { timer, run });
        },
        [delay, execute],
    );

    const flush = useCallback((): void => {
        // Deletar a entrada corrente durante a iteração de um Map é seguro.
        for (const [key, entry] of entries.current.entries()) {
            clearTimeout(entry.timer);
            execute(key, entry.run);
        }
    }, [execute]);

    useEffect(() => {
        const pending = entries.current;

        return () => {
            for (const entry of pending.values()) {
                clearTimeout(entry.timer);
            }

            pending.clear();
        };
    }, []);

    return { status, savedAt, schedule, flush };
}
