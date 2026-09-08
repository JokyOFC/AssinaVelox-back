import { Head } from '@inertiajs/react';
import { formatDate } from '@/lib/format';
import type { LegalPageProps } from '@/pages/legal/terms';

/** Política de Privacidade (ROUTES §1.3). Conteúdo final vem do backend; casca inicial. */
export default function Privacy({ content_html = null, version = null, updated_at = null }: LegalPageProps) {
    return (
        <>
            <Head title="Política de Privacidade" />
            <article className="rounded-xl border border-border bg-card p-6 shadow-card md:p-8">
                <p className="text-[11px] font-bold tracking-[.18em] text-muted-foreground uppercase">
                    Documento legal
                </p>
                <h1 className="mt-2 text-[26px] font-bold tracking-[-.01em]">Política de Privacidade</h1>
                <p className="mt-1.5 text-[13px] text-muted-foreground">
                    {version ? `Versão ${version}` : 'Versão em elaboração'}
                    {updated_at && ` · atualizado em ${formatDate(updated_at)}`}
                </p>
                <div className="mt-6 text-[14px] leading-[1.65] text-text-secondary [&_h2]:mt-6 [&_h2]:text-[18px] [&_h2]:font-bold [&_h2]:text-foreground [&_li]:mt-1 [&_p]:mt-3 [&_ul]:mt-3 [&_ul]:list-disc [&_ul]:pl-5">
                    {content_html ? (
                        <div dangerouslySetInnerHTML={{ __html: content_html }} />
                    ) : (
                        <>
                            <p>
                                Esta política explica quais dados pessoais a AssinaVelox trata
                                para operar a plataforma (dados de conta, dados dos signatários
                                informados pelo remetente, registros técnicos de aceite como IP
                                e user-agent), com quais finalidades e por quanto tempo, em
                                conformidade com a LGPD (Lei 13.709/2018).
                            </p>
                            <p>
                                O texto integral está em elaboração e será publicado nesta
                                página antes do lançamento comercial.
                            </p>
                        </>
                    )}
                </div>
            </article>
        </>
    );
}
