import { usePage } from '@inertiajs/react';
import { type ReactNode, useCallback, useEffect, useState } from 'react';
import type { AuthUser, SharedProps } from '@/types';
import {
    AuthTransitionOverlay,
    type AuthTransitionScene,
    splitName,
} from './auth-transition-overlay';
import { trackInteractionOrigin } from './interaction-origin';

type KnownUser = Pick<AuthUser, 'id' | 'name' | 'email'>;

type PreviewOptions = {
    /** Congela a cena neste instante (ms) — para olhar um quadro com calma. */
    at?: number;
    name?: string;
    email?: string;
};

declare global {
    interface Window {
        /** Só em desenvolvimento: toca (ou congela) a transição sem precisar entrar ou sair. */
        __avAuthTransition?: (
            kind: 'login' | 'logout' | null,
            options?: PreviewOptions,
        ) => void;
    }
}

function toKnown(user: AuthUser | null | undefined): KnownUser | null {
    return user ? { id: user.id, name: user.name, email: user.email } : null;
}

function sameUser(
    a: KnownUser | null | undefined,
    b: KnownUser | null,
): boolean {
    if (a === undefined) {
        return false;
    }

    if (a === null || b === null) {
        return a === b;
    }

    return a.id === b.id && a.name === b.name && a.email === b.email;
}

function announcement(scene: AuthTransitionScene): string {
    const { first } = splitName(scene.name);

    if (scene.kind === 'login') {
        return first
            ? `Acesso autenticado. Boas-vindas, ${first}.`
            : 'Acesso autenticado.';
    }

    return first
        ? `Sessão encerrada. Até logo, ${first}.`
        : 'Sessão encerrada.';
}

/**
 * Raiz comum a todas as cascas (resources/js/app.tsx). Fica montada de uma página para a
 * outra, então é ela que percebe quando `auth.user` aparece (entrou) ou some (saiu) e põe a
 * cortina da transição no MESMO commit da troca de página — a página nova nunca pisca antes.
 *
 * Vale para qualquer caminho que troque a conta sem recarregar o navegador: login, desafio
 * de dois fatores, cadastro, convite aceito, "Sair", exclusão da conta, sessão expirada.
 * Trocar de um usuário para outro ("acessar como") não é entrada nem saída e não anima.
 */
export default function AuthTransitionRoot({
    children,
}: {
    children: ReactNode;
}) {
    // Páginas de erro podem chegar sem props compartilhadas (ver PublicLayout): aí não se sabe
    // quem está logado, e não saber é diferente de "ninguém".
    const { auth } = usePage().props as Partial<SharedProps>;
    const current = auth === undefined ? undefined : toKnown(auth.user);

    const [known, setKnown] = useState(current);
    const [scene, setScene] = useState<
        (AuthTransitionScene & { key: number }) | null
    >(null);

    // Estado derivado no render (e não num efeito) para a cortina nascer junto com a página.
    if (current !== undefined && !sameUser(known, current)) {
        setKnown(current);

        const change: AuthTransitionScene | null =
            known === null && current
                ? { kind: 'login', name: current.name, email: current.email }
                : known && current === null
                  ? { kind: 'logout', name: known.name, email: known.email }
                  : null;

        if (change) {
            setScene((previous) => ({
                ...change,
                key: (previous?.key ?? 0) + 1,
            }));
        }
    }

    const clearScene = useCallback(() => setScene(null), []);

    useEffect(() => trackInteractionOrigin(), []);

    // Na área pública, deixa a letra de mão pronta: abaixo de `lg` o aside do login (o único
    // outro lugar que a usa) não é renderizado, e a cena de entrada começaria na fonte reserva.
    const isGuest = current === null;

    useEffect(() => {
        if (isGuest) {
            void document.fonts?.load('600 50px Caveat');
        }
    }, [isGuest]);

    useEffect(() => {
        if (!import.meta.env.DEV) {
            return;
        }

        window.__avAuthTransition = (kind, options = {}) => {
            setScene((previous) =>
                kind === null
                    ? null
                    : {
                          kind,
                          name: options.name ?? known?.name ?? 'Maria Souza',
                          email:
                              options.email ??
                              known?.email ??
                              'maria@empresa.com.br',
                          frozenAt: options.at ?? null,
                          key: (previous?.key ?? 0) + 1,
                      },
            );
        };

        return () => {
            delete window.__avAuthTransition;
        };
    }, [known]);

    return (
        <>
            {children}
            <p role="status" className="sr-only">
                {scene ? announcement(scene) : ''}
            </p>
            {scene && (
                <AuthTransitionOverlay
                    key={scene.key}
                    scene={scene}
                    onDone={clearScene}
                />
            )}
        </>
    );
}
