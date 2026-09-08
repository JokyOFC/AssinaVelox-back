import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AppLogo from '@/components/app-logo';
import { FlashToaster } from '@/components/flash-toaster';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { dashboard, home, login, register } from '@/routes';
import { privacy, terms } from '@/routes/legal';
import { index as verifyIndex } from '@/routes/verify';

export type PublicLayoutProps = {
    children: ReactNode;
    /** Largura máxima do conteúdo (default 820px; marketing usa 1100). */
    maxWidth?: number;
    /** Remove o padding/largura do conteúdo (marketing). */
    fullBleed?: boolean;
};

/**
 * Casca das páginas públicas simples (Termos, Privacidade, Verificação,
 * Home mínima, erros): header branco com logo + links, conteúdo centralizado,
 * rodapé com links legais.
 */
export default function PublicLayout({
    children,
    maxWidth = 820,
    fullBleed = false,
}: PublicLayoutProps) {
    // Páginas de erro (errors/403|404|500) podem ser renderizadas por uma rota que nunca
    // casou — o grupo `web` (e portanto HandleInertiaRequests) não roda e NENHUMA prop
    // compartilhada chega. Sem o fallback, `auth.user` quebrava a página inteira em branco.
    const { auth } = usePage().props as Partial<
        ReturnType<typeof usePage>['props']
    >;
    const user = auth?.user ?? null;

    return (
        <div className="bg-background text-foreground flex min-h-svh flex-col text-[14px]">
            <header className="border-border flex h-[60px] items-center gap-4 border-b bg-white px-4 md:px-6">
                <Link href={home()} className="inline-block">
                    <AppLogo height={28} />
                </Link>
                <nav className="ml-auto flex items-center gap-2 text-[13.5px]">
                    <Link
                        href={verifyIndex()}
                        className="text-text-secondary hover:text-primary hidden px-2 font-medium sm:inline"
                    >
                        Verificar documento
                    </Link>
                    {user ? (
                        <Button asChild size="sm">
                            <Link href={dashboard()}>Ir para o painel</Link>
                        </Button>
                    ) : (
                        <>
                            <Button asChild variant="outline" size="sm">
                                <Link href={login()}>Entrar</Link>
                            </Button>
                            <Button
                                asChild
                                size="sm"
                                className="hidden sm:inline-flex"
                            >
                                <Link href={register()}>
                                    Criar conta grátis
                                </Link>
                            </Button>
                        </>
                    )}
                </nav>
            </header>
            <main
                className={cn(
                    'flex w-full flex-1 flex-col',
                    !fullBleed && 'mx-auto gap-5 px-4 py-8 md:px-6 md:py-10',
                )}
                style={fullBleed ? undefined : { maxWidth }}
            >
                {children}
            </main>
            <footer className="border-border text-muted-foreground border-t bg-white px-6 py-5 text-[12px]">
                <div className="mx-auto flex max-w-[1100px] flex-wrap items-center justify-between gap-3">
                    <span>
                        © {new Date().getFullYear()} AssinaVelox · Assinatura
                        eletrônica com trilha de auditoria
                    </span>
                    <div className="flex gap-4">
                        <Link href={terms()} className="hover:text-primary">
                            Termos de uso
                        </Link>
                        <Link href={privacy()} className="hover:text-primary">
                            Privacidade
                        </Link>
                        <Link
                            href={verifyIndex()}
                            className="hover:text-primary"
                        >
                            Verificar documento
                        </Link>
                    </div>
                </div>
            </footer>
            <FlashToaster />
        </div>
    );
}
