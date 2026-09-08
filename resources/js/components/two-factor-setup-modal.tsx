import { Form } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { Check, Copy, ScanLine } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import AlertError from '@/components/alert-error';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { InputOTP, InputOTPGroup, InputOTPSlot } from '@/components/ui/input-otp';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';
import { confirm } from '@/routes/two-factor';

function TwoFactorSetupStep({
    qrCodeSvg,
    manualSetupKey,
    buttonText,
    onNextStep,
    errors,
}: {
    qrCodeSvg: string | null;
    manualSetupKey: string | null;
    buttonText: string;
    onNextStep: () => void;
    errors: string[];
}) {
    const [copiedText, copy] = useClipboard();
    const IconComponent = copiedText === manualSetupKey ? Check : Copy;

    if (errors?.length) {
        return <AlertError errors={errors} title="Não foi possível carregar a configuração." />;
    }

    return (
        <>
            <div className="mx-auto flex max-w-md overflow-hidden">
                <div className="mx-auto aspect-square w-56 rounded-xl border border-border bg-white p-3">
                    {qrCodeSvg ? (
                        <div className="aspect-square w-full [&_svg]:size-full" dangerouslySetInnerHTML={{ __html: qrCodeSvg }} />
                    ) : (
                        <div className="flex h-full items-center justify-center">
                            <Spinner />
                        </div>
                    )}
                </div>
            </div>

            <Button className="w-full" onClick={onNextStep}>
                {buttonText}
            </Button>

            <div className="flex w-full items-center gap-3 text-[12px] text-muted-foreground">
                <span className="h-px flex-1 bg-border" />
                ou digite a chave manualmente
                <span className="h-px flex-1 bg-border" />
            </div>

            <div className="flex w-full items-stretch overflow-hidden rounded-lg border border-border">
                {!manualSetupKey ? (
                    <div className="flex h-10 w-full items-center justify-center bg-muted">
                        <Spinner />
                    </div>
                ) : (
                    <>
                        <input type="text" readOnly value={manualSetupKey} className="h-10 w-full bg-sidebar px-3 font-mono text-[13px] text-foreground outline-none" />
                        <button type="button" onClick={() => copy(manualSetupKey)} className="border-l border-border px-3 text-muted-foreground hover:bg-accent hover:text-foreground" aria-label="Copiar chave">
                            <IconComponent className="size-4" />
                        </button>
                    </>
                )}
            </div>
        </>
    );
}

function TwoFactorVerificationStep({ onClose, onBack }: { onClose: () => void; onBack: () => void }) {
    const [code, setCode] = useState('');
    const pinInputContainerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const timer = window.setTimeout(() => pinInputContainerRef.current?.querySelector('input')?.focus(), 0);

        return () => window.clearTimeout(timer);
    }, []);

    return (
        <Form {...confirm.form()} onSuccess={() => onClose()} resetOnError resetOnSuccess>
            {({ processing, errors }: { processing: boolean; errors?: { confirmTwoFactorAuthentication?: { code?: string } } }) => (
                <div ref={pinInputContainerRef} className="flex w-full flex-col gap-3">
                    <div className="flex w-full flex-col items-center gap-3 rounded-[10px] border border-border bg-sidebar p-3.5">
                        <InputOTP id="otp" name="code" maxLength={OTP_MAX_LENGTH} onChange={setCode} disabled={processing} pattern={REGEXP_ONLY_DIGITS} autoFocus containerClassName="w-full">
                            <InputOTPGroup className="w-full gap-2">
                                {Array.from({ length: OTP_MAX_LENGTH }, (_, index) => (
                                    <InputOTPSlot key={index} index={index} className="h-12 flex-1 rounded-lg border border-input bg-white text-[20px] font-bold tabular first:rounded-l-lg last:rounded-r-lg" />
                                ))}
                            </InputOTPGroup>
                        </InputOTP>
                        <InputError message={errors?.confirmTwoFactorAuthentication?.code} />
                    </div>

                    <div className="flex w-full gap-2">
                        <Button type="button" variant="outline" className="flex-1" onClick={onBack} disabled={processing}>
                            Voltar
                        </Button>
                        <Button type="submit" className="flex-1" disabled={processing || code.length < OTP_MAX_LENGTH}>
                            {processing && <Spinner />}
                            Confirmar
                        </Button>
                    </div>
                </div>
            )}
        </Form>
    );
}

type Props = {
    isOpen: boolean;
    onClose: () => void;
    requiresConfirmation: boolean;
    twoFactorEnabled: boolean;
    qrCodeSvg: string | null;
    manualSetupKey: string | null;
    clearSetupData: () => void;
    fetchSetupData: () => Promise<void>;
    errors: string[];
};

export default function TwoFactorSetupModal({ isOpen, onClose, requiresConfirmation, twoFactorEnabled, qrCodeSvg, manualSetupKey, clearSetupData, fetchSetupData, errors }: Props) {
    const [showVerificationStep, setShowVerificationStep] = useState(false);

    const modalConfig = useMemo(() => {
        if (twoFactorEnabled) {
            return {
                title: 'Autenticação em duas etapas ativada',
                description: 'Escaneie o QR code ou digite a chave no seu aplicativo autenticador.',
                buttonText: 'Fechar',
            };
        }

        if (showVerificationStep) {
            return {
                title: 'Confirme o código',
                description: 'Digite o código de 6 dígitos exibido no aplicativo autenticador.',
                buttonText: 'Continuar',
            };
        }

        return {
            title: 'Ativar autenticação em duas etapas',
            description: 'Escaneie o QR code ou digite a chave no seu aplicativo autenticador para concluir.',
            buttonText: 'Continuar',
        };
    }, [twoFactorEnabled, showVerificationStep]);

    const resetModalState = useCallback(() => {
        if (twoFactorEnabled) {
            clearSetupData();
        }

        setShowVerificationStep(false);
    }, [clearSetupData, twoFactorEnabled]);

    const handleClose = useCallback(() => {
        resetModalState();
        onClose();
    }, [onClose, resetModalState]);

    const handleModalNextStep = useCallback(() => {
        if (requiresConfirmation) {
            setShowVerificationStep(true);

            return;
        }

        clearSetupData();
        handleClose();
    }, [requiresConfirmation, clearSetupData, handleClose]);

    const fetchSetupDataRef = useRef(fetchSetupData);

    useEffect(() => {
        fetchSetupDataRef.current = fetchSetupData;
    }, [fetchSetupData]);

    useEffect(() => {
        if (isOpen && !qrCodeSvg) {
            void fetchSetupDataRef.current();
        }
    }, [isOpen, qrCodeSvg]);

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && handleClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader className="items-center text-center">
                    <span className="mb-1 flex size-11 items-center justify-center rounded-xl bg-primary-soft text-primary">
                        <ScanLine className="size-5" />
                    </span>
                    <DialogTitle>{modalConfig.title}</DialogTitle>
                    <DialogDescription className="text-center">{modalConfig.description}</DialogDescription>
                </DialogHeader>

                <div className="flex flex-col items-center gap-4">
                    {showVerificationStep ? (
                        <TwoFactorVerificationStep onClose={handleClose} onBack={() => setShowVerificationStep(false)} />
                    ) : (
                        <TwoFactorSetupStep qrCodeSvg={qrCodeSvg} manualSetupKey={manualSetupKey} buttonText={modalConfig.buttonText} onNextStep={handleModalNextStep} errors={errors} />
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
