import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AppLogo from '@/components/app-logo';
import { initials } from '@/lib/format';
import { privacy, terms } from '@/routes/legal';

/**
 * Casca da página pública do formulário (Fase 2 §2.2): mobile-first, leve e
 * sem rastreadores — nenhuma fonte, script, pixel ou iframe de terceiros, só o
 * bundle da própria aplicação. A organização dona do formulário vem primeiro;
 * "via AssinaVelox" fica sempre visível. `noindex` também no cabeçalho HTTP.
 */
export function PublicFormShell({
    title,
    organizationName,
    children,
}: {
    title: string;
    organizationName?: string | null;
    children: ReactNode;
}) {
    return (
        <div className="bg-background text-foreground flex min-h-svh flex-col text-[14px]">
            <Head title={title}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <header className="border-border flex min-h-[60px] items-center gap-3 border-b bg-white px-4 py-2 md:px-6">
                {organizationName ? (
                    <>
                        <span
                            aria-hidden
                            className="bg-primary-soft text-primary flex size-9 shrink-0 items-center justify-center rounded-[10px] text-[13px] font-bold"
                        >
                            {initials(organizationName)}
                        </span>
                        <div className="min-w-0">
                            <p className="truncate text-[14px] font-semibold">
                                {organizationName}
                            </p>
                            <p className="text-muted-foreground text-[11.5px]">
                                via AssinaVelox
                            </p>
                        </div>
                    </>
                ) : (
                    <AppLogo height={26} />
                )}
            </header>
            <main className="mx-auto flex w-full max-w-[640px] flex-1 flex-col gap-5 px-4 py-6 md:px-6 md:py-10">
                {children}
            </main>
            <footer className="border-border text-muted-foreground border-t bg-white px-4 py-4 text-[12px] md:px-6">
                <div className="mx-auto flex max-w-[640px] flex-wrap items-center justify-between gap-3">
                    <span>Formulário hospedado pela AssinaVelox</span>
                    <div className="flex gap-4">
                        <Link href={terms()} className="hover:text-primary">
                            Termos de uso
                        </Link>
                        <Link href={privacy()} className="hover:text-primary">
                            Privacidade
                        </Link>
                    </div>
                </div>
            </footer>
        </div>
    );
}

/** Cartão de mensagem (pausado, encerrado, enviado, link vencido…). */
export function PublicFormMessage({
    icon,
    title,
    children,
    tone = 'neutral',
}: {
    icon: ReactNode;
    title: string;
    children?: ReactNode;
    tone?: 'neutral' | 'success' | 'warning';
}) {
    const toneClass = {
        neutral: 'bg-muted text-text-secondary',
        success: 'bg-success-bg text-success',
        warning: 'bg-warning-bg text-warning',
    }[tone];

    return (
        <div
            role="status"
            className="border-border bg-card shadow-card flex flex-col items-start gap-4 rounded-xl border p-6"
        >
            <span
                className={`flex size-[52px] items-center justify-center rounded-[14px] ${toneClass}`}
            >
                {icon}
            </span>
            <div>
                <h1 className="text-[22px] leading-[1.2] font-bold tracking-[-.01em]">
                    {title}
                </h1>
                {children && (
                    <div className="text-text-secondary mt-2 flex flex-col gap-2 text-[14px] leading-[1.55]">
                        {children}
                    </div>
                )}
            </div>
        </div>
    );
}
