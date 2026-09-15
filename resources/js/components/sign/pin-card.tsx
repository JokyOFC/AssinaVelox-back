import { router } from '@inertiajs/react';
import { KeyRound, ShieldAlert } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/i18n';
import { verify as pinVerify } from '@/routes/sign/pin';
import type { SignerAuth } from '@/types/models';

/**
 * Etapa do PIN do remetente (Fase 2 §2.9), depois do código confirmado.
 *
 * O PIN é um segredo que quem enviou combinou com o participante por fora do AssinaVelox:
 * o sistema nunca o envia. O campo é limpo a cada tentativa e nunca vai para o estado da
 * página. Erros chegam em `errors.pin` (incorreto, bloqueio temporário, bloqueado, "confirme
 * primeiro o código").
 */
export function PinCard({
    token,
    pin,
    senderName,
    error,
}: {
    token: string;
    pin: NonNullable<SignerAuth['pin']>;
    senderName: string;
    error?: string;
}) {
    const { t, tp, rich } = useI18n();
    const [value, setValue] = useState('');
    const [processing, setProcessing] = useState(false);

    // Um PIN errado deve ser digitado de novo desde o começo.
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
            pinVerify(token).url,
            { pin: value },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => {
                    setProcessing(false);
                    setValue('');
                },
            },
        );
    };

    return (
        <form
            onSubmit={submit}
            className="border-border bg-sidebar flex flex-col gap-2.5 rounded-[10px] border p-3.5"
        >
            <div className="flex items-start gap-2.5 text-[13px]">
                <KeyRound className="text-primary mt-0.5 size-4 shrink-0" />
                <span>
                    {rich('pin.intro', {
                        pin: <b>PIN</b>,
                        sender: senderName,
                    })}
                </span>
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="sender-pin" className="text-[12.5px]">
                    {t('pin.label', {
                        min: pin.min_length,
                        max: pin.max_length,
                    })}
                </Label>
                <Input
                    id="sender-pin"
                    type="password"
                    inputMode="numeric"
                    autoComplete="off"
                    autoFocus
                    className="tabular h-12 bg-white text-center font-mono text-[20px] tracking-[.4em]"
                    value={value}
                    maxLength={pin.max_length}
                    aria-invalid={Boolean(error)}
                    onChange={(event) =>
                        setValue(
                            event.target.value
                                .replace(/\D+/g, '')
                                .slice(0, pin.max_length),
                        )
                    }
                />
            </div>

            {error && (
                <p
                    role="alert"
                    className="text-danger flex items-start gap-1.5 text-[12.5px]"
                >
                    <ShieldAlert className="mt-px size-3.5 shrink-0" />
                    {error}
                </p>
            )}
            {!error && pin.attempts_left < 3 && (
                <p className="text-warning text-[12.5px]">
                    {tp('pin.attempts_left', pin.attempts_left)}
                </p>
            )}

            <Button
                type="submit"
                size="xl"
                className="mt-1"
                disabled={value.length < pin.min_length || processing}
            >
                {processing && <Spinner className="size-4" />}
                {t('pin.confirm')}
            </Button>
            <p className="text-muted-foreground text-[12px] leading-[1.5]">
                {t('pin.help', { sender: senderName })}
            </p>
        </form>
    );
}
