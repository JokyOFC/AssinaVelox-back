import { router } from '@inertiajs/react';
import { KeyRound, ShieldAlert } from 'lucide-react';
import { type FormEvent, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { plural } from '@/lib/format';
import type { SignerAuth } from '@/types/models';

/**
 * PIN combinado pelo remetente com o participante (Fase 2 §2.9), depois do
 * código, no dispositivo presencial. O PIN é digitado pela própria pessoa; o
 * campo é limpo a cada tentativa e nunca vai para o estado da página.
 */
export function ParticipantPinCard({
    pin,
    verifyUrl,
    error,
}: {
    pin: NonNullable<SignerAuth['pin']>;
    verifyUrl: string;
    error?: string;
}) {
    const [value, setValue] = useState('');
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (error) {
            setValue('');
        }
    }, [error]);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (value.length < pin.min_length || processing) {
            return;
        }

        setProcessing(true);
        router.post(
            verifyUrl,
            { pin: value },
            {
                preserveScroll: true,
                onFinish: () => {
                    setProcessing(false);
                    setValue('');
                },
            },
        );
    };

    const blocked = pin.blocked;

    return (
        <form
            onSubmit={submit}
            className="border-border bg-card shadow-card flex flex-col gap-4 rounded-[14px] border p-5 sm:p-[22px]"
        >
            <div>
                <h1 className="text-[20px] leading-[1.25] font-bold tracking-[-.01em]">
                    Informe o PIN
                </h1>
                <p className="text-text-secondary mt-1.5 text-[13.5px] leading-[1.55]">
                    Código confirmado. Quem enviou o documento combinou um PIN
                    com você. Digite-o sem mostrar a ninguém.
                </p>
            </div>

            {blocked ? (
                <p
                    role="alert"
                    className="border-danger-border bg-danger-bg text-danger flex items-center gap-2 rounded-[10px] border p-3 text-[13px]"
                >
                    <ShieldAlert className="size-4 shrink-0" />O PIN foi
                    bloqueado depois de tentativas demais. Fale com quem enviou
                    o documento.
                </p>
            ) : (
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="in-person-pin">PIN</Label>
                    <Input
                        id="in-person-pin"
                        type="password"
                        inputMode="numeric"
                        autoComplete="off"
                        autoFocus
                        maxLength={pin.max_length}
                        value={value}
                        onChange={(event) =>
                            setValue(event.target.value.replace(/\D+/g, ''))
                        }
                    />
                    <span className="text-muted-foreground text-[12px]">
                        {pin.min_length === pin.max_length
                            ? `${pin.min_length} dígitos`
                            : `De ${pin.min_length} a ${pin.max_length} dígitos`}{' '}
                        ·{' '}
                        {plural(
                            pin.attempts_left,
                            'tentativa restante',
                            'tentativas restantes',
                        )}
                    </span>
                </div>
            )}

            {error && (
                <p role="alert" className="text-danger text-[12.5px]">
                    {error}
                </p>
            )}

            {!blocked && (
                <Button
                    type="submit"
                    size="xl"
                    disabled={value.length < pin.min_length || processing}
                >
                    {processing ? (
                        <Spinner className="size-4" />
                    ) : (
                        <KeyRound className="size-4" />
                    )}
                    Confirmar PIN
                </Button>
            )}
        </form>
    );
}
