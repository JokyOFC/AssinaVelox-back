import { Link, usePage } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import type { SharedProps } from '@/types';

/**
 * Faixa persistente do antifraude (Fase 3 §3.7; docs/fase-3/antifraude.md §6). Aparece em
 * todas as telas do app enquanto o envio de novos documentos da organização estiver
 * suspenso, com o caminho para ver o motivo e pedir revisão humana (LGPD art. 20). Só usa
 * o estado compartilhado — nunca pontuação ou regra.
 */
export function RiskRestrictionBanner() {
    const risk = (usePage().props as Partial<SharedProps>).risk;

    if (!risk || risk.status !== 'restricted') {
        return null;
    }

    return (
        <div
            role="status"
            className="border-warning-border bg-warning-bg text-warning flex flex-wrap items-center justify-between gap-2 border-b px-4 py-2 text-[13px]"
        >
            <span className="inline-flex min-w-0 items-center gap-2">
                <ShieldAlert className="size-4 shrink-0" />
                <span className="min-w-0">
                    <strong className="font-semibold">
                        Envio de novos documentos suspenso
                    </strong>{' '}
                    até uma revisão de segurança. Documentos já enviados
                    continuam disponíveis.
                </span>
            </span>
            <Link
                href={risk.appeal_url}
                className="font-semibold underline underline-offset-2"
            >
                Ver motivo e pedir revisão
            </Link>
        </div>
    );
}
