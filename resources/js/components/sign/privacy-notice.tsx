import { ChevronDown, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { LegalText, Emphasis } from '@/components/sign/legal-text';
import { cn } from '@/lib/utils';

export interface PrivacyNoticeContent {
    /** Linha-resumo sempre visível (aviso-de-privacidade-signatario.md). */
    summary: string;
    /** Texto completo, com as variáveis da Operadora já substituídas. */
    body: string | null;
}

/**
 * Aviso de privacidade ao signatário (`docs/juridico/aviso-de-privacidade-signatario.md`).
 *
 * Exibido duas vezes: na etapa "Confirmar identidade", **antes** do botão
 * "Receber código", e na etapa "Assinar", junto da caixa de aceite. A
 * linha-resumo fica sempre visível; o texto completo abre num `details` — não
 * é escondido atrás de um link para outra página.
 */
export function PrivacyNotice({
    notice,
    privacyUrl,
    className,
}: {
    notice: PrivacyNoticeContent;
    privacyUrl: string;
    className?: string;
}) {
    const [open, setOpen] = useState(false);

    return (
        <div
            className={cn(
                'border-border bg-sidebar rounded-[10px] border p-3',
                className,
            )}
        >
            <div className="flex gap-2.5">
                <ShieldCheck className="text-primary mt-px size-4 shrink-0" />
                <p className="text-text-secondary text-[12.5px] leading-[1.5]">
                    <Emphasis text={notice.summary} />
                </p>
            </div>

            {notice.body ? (
                <>
                    <button
                        type="button"
                        onClick={() => setOpen((value) => !value)}
                        aria-expanded={open}
                        className="text-primary mt-2 inline-flex items-center gap-1 text-[12.5px] font-semibold"
                    >
                        {open ? 'Ocultar aviso completo' : 'Ver aviso completo'}
                        <ChevronDown
                            className={cn(
                                'size-3.5 transition-transform',
                                open && 'rotate-180',
                            )}
                        />
                    </button>
                    {open && (
                        <div className="border-border mt-2.5 max-h-[280px] overflow-y-auto border-t pt-2.5">
                            <LegalText text={notice.body} />
                        </div>
                    )}
                </>
            ) : (
                <a
                    href={privacyUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-primary mt-2 inline-block text-[12.5px] font-semibold"
                >
                    Ver aviso completo
                </a>
            )}
        </div>
    );
}

/**
 * Linha-resumo padrão quando o servidor ainda não envia `privacy_notice`.
 * O texto é o do documento jurídico, com a variável de remetente substituída;
 * as variáveis da Operadora (razão social, CNPJ, DPO) só existem no servidor,
 * por isso o corpo completo fica `null` e o link aponta para a política.
 */
export function defaultPrivacyNotice(
    organizationName: string,
): PrivacyNoticeContent {
    return {
        summary: `Este documento foi enviado por **${organizationName}**. Para registrar seu aceite, a AssinaVelox gravará data, IP, navegador, a versão exata do documento e o código confirmado por e-mail.`,
        body: null,
    };
}
