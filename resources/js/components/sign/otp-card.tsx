import { router, useForm } from '@inertiajs/react';
import { KeyRound, Mail, ShieldAlert } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
    PrivacyNotice,
    type PrivacyNoticeContent,
} from '@/components/sign/privacy-notice';
import { Button } from '@/components/ui/button';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Spinner } from '@/components/ui/spinner';
import { formatDateMedium, plural } from '@/lib/format';
import { send as otpSend, verify as otpVerify } from '@/routes/sign/otp';

export interface OtpState {
    sent_at: string | null;
    expires_at: string | null;
    resend_available_at: string | null;
    attempts_left: number;
}

export interface OtpCardProps {
    token: string;
    firstName: string;
    emailMasked: string;
    senderName: string;
    organizationName: string;
    sentAt: string | null;
    expiresAt: string | null;
    otp: OtpState | null;
    notice: PrivacyNoticeContent;
    termsUrl: string;
    privacyUrl: string;
    /** `errors.code` / `errors.otp` da resposta 422. */
    errors: Record<string, string>;
    /** `limits.otp_length` e `limits.otp_ttl_minutes` das props. */
    codeLength?: number;
    ttlMinutes?: number;
}

/** Segundos que faltam até `until`; 0 quando já passou. */
function secondsUntil(until: string | null): number {
    if (!until) {
        return 0;
    }

    const diff = new Date(until).getTime() - Date.now();

    return diff > 0 ? Math.ceil(diff / 1000) : 0;
}

/**
 * Etapa "Confirmar identidade" (arquitetura §4.1–4.3; DESIGN §4.21).
 *
 * O código **não** é enviado sozinho: o signatário pede. É o que o aviso de
 * privacidade promete ("Não solicitar o código não gera nenhum aceite") e o
 * que a arquitetura §4.1 descreve ("botão Receber código").
 */
