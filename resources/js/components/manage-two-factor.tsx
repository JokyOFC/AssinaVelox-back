import { Form } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import Heading from '@/components/heading';
import TwoFactorRecoveryCodes from '@/components/two-factor-recovery-codes';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import { disable, enable } from '@/routes/two-factor';

export type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

/** Card "Autenticação em duas etapas" (TOTP via Fortify). */
export default function ManageTwoFactor(props: Props) {
    const requiresConfirmation = props.requiresConfirmation ?? false;
    const twoFactorEnabled = props.twoFactorEnabled ?? false;

    const {
        qrCodeSvg,
        hasSetupData,
        manualSetupKey,
        clearSetupData,
        clearTwoFactorAuthData,
        fetchSetupData,
        recoveryCodesList,
        fetchRecoveryCodes,
        errors,
    } = useTwoFactorAuth();
    const [showSetupModal, setShowSetupModal] = useState(false);
    const prevTwoFactorEnabled = useRef(twoFactorEnabled);

    useEffect(() => {
        if (prevTwoFactorEnabled.current && !twoFactorEnabled) {
            clearTwoFactorAuthData();
        }

        prevTwoFactorEnabled.current = twoFactorEnabled;
    }, [twoFactorEnabled, clearTwoFactorAuthData]);

    if (!(props.canManageTwoFactor ?? false)) {
        return null;
    }

    return (
        <div id="2fa" className="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 shadow-card">
            <Heading
                variant="small"
                title="Autenticação em duas etapas"
                description="Um código temporário do seu aplicativo autenticador é exigido a cada login."
                action={
                    <Badge variant={twoFactorEnabled ? 'success' : 'neutral'} dot>
                        {twoFactorEnabled ? 'Ativa' : 'Inativa'}
                    </Badge>
                }
            />

            {twoFactorEnabled ? (
                <div className="flex flex-col gap-4">
                    <p className="text-[13px] leading-[1.55] text-text-secondary">
                        Ao entrar, você informará um código gerado por um aplicativo compatível com TOTP (Google
                        Authenticator, Authy, 1Password etc.).
                    </p>
                    <TwoFactorRecoveryCodes recoveryCodesList={recoveryCodesList} fetchRecoveryCodes={fetchRecoveryCodes} errors={errors} />
                    <Form {...disable.form()}>
                        {({ processing }) => (
                            <Button variant="destructive" size="sm" type="submit" disabled={processing}>
                                Desativar 2FA
                            </Button>
                        )}
                    </Form>
                </div>
            ) : (
                <div className="flex flex-col items-start gap-4">
                    <p className="text-[13px] leading-[1.55] text-text-secondary">
                        Ao ativar, você precisará de um código do aplicativo autenticador além da senha. Recomendado
                        para todos os usuários; obrigatório quando a organização exige.
                    </p>
                    {hasSetupData ? (
                        <Button onClick={() => setShowSetupModal(true)}>
                            <ShieldCheck />
                            Continuar configuração
                        </Button>
                    ) : (
                        <Form {...enable.form()} onSuccess={() => setShowSetupModal(true)}>
                            {({ processing }) => (
                                <Button type="submit" disabled={processing}>
                                    <ShieldCheck />
                                    Ativar 2FA
                                </Button>
                            )}
                        </Form>
                    )}
                </div>
            )}

            <TwoFactorSetupModal
                isOpen={showSetupModal}
                onClose={() => setShowSetupModal(false)}
                requiresConfirmation={requiresConfirmation}
                twoFactorEnabled={twoFactorEnabled}
                qrCodeSvg={qrCodeSvg}
                manualSetupKey={manualSetupKey}
                clearSetupData={clearSetupData}
                fetchSetupData={fetchSetupData}
                errors={errors}
            />
        </div>
    );
}
