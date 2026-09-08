import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AppLogo from '@/components/app-logo';
import { FlashToaster } from '@/components/flash-toaster';
import { home } from '@/routes';
import { privacy, terms } from '@/routes/legal';

export type AuthLayoutProps = {
    children: ReactNode;
    title?: string;
    description?: ReactNode;
    /** Oculta o bloco de título (páginas que desenham o próprio cabeçalho, ex.: estado "enviado"). */
    hideHeader?: boolean;
    /** Largura máxima da coluna do formulário (default 400px). */
    maxWidth?: number;
};

const TRUST_BADGES = [
    'Validade jurídica',
    'Conforme LGPD',
    'Certificado A1 da operadora',
    'Trilha de auditoria',
];

/**
 * Layout split de autenticação (DESIGN §6.1): aside navy 44% (min 360px,
 * oculto abaixo de lg) com logo invertida, hero, glass card de assinatura,
 * trust badges e rodapé; formulário centralizado com max-w 400px.
 */
export default function AuthLayout({
    children,
    title,
    description,
    hideHeader = false,
    maxWidth = 400,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-svh bg-background text-[14px] text-foreground">
            <aside className="relative hidden min-w-[360px] flex-[0_0_44%] flex-col justify-between overflow-hidden bg-navy px-12 py-10 text-white lg:flex">
                <div
                    aria-hidden
                    className="pointer-events-none absolute -top-[120px] -right-[120px] size-[420px] rounded-full"
                    style={{
                        background:
                            'radial-gradient(circle, rgba(46,123,239,.35), rgba(46,123,239,0) 70%)',
                    }}
                />
                <Link href={home()} className="relative inline-block w-fit">
                    <AppLogo inverted height={34} />
                </Link>

                <div className="relative">
                    <p className="mb-4 text-[11px] font-semibold tracking-[.24em] text-on-navy-muted uppercase">
                        Plataforma de assinatura eletrônica
                    </p>
                    <h2
                        className="font-extrabold italic uppercase"
                        style={{
                            fontSize: 'clamp(36px, 4vw, 56px)',
                            lineHeight: 0.95,
                            letterSpacing: '-.015em',
                        }}
                    >
                        Assine documentos
                        <br />
                        em <span className="text-primary-bright">minutos</span>
                    </h2>

                    <div className="mt-9 max-w-[360px] rounded-xl border border-white/10 bg-white/[.06] px-5 pt-[18px] pb-3.5">
                        <div className="mb-2 text-[9.5px] font-bold tracking-[.16em] text-on-navy-muted uppercase">
                            Assinatura
                        </div>
                        <div
                            className="animate-sign-wipe -rotate-2 font-hand text-[34px] leading-none text-white"
                            style={{ fontFamily: "'Caveat', 'Segoe Script', cursive" }}
                        >
                            Maria A. Souza
                        </div>
                        <div className="mt-3.5 flex items-center justify-between border-t border-dashed border-white/[.14] pt-3 text-[12px] text-on-navy-secondary">
                            <span>Contrato de locação · Apto 302</span>
                            <span className="font-bold text-success-solid">Assinado ✓</span>
                        </div>
                    </div>

                    <div className="mt-7 flex flex-wrap gap-x-6 gap-y-2.5 text-[12.5px] font-semibold text-on-navy-secondary">
                        {TRUST_BADGES.map((badge) => (
                            <span key={badge}>✓ {badge}</span>
                        ))}
                    </div>
                </div>

                <div className="relative flex gap-5 text-[12px] text-on-navy-muted">
                    <Link href={terms()} className="hover:text-white">
                        Termos de uso
                    </Link>
                    <Link href={privacy()} className="hover:text-white">
                        Privacidade
                    </Link>
                    <a
                        href="https://ajuda.assinavelox.com.br"
                        target="_blank"
                        rel="noopener noreferrer"
                        className="hover:text-white"
                    >
                        Suporte
                    </a>
                </div>
            </aside>

            <main className="flex flex-1 items-center justify-center px-6 py-10">
                <div
                    className="flex w-full flex-col gap-6"
                    style={{ maxWidth }}
                >
                    <Link href={home()} className="inline-block w-fit lg:hidden">
                        <AppLogo height={30} />
                    </Link>
                    {!hideHeader && (title || description) && (
                        <div>
                            {title && (
                                <h1 className="text-[26px] font-bold tracking-[-.01em]">
                                    {title}
                                </h1>
                            )}
                            {description && (
                                <p className="mt-2 text-[14px] leading-[1.55] text-text-secondary">
                                    {description}
                                </p>
                            )}
                        </div>
                    )}
                    {children}
                </div>
            </main>
            <FlashToaster />
        </div>
    );
}