export function OtpCard({
    token,
    firstName,
    emailMasked,
    senderName,
    organizationName,
    sentAt,
    expiresAt,
    otp,
    notice,
    termsUrl,
    privacyUrl,
    errors,
    codeLength = 6,
    ttlMinutes = 10,
}: OtpCardProps) {
    const CODE_LENGTH = codeLength;
    const requested = otp?.sent_at != null;
    const [sending, setSending] = useState(false);
    const [countdown, setCountdown] = useState(() =>
        secondsUntil(otp?.resend_available_at ?? null),
    );
    const submittedCode = useRef<string | null>(null);

    const form = useForm({ code: '' });
    const { data, setData, post, processing, reset } = form;

    useEffect(() => {
        setCountdown(secondsUntil(otp?.resend_available_at ?? null));
    }, [otp?.resend_available_at]);

    useEffect(() => {
        if (countdown <= 0) {
            return;
        }

        const timer = window.setInterval(() => {
            setCountdown((value) => (value > 0 ? value - 1 : 0));
        }, 1000);

        return () => window.clearInterval(timer);
    }, [countdown]);

    // Um código errado deve poder ser reescrito: limpa o campo quando o
    // servidor devolve erro para não somar dígitos ao valor recusado.
    useEffect(() => {
        if (errors.code) {
            reset('code');
            submittedCode.current = null;
        }
    }, [errors.code, reset]);

    const sendCode = () => {
        setSending(true);
        router.post(
            otpSend(token).url,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSending(false),
            },
        );
    };

    const submit = (code: string) => {
        if (code.length !== CODE_LENGTH || submittedCode.current === code) {
            return;
        }

        submittedCode.current = code;
        post(otpVerify(token).url, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const deadline = useMemo(
        () => (expiresAt ? formatDateMedium(expiresAt) : null),
        [expiresAt],
    );

    return (
        <div className="border-border bg-card shadow-card flex flex-col gap-4 rounded-[14px] border p-5 sm:p-[22px]">
            <div>
                <p className="text-muted-foreground text-[11px] font-bold tracking-[.16em] uppercase">
                    Olá, {firstName}
                </p>
                <h1 className="mt-1.5 text-[20px] leading-[1.25] font-bold tracking-[-.01em]">
                    Confirme sua identidade para assinar
                </h1>
                <p className="text-text-secondary mt-2 text-[13.5px] leading-[1.55]">
                    {senderName} ({organizationName}) enviou este documento para
                    você
                    {sentAt ? ` em ${formatDateMedium(sentAt)}` : ''}.
                    {deadline && (
                        <>
                            {' '}
                            Prazo: <b className="text-foreground">{deadline}</b>
                            .
                        </>
                    )}
                </p>
            </div>

            <PrivacyNotice notice={notice} privacyUrl={privacyUrl} />

            {!requested ? (
                <>
                    <div className="border-border bg-sidebar flex items-center gap-2.5 rounded-[10px] border p-3.5 text-[13px]">
                        <Mail className="text-primary size-4 shrink-0" />
                        <span>
                            Enviaremos um código de {CODE_LENGTH} dígitos para{' '}
                            <b>{emailMasked}</b>.
                        </span>
                    </div>
                    <Button
                        type="button"
                        size="xl"
                        onClick={sendCode}
                        disabled={sending}
                    >
                        {sending ? (
                            <Spinner className="size-4" />
                        ) : (
                            <KeyRound className="size-4" />
                        )}
                        Receber código por e-mail
                    </Button>
                </>
            ) : (
                <div className="border-border bg-sidebar flex flex-col gap-2.5 rounded-[10px] border p-3.5">
                    <div className="flex items-center gap-2.5 text-[13px]">
                        <Mail className="text-primary size-4 shrink-0" />
                        <span>
                            Enviamos um código para <b>{emailMasked}</b>
                        </span>
                    </div>

                    <InputOTP
                        maxLength={CODE_LENGTH}
                        value={data.code}
                        inputMode="numeric"
                        autoFocus
                        aria-label={`Código de ${CODE_LENGTH} dígitos recebido por e-mail`}
                        onChange={(code) => {
                            setData('code', code);
                            submit(code);
                        }}
                        containerClassName="w-full"
                    >
                        <InputOTPGroup className="flex w-full gap-2">
                            {Array.from({ length: CODE_LENGTH }, (_, index) => (
                                <InputOTPSlot
                                    key={index}
                                    index={index}
                                    className="border-input tabular h-12 flex-1 rounded-lg border bg-white text-[20px] font-bold first:rounded-l-lg last:rounded-r-lg"
                                />
                            ))}
                        </InputOTPGroup>
                    </InputOTP>

                    <div className="text-muted-foreground flex flex-wrap items-center justify-between gap-2 text-[12px]">
                        <span className="tabular">
                            Código válido por {ttlMinutes} min
                        </span>
                        <button
                            type="button"
                            onClick={sendCode}
                            disabled={countdown > 0 || sending}
                            className="text-primary font-semibold disabled:opacity-60"
                        >
                            {countdown > 0
                                ? `Reenviar em ${countdown}s`
                                : 'Reenviar código'}
                        </button>
                    </div>

                    {errors.code && (
                        <p
                            role="alert"
                            className="text-danger flex items-start gap-1.5 text-[12.5px]"
                        >
                            <ShieldAlert className="mt-px size-3.5 shrink-0" />
                            {errors.code}
                        </p>
                    )}
                    {!errors.code && otp && otp.attempts_left < 3 && (
                        <p className="text-warning text-[12.5px]">
                            {otp.attempts_left === 0
                                ? 'Tentativas esgotadas. Peça um novo código.'
                                : `${plural(otp.attempts_left, 'tentativa restante', 'tentativas restantes')} antes de precisar de um novo código.`}
                        </p>
                    )}
                    {errors.otp && (
                        <p role="alert" className="text-danger text-[12.5px]">
                            {errors.otp}
                        </p>
                    )}

                    <Button
                        type="button"
                        size="xl"
                        className="mt-1"
                        disabled={
                            data.code.length !== CODE_LENGTH || processing
                        }
                        onClick={() => {
                            submittedCode.current = null;
                            submit(data.code);
                        }}
                    >
                        {processing && <Spinner className="size-4" />}
                        Confirmar código e continuar
                    </Button>
                </div>
            )}

            <p className="text-muted-foreground text-center text-[12px] leading-[1.5]">
                Ao continuar você concorda com os{' '}
                <a href={termsUrl} target="_blank" rel="noopener noreferrer">
                    termos de uso
                </a>
                . Seus dados são tratados conforme a LGPD.
            </p>
        </div>
    );
}
