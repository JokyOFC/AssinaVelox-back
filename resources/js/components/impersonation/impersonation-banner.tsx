import { router, usePage } from '@inertiajs/react';
import { Eye, LogOut } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { stop as stopImpersonation } from '@/routes/admin/impersonation';

/** Prop compartilhada pelo middleware EnforceImpersonationReadOnly. */
export interface ImpersonationState {
    id: string;
    admin_name: string;
    target_name: string;
    organization_name: string;
    expires_at: string;
}

function minutesLeft(expiresAt: string): number {
    return Math.max(
        0,
        Math.ceil((new Date(expiresAt).getTime() - Date.now()) / 60000),
    );
}

/**
 * Banner persistente do "acessar como" (Fase 2 — ROUTES Q15): aparece em
 * todas as telas enquanto a sessão de suporte existir, com o tempo restante e
 * o botão "Encerrar". A sessão é somente leitura (o servidor recusa qualquer
 * ação); o banner só informa.
 */
export function ImpersonationBanner() {
    const shared = (
        usePage().props as {
            impersonation?: Partial<ImpersonationState> | null;
        }
    ).impersonation;
    // Só a prop COMPARTILHADA (sessão ativa) tem `expires_at`; qualquer outro objeto com o
    // mesmo nome não pode acender o banner (integração I-2A: a página do cliente no painel
    // interno tinha uma prop `impersonation` própria e mostrava "encerra em NaN min").
    const impersonation =
        shared && typeof shared.expires_at === 'string'
            ? (shared as ImpersonationState)
            : null;
    const [left, setLeft] = useState(() =>
        impersonation ? minutesLeft(impersonation.expires_at) : 0,
    );
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!impersonation) {
            return;
        }

        setLeft(minutesLeft(impersonation.expires_at));
        const timer = window.setInterval(
            () => setLeft(minutesLeft(impersonation.expires_at)),
            30000,
        );

        return () => window.clearInterval(timer);
    }, [impersonation]);

    if (!impersonation) {
        return null;
    }

    return (
        <div
            role="status"
            className="bg-navy sticky top-0 z-40 flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-[13px] text-white"
        >
            <span className="inline-flex min-w-0 items-center gap-2">
                <Eye className="text-primary-bright size-4 shrink-0" />
                <span className="min-w-0">
                    Você está acessando como{' '}
                    <strong className="font-semibold">
                        {impersonation.target_name}
                    </strong>{' '}
                    · {impersonation.organization_name}
                    <span className="text-on-navy-subtle">
                        {' '}
                        · somente leitura · encerra em {left} min
                    </span>
                </span>
            </span>
            <Button
                size="xs"
                variant="secondary"
                disabled={busy}
                onClick={() => {
                    setBusy(true);
                    router.post(
                        stopImpersonation.url(),
                        {},
                        { onFinish: () => setBusy(false) },
                    );
                }}
            >
                <LogOut className="size-3.5" />
                Encerrar
            </Button>
        </div>
    );
}
