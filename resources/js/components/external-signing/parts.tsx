import { CircleAlert, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Peças visuais comuns aos cartões de assinatura externa da página pública — as mesmas do
 * cartão do certificado A1 (`participant-certificate-card.tsx`), para as três opções
 * parecerem uma família: cartão 14px, ícone em quadrado azul-claro, notas por tom.
 */

export type NoteTone = 'info' | 'success' | 'warning' | 'danger' | 'neutral';

const NOTE_CLASSES: Record<NoteTone, string> = {
    info: 'border-primary-soft-border bg-primary-soft text-primary',
    success: 'border-success-border bg-success-bg text-success',
    warning: 'border-warning-border bg-warning-bg text-warning',
    danger: 'border-danger-border bg-danger-bg text-danger',
    neutral: 'border-neutral-border bg-neutral-bg text-text-secondary',
};

export function Note({
    tone,
    icon,
    children,
    className,
    role,
}: {
    tone: NoteTone;
    icon?: ReactNode;
    children: ReactNode;
    className?: string;
    role?: 'alert' | 'note' | 'status';
}) {
    return (
        <div
            role={role}
            className={cn(
                'flex items-start gap-2 rounded-[10px] border p-3 text-[12.5px] leading-[1.5]',
                NOTE_CLASSES[tone],
                className,
            )}
        >
            {icon && <span className="mt-0.5 shrink-0">{icon}</span>}
            <div className="min-w-0">{children}</div>
        </div>
    );
}

export function CardShell({
    icon: Icon,
    title,
    subtitle,
    badge,
    label,
    className,
    children,
}: {
    icon: LucideIcon;
    title: string;
    subtitle: string;
    badge?: ReactNode;
    /** `aria-label` da seção. */
    label: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <section
            aria-label={label}
            className={cn(
                'border-border bg-card shadow-card flex flex-col gap-3.5 rounded-[14px] border p-5 sm:p-[22px]',
                className,
            )}
        >
            <div className="flex items-start gap-3">
                <span className="bg-primary-soft text-primary flex size-9 shrink-0 items-center justify-center rounded-[10px]">
                    <Icon className="size-[18px]" />
                </span>
                <div className="min-w-0 flex-1">
                    <h2 className="text-[15px] leading-tight font-semibold">
                        {title}
                    </h2>
                    <p className="text-muted-foreground mt-0.5 text-[12.5px]">
                        {subtitle}
                    </p>
                    {/* Abaixo do título: no comprovante a coluna é estreita e o selo é longo. */}
                    {badge && <div className="mt-1.5">{badge}</div>}
                </div>
            </div>
            {children}
        </section>
    );
}

export function ErrorAlert({
    title,
    message,
}: {
    title: string;
    message: string;
}) {
    return (
        <div
            role="alert"
            className="border-danger-border bg-danger-bg text-danger flex items-start gap-2 rounded-[10px] border p-3 text-[12.5px] leading-[1.5]"
        >
            <CircleAlert className="mt-0.5 size-4 shrink-0" />
            <span>
                <b>{title}.</b> {message}
            </span>
        </div>
    );
}

/** Passo numerado (instruções do gov.br e do componente local). */
export function StepList({
    steps,
    className,
}: {
    steps: ReactNode[];
    className?: string;
}) {
    return (
        <ol className={cn('flex flex-col gap-2', className)}>
            {steps.map((step, index) => (
                <li
                    key={index}
                    className="text-text-secondary flex items-start gap-2.5 text-[12.5px] leading-[1.5]"
                >
                    <span className="bg-accent text-foreground tabular flex size-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold">
                        {index + 1}
                    </span>
                    <span className="min-w-0">{step}</span>
                </li>
            ))}
        </ol>
    );
}

/** Selo "simulado" — o simulador é SEMPRE identificado (T1). */
export function SimulatedTag({ className }: { className?: string }) {
    return (
        <span
            className={cn(
                'border-warning-border bg-warning-bg text-warning inline-flex items-center rounded-md border px-1.5 py-px text-[11px] font-bold tracking-[.02em] uppercase',
                className,
            )}
        >
            simulado
        </span>
    );
}
