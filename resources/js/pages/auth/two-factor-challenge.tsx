import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Spinner } from '@/components/ui/spinner';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';
import { store } from '@/routes/two-factor/login';

/** Desafio 2FA (ROUTES §2.3): código de 6 dígitos ou código de recuperação. */
export default function TwoFactorChallenge() {
    const [showRecoveryInput, setShowRecoveryInput] = useState(false);
    const [code, setCode] = useState('');

    setLayoutProps(
        showRecoveryInput
            ? {
                  title: 'Código de recuperação',
                  description:
                      'Confirme o acesso à sua conta informando um dos códigos de recuperação de emergência.',
              }
            : {
                  title: 'Autenticação em duas etapas',
                  description:
                      'Informe o código de 6 dígitos gerado pelo seu aplicativo autenticador.',
              },
    );

    const toggleRecoveryMode = (clearErrors: () => void): void => {
        setShowRecoveryInput(!showRecoveryInput);
        clearErrors();
        setCode('');
    };

    return (
        <>
            <Head title="Autenticação em duas etapas" />

            <Form
                {...store.form()}
                className="flex flex-col gap-4"
                resetOnError
                resetOnSuccess={!showRecoveryInput}
                options={{ viewTransition: true }}
            >
                {({ errors, processing, clearErrors }) => (
                    <>
                        {showRecoveryInput ? (
                            <div className="grid gap-1.5">
                                <Input
                                    name="recovery_code"
                                    type="text"
                                    placeholder="Digite o código de recuperação"
                                    autoFocus
                                    required
                                    className="h-10 font-mono"
                                    aria-invalid={!!errors.recovery_code}
                                />
                                <InputError message={errors.recovery_code} />
                            </div>
                        ) : (
                            <div className="border-border bg-sidebar flex flex-col items-center gap-3 rounded-[10px] border p-3.5">
                                <InputOTP
                                    name="code"
                                    maxLength={OTP_MAX_LENGTH}
                                    value={code}
                                    onChange={(value) => setCode(value)}
                                    disabled={processing}
                                    pattern={REGEXP_ONLY_DIGITS}
                                    autoFocus
                                    containerClassName="w-full"
                                >
                                    <InputOTPGroup className="w-full gap-2">
                                        {Array.from(
                                            { length: OTP_MAX_LENGTH },
                                            (_, index) => (
                                                <InputOTPSlot
                                                    key={index}
                                                    index={index}
                                                    className="border-input tabular h-12 flex-1 rounded-lg border bg-white text-[20px] font-bold first:rounded-l-lg last:rounded-r-lg"
                                                />
                                            ),
                                        )}
                                    </InputOTPGroup>
                                </InputOTP>
                                <InputError message={errors.code} />
                                <span className="text-muted-foreground text-[12px]">
                                    O código muda a cada 30 segundos.
                                </span>
                            </div>
                        )}

                        <Button
                            type="submit"
                            size="lg"
                            className="w-full"
                            disabled={
                                processing ||
                                (!showRecoveryInput &&
                                    code.length < OTP_MAX_LENGTH)
                            }
                        >
                            {processing && <Spinner />}
                            Continuar
                        </Button>

                        <p className="text-text-secondary text-center text-[13.5px]">
                            ou{' '}
                            <button
                                type="button"
                                className="text-primary font-semibold hover:underline"
                                onClick={() => toggleRecoveryMode(clearErrors)}
                            >
                                {showRecoveryInput
                                    ? 'use o código do aplicativo autenticador'
                                    : 'use um código de recuperação'}
                            </button>
                        </p>
                    </>
                )}
            </Form>
        </>
    );
}
