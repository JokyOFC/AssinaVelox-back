import { Head } from '@inertiajs/react';
import { formatDate } from '@/lib/format';

export interface LegalPageProps {
    /** HTML sanitizado gerado no backend (Markdown → HTML) ou null. */
    content_html?: string | null;
    version?: string | null;
    updated_at?: string | null;
}

/** Termos de uso (ROUTES §1.3). Conteúdo final vem do backend; casca inicial. */
export default function Terms({
    content_html = null,
    version = null,
    updated_at = null,
}: LegalPageProps) {
    return (
        <>
            <Head title="Termos de uso" />
            <article className="border-border bg-card shadow-card rounded-xl border p-6 md:p-8">
                <p className="text-muted-foreground text-[11px] font-bold tracking-[.18em] uppercase">
                    Documento legal
                </p>
                <h1 className="mt-2 text-[26px] font-bold tracking-[-.01em]">
                    Termos de uso
                </h1>
                <p className="text-muted-foreground mt-1.5 text-[13px]">
                    {version ? `Versão ${version}` : 'Versão em elaboração'}
                    {updated_at && ` · atualizado em ${formatDate(updated_at)}`}
                </p>
                <div className="text-text-secondary [&_h2]:text-foreground mt-6 text-[14px] leading-[1.65] [&_h2]:mt-6 [&_h2]:text-[18px] [&_h2]:font-bold [&_li]:mt-1 [&_p]:mt-3 [&_ul]:mt-3 [&_ul]:list-disc [&_ul]:pl-5">
                    {content_html ? (
                        <div
                            dangerouslySetInnerHTML={{ __html: content_html }}
                        />
                    ) : (
                        <>
                            <p>
                                Os Termos de uso da AssinaVelox descrevem as
                                condições para utilização da plataforma de
                                preparo de documentos e coleta de aceites
                                eletrônicos, incluindo responsabilidades das
                                organizações remetentes, dos signatários e da
                                operadora.
                            </p>
                            <p>
                                O texto integral está em elaboração e será
                                publicado nesta página antes do lançamento
                                comercial. Enquanto isso, o uso da plataforma em
                                ambiente de testes não gera obrigações
                                contratuais.
                            </p>
                        </>
                    )}
                </div>
            </article>
        </>
    );
}
