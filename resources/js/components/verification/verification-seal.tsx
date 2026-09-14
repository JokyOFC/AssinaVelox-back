import {
    Ban,
    CalendarX2,
    Clock,
    Shield,
    ShieldCheck,
    XCircle,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { EnvelopeStatus, SignatureStatus } from '@/types/enums';
import { SEAL_TONE_CLASSES, verificationSeal } from './seal';

const ICONS: Record<string, LucideIcon> = {
    'shield-check': ShieldCheck,
    shield: Shield,
    clock: Clock,
    x: XCircle,
    calendar: CalendarX2,
    ban: Ban,
};

/**
 * Cartão de estado da verificação pública. Verde **só** quando concluído; o
 * texto do selo concluído distingue "assinado digitalmente pela operadora" de
 * "aceite eletrônico com evidências" (ver `seal.ts`).
 */
export function VerificationSealCard({
    status,
    signatureStatus,
    policy,
    simulated = false,
    portal = false,
    operator = false,
    className,
}: {
    status: EnvelopeStatus;
    signatureStatus?: SignatureStatus | null;
    policy?: string | null;
    /** Fase 3 §3.4: alguma assinatura por componente veio do simulador. */
    simulated?: boolean;
    /** Fase 3 §3.5: algum participante devolveu o arquivo assinado no portal. */
    portal?: boolean;
    /** Fase 3 §3.4: a operadora assinou por último. */
    operator?: boolean;
    className?: string;
}) {
    const seal = verificationSeal(status, signatureStatus, {
        policy,
        simulated,
        portal,
        operator,
    });
    const Icon = ICONS[seal.icon] ?? Shield;

    return (
        <div
            className={cn(
                'flex items-start gap-3 rounded-xl border p-4',
                SEAL_TONE_CLASSES[seal.tone],
                className,
            )}
        >
            <span className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-white/70">
                <Icon className="size-[18px]" />
            </span>
            <div className="min-w-0">
                <p className="text-[15px] leading-[1.3] font-bold">
                    {seal.title}
                </p>
                <p className="mt-1.5 text-[12.5px] leading-[1.55] opacity-90">
                    {seal.description}
                </p>
            </div>
        </div>
    );
}
