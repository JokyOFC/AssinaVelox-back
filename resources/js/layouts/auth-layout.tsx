import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AppLogo from '@/components/app-logo';
import { FlashToaster } from '@/components/flash-toaster';
import { useSiteUrl } from '@/hooks/use-site-url';
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

/**
 * Selos da porta de entrada. Cada um afirma algo que a plataforma cumpre sempre.
 *
 * DESIGN_SYSTEM §6.1 prescreve um selo de validade e outro de ICP-Brasil; nenhum dos
 * dois pode ficar. Os Termos de Uso §3.5 ("Sem garantia de validade jurídica universal")
 * negam o primeiro com todas as letras, e a declaração de aceite repete a ressalva no
 * item 5. O segundo — anunciado como "Certificado A1 da operadora" — apresentava como
 * característica fixa algo que só existe quando há certificado configurado; sem ele o
 * envelope conclui como aceite eletrônico com evidências (arquitetura §2), e a
 * arquitetura tem precedência sobre o design pela ordem de RECONCILIACAO.md.
 */
const TRUST_BADGES = [
    'Aceite eletrônico com evidências',
    'Conforme LGPD',
    'Verificação pública por código',
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
    // O app não tem página inicial: `home` redireciona para o próprio login. O logo leva ao
    // site institucional (assinavelox.com.br), como em qualquer produto.
    const siteUrl = useSiteUrl();

    return (
        <div className="bg-background text-foreground flex min-h-svh text-[14px]">
            <aside className="bg-navy relative hidden min-w-[360px] flex-[0_0_44%] flex-col justify-between overflow-hidden px-12 py-10 text-white lg:flex">
                <div
                    aria-hidden
                    className="pointer-events-none absolute -top-[120px] -right-[120px] size-[420px] rounded-full"
                    style={{
                        background:
                            'radial-gradient(circle, rgba(46,123,239,.35), rgba(46,123,239,0) 70%)',
                    }}
                />
                <a href={siteUrl} className="relative inline-block w-fit">
                    <AppLogo inverted height={34} />
                </a>

                <div className="relative">
                    <p className="text-on-navy-muted mb-4 text-[11px] font-semibold tracking-[.24em] uppercase">
                        Plataforma de assinatura eletrônica
                    </p>
                    <h2
                        className="font-extrabold uppercase italic"
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
                        <div className="text-on-navy-muted mb-2 text-[9.5px] font-bold tracking-[.16em] uppercase">
                            Assinatura
                        </div>
                        <div
                            className="animate-sign-wipe font-hand -rotate-2 text-[34px] leading-none text-white"
                            style={{
                                fontFamily: "'Caveat', 'Segoe Script', cursive",
                            }}
                        >
                            Maria A. Souza
                        </div>
                        <div className="text-on-navy-secondary mt-3.5 flex items-center justify-between border-t border-dashed border-white/[.14] pt-3 text-[12px]">
                            <span>Contrato de locação · Apto 302</span>
                            <span className="text-success-solid font-bold">
                                Assinado ✓
                            </span>
                        </div>
                    </div>

                    <div className="text-on-navy-secondary mt-7 flex flex-wrap gap-x-6 gap-y-2.5 text-[12.5px] font-semibold">
                        {TRUST_BADGES.map((badge) => (
                            <span key={badge}>✓ {badge}</span>
                        ))}
                    </div>
                </div>

                <div className="text-on-navy-muted relative flex gap-5 text-[12px]">
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
                    <a href={siteUrl} className="inline-block w-fit lg:hidden">
                        <AppLogo height={30} />
                    </a>
                    {!hideHeader && (title || description) && (
                        <div>
                            {title && (
                                <h1 className="text-[26px] font-bold tracking-[-.01em]">
                                    {title}
                                </h1>
                            )}
                            {description && (
                                <p className="text-text-secondary mt-2 text-[14px] leading-[1.55]">
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
