import type { ReactNode } from 'react';
import { AvatarInitials } from '@/components/avatar-initials';
import AppLogo from '@/components/app-logo';
import { FlashToaster } from '@/components/flash-toaster';
import { Stepper, type StepperStep } from '@/components/stepper';

export type SignerLayoutProps = {
    children: ReactNode;
    /** Remetente exibido no header (organização + iniciais). */
    sender?: { organization_name: string; organization_initials: string } | null;
    /** Índice do passo atual no stepper público (0 = Confirmar identidade). */
    step?: number | null;
    steps?: StepperStep[];
};

export const SIGNER_STEPS: StepperStep[] = [
    { key: 'identify', title: 'Confirmar identidade' },
    { key: 'sign', title: 'Assinar' },
    { key: 'completed', title: 'Concluído' },
];

/**
 * Casca da página pública do signatário (DESIGN §6.12; ROUTES §1.6):
 * header branco 60px com organização remetente, stepper em pills e
 * "via AssinaVelox"; corpo `bg-accent`, largura máxima 1200px.
 * Wave B preenche o conteúdo.
 */
export default function SignerLayout({
    children,
    sender,
    step = null,
    steps = SIGNER_STEPS,
}: SignerLayoutProps) {
    return (
        <div className="flex min-h-svh flex-col bg-accent text-[14px] text-foreground">
            <header className="flex h-[60px] items-center gap-4 border-b border-border bg-white px-4 md:px-6">
                {sender ? (
                    <div className="flex min-w-0 items-center gap-2.5">
                        <AvatarInitials
                            initials={sender.organization_initials}
                            tone="organization"
                            size="md"
                        />
                        <span className="min-w-0">
                            <span className="block truncate text-[13.5px] font-semibold">
                                {sender.organization_name}
                            </span>
                            <span className="block text-[11.5px] text-muted-foreground">
                                solicita sua assinatura
                            </span>
                        </span>
                    </div>
                ) : (
                    <AppLogo height={24} />
                )}
                {step !== null && (
                    <div className="hidden flex-1 justify-center md:flex">
                        <Stepper steps={steps} current={step} variant="pills" />
                    </div>
                )}
                <div className="ml-auto flex items-center gap-1.5 text-[11.5px] text-muted-foreground">
                    <span>via</span>
                    <AppLogo height={18} />
                </div>
            </header>
            {step !== null && (
                <div className="flex justify-center border-b border-border bg-white px-4 py-2 md:hidden">
                    <Stepper steps={steps} current={step} variant="pills" />
                </div>
            )}
            <main className="mx-auto flex w-full max-w-[1200px] flex-1 flex-col gap-4 p-4 md:p-6">
                {children}
            </main>
            <footer className="px-6 py-4 text-center text-[11.5px] text-muted-foreground">
                Documento processado pela AssinaVelox · Aceite eletrônico com trilha de auditoria
            </footer>
            <FlashToaster />
        </div>
    );
}
