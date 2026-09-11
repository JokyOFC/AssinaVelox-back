import { FileText, Lock } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import AppLogo from '@/components/app-logo';
import { AvatarInitials } from '@/components/avatar-initials';
import type { SignerBrand } from '@/components/branding/types';
import { FlashToaster } from '@/components/flash-toaster';
import { Stepper, type StepperStep } from '@/components/stepper';

export type SignerLayoutProps = {
    children: ReactNode;
    /** Remetente exibido no header (organização + iniciais). */
    sender?: {
        organization_name: string;
        organization_initials: string;
        logo_url?: string | null;
        /**
         * Fase 2 §2.8: marca da organização (flag `branding` + marca salva).
         * Ausente ou nula = cabeçalho da Fase 1. "via AssinaVelox" continua
         * visível em qualquer caso.
         */
        brand?: SignerBrand | null;
    } | null;
    /** Título do documento — segunda linha do cabeçalho. */
    documentTitle?: string | null;
    /** Índice do passo atual no stepper público (0 = Confirmar identidade). */
    step?: number | null;
    steps?: StepperStep[];
    /** Link do aviso de privacidade exibido no rodapé. */
    privacyUrl?: string | null;
    termsUrl?: string | null;
};

export const SIGNER_STEPS: StepperStep[] = [
    { key: 'identify', title: 'Confirmar identidade' },
    { key: 'sign', title: 'Assinar' },
    { key: 'completed', title: 'Concluído' },
];

/**
 * Casca da página pública do signatário (DESIGN §6.12; ROUTES §1.6).
 *
 * **Mobile-first**: no celular o cabeçalho guarda só a organização e o título
 * do documento, e o stepper vai para uma faixa própria abaixo; a partir de
 * `md` o stepper sobe para a direita do cabeçalho, como no mock.
 *
 * Não há rastreador de terceiros nesta página: nenhuma fonte, script, pixel ou
 * iframe externo é carregado aqui (as fontes são servidas pelo próprio build,
 * ver `vite.config.ts`). É o que o aviso de privacidade ao signatário promete.
 */
export default function SignerLayout({
    children,
    sender,
    documentTitle = null,
    step = null,
    steps = SIGNER_STEPS,
    privacyUrl = null,
    termsUrl = null,
}: SignerLayoutProps) {
    const brand = sender?.brand ?? null;

    // As cores da marca ficam expostas como variáveis para as páginas públicas
    // que quiserem usá-las (contraste já validado no servidor).
    const brandStyle = brand
        ? ({
              '--brand-primary': brand.primary_color,
              '--brand-accent': brand.accent_color,
              '--brand-on-primary': brand.on_primary,
          } as CSSProperties)
        : undefined;

    return (
        <div
            className="bg-accent text-foreground flex min-h-svh flex-col text-[14px]"
            style={brandStyle}
            data-branded={brand ? 'true' : undefined}
        >
            <header
                className={
                    'border-border sticky top-0 z-10 flex min-h-[60px] items-center gap-3 border-b bg-white px-4 md:gap-4 md:px-6' +
                    (brand ? ' border-t-[3px]' : '')
                }
                style={
                    brand ? { borderTopColor: brand.accent_color } : undefined
                }
            >
                {sender ? (
                    <div className="flex min-w-0 items-center gap-2.5">
                        {brand?.logo_url ? (
                            <img
                                src={brand.logo_url}
                                alt={`Logo de ${brand.display_name}`}
                                className="h-9 max-w-[120px] shrink-0 object-contain"
                            />
                        ) : (
                            <AvatarInitials
                                initials={sender.organization_initials}
                                tone="organization"
                                size="md"
                            />
                        )}
                        <span className="min-w-0">
                            <span className="block truncate text-[13.5px] font-semibold">
                                {brand?.display_name ??
                                    sender.organization_name}
                            </span>
                            <span className="text-muted-foreground block truncate text-[11.5px]">
                                {documentTitle ? (
                                    <span className="inline-flex items-center gap-1">
                                        <FileText className="size-3 shrink-0" />
                                        {documentTitle}
                                    </span>
                                ) : (
                                    'solicita sua assinatura'
                                )}
                            </span>
                        </span>
                    </div>
                ) : (
                    <AppLogo height={24} />
                )}

                {step !== null && (
                    <div className="hidden flex-1 justify-center lg:flex">
                        <Stepper steps={steps} current={step} variant="pills" />
                    </div>
                )}

                <div className="text-muted-foreground border-border ml-auto flex shrink-0 items-center gap-1.5 border-l pl-3 text-[11.5px]">
                    <span className="hidden sm:inline">via</span>
                    <AppLogo height={18} />
                </div>
            </header>

            {step !== null && (
                <div className="border-border flex justify-center overflow-x-auto border-b bg-white px-4 py-2 lg:hidden">
                    <Stepper steps={steps} current={step} variant="pills" />
                </div>
            )}

            <main className="mx-auto flex w-full max-w-[1200px] flex-1 flex-col gap-4 px-4 pt-4 pb-8 md:px-6 md:pt-5 md:pb-10">
                {children}
            </main>

            <footer className="text-muted-foreground flex flex-col items-center gap-1.5 px-6 py-5 text-center text-[11.5px]">
                <span className="flex flex-wrap items-center justify-center gap-x-2.5 gap-y-1">
                    <span className="inline-flex items-center gap-1">
                        <Lock className="size-3" />
                        Conexão protegida (TLS)
                    </span>
                    <span aria-hidden>·</span>
                    <span>Trilha de auditoria</span>
                    {privacyUrl && (
                        <>
                            <span aria-hidden>·</span>
                            <a
                                href={privacyUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-muted-foreground underline-offset-2 hover:underline"
                            >
                                Aviso de privacidade
                            </a>
                        </>
                    )}
                    {termsUrl && (
                        <>
                            <span aria-hidden>·</span>
                            <a
                                href={termsUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-muted-foreground underline-offset-2 hover:underline"
                            >
                                Termos de uso
                            </a>
                        </>
                    )}
                </span>
                <span>
                    Documento processado pela AssinaVelox · aceite eletrônico
                    com evidências
                </span>
            </footer>

            <FlashToaster />
        </div>
    );
}
