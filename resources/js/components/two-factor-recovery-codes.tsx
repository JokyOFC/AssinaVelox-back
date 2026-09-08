import { Form } from '@inertiajs/react';
import { Eye, EyeOff, LockKeyhole, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import AlertError from '@/components/alert-error';
import { Button } from '@/components/ui/button';
import { regenerateRecoveryCodes } from '@/routes/two-factor';

type Props = {
    recoveryCodesList: string[];
    fetchRecoveryCodes: () => Promise<void>;
    errors: string[];
};

/** Códigos de recuperação do 2FA (mostrar/ocultar, regenerar). */
export default function TwoFactorRecoveryCodes({
    recoveryCodesList,
    fetchRecoveryCodes,
    errors,
}: Props) {
    const [codesAreVisible, setCodesAreVisible] = useState(false);
    const codesSectionRef = useRef<HTMLDivElement | null>(null);
    const canRegenerateCodes = recoveryCodesList.length > 0 && codesAreVisible;

    const toggleCodesVisibility = useCallback(async () => {
        if (!codesAreVisible && !recoveryCodesList.length) {
            await fetchRecoveryCodes();
        }

        setCodesAreVisible(!codesAreVisible);

        if (!codesAreVisible) {
            window.setTimeout(() =>
                codesSectionRef.current?.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest',
                }),
            );
        }
    }, [codesAreVisible, recoveryCodesList.length, fetchRecoveryCodes]);

    useEffect(() => {
        if (!recoveryCodesList.length) {
            void fetchRecoveryCodes();
        }
    }, [recoveryCodesList.length, fetchRecoveryCodes]);

    const RecoveryCodeIcon = codesAreVisible ? EyeOff : Eye;

    return (
        <div className="border-border rounded-[10px] border p-4">
            <div className="flex items-start gap-3">
                <span className="bg-accent-subtle text-primary flex size-9 shrink-0 items-center justify-center rounded-lg">
                    <LockKeyhole className="size-4" />
                </span>
                <div className="min-w-0 flex-1">
                    <div className="text-[13.5px] font-semibold">
                        Códigos de recuperação
                    </div>
                    <p className="text-muted-foreground mt-0.5 text-[12.5px] leading-[1.5]">
                        Permitem recuperar o acesso se você perder o dispositivo
                        autenticador. Guarde-os em um gerenciador de senhas.
                    </p>
                </div>
            </div>

            <div className="mt-3 flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="xs"
                    onClick={toggleCodesVisibility}
                    aria-expanded={codesAreVisible}
                    aria-controls="recovery-codes-section"
                >
                    <RecoveryCodeIcon className="size-3.5" />
                    {codesAreVisible
                        ? 'Ocultar códigos'
                        : 'Ver códigos de recuperação'}
                </Button>

                {canRegenerateCodes && (
                    <Form
                        {...regenerateRecoveryCodes.form()}
                        options={{ preserveScroll: true }}
                        onSuccess={fetchRecoveryCodes}
                    >
                        {({ processing }) => (
                            <Button
                                variant="ghost"
                                size="xs"
                                type="submit"
                                disabled={processing}
                            >
                                <RefreshCw className="size-3.5" /> Gerar novos
                                códigos
                            </Button>
                        )}
                    </Form>
                )}
            </div>

            <div
                id="recovery-codes-section"
                className={`overflow-hidden transition-all duration-300 ${codesAreVisible ? 'mt-3 h-auto opacity-100' : 'h-0 opacity-0'}`}
                aria-hidden={!codesAreVisible}
            >
                {errors?.length ? (
                    <AlertError
                        errors={errors}
                        title="Não foi possível carregar os códigos."
                    />
                ) : (
                    <>
                        <div
                            ref={codesSectionRef}
                            className="bg-sidebar grid gap-1 rounded-lg p-4 font-mono text-[13px] sm:grid-cols-2"
                            role="list"
                            aria-label="Códigos de recuperação"
                        >
                            {recoveryCodesList.length
                                ? recoveryCodesList.map((code) => (
                                      <div
                                          key={code}
                                          role="listitem"
                                          className="select-text"
                                      >
                                          {code}
                                      </div>
                                  ))
                                : Array.from({ length: 8 }, (_, index) => (
                                      <div
                                          key={index}
                                          className="bg-muted h-4 animate-pulse rounded"
                                          aria-hidden
                                      />
                                  ))}
                        </div>
                        <p className="text-muted-foreground mt-2 text-[12px]">
                            Cada código pode ser usado uma única vez e é
                            descartado após o uso. Gere novos códigos quando
                            precisar.
                        </p>
                    </>
                )}
            </div>
        </div>
    );
}
