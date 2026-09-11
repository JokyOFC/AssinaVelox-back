import { router, useForm } from '@inertiajs/react';
import {
    Ban,
    KeyRound,
    Mail,
    MessageCircle,
    MessageSquare,
    ShieldAlert,
    TimerReset,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { PinCard } from '@/components/sign/pin-card';
import {
    PrivacyNotice,
    type PrivacyNoticeContent,
} from '@/components/sign/privacy-notice';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Spinner } from '@/components/ui/spinner';
import { formatDateMedium, formatTime, plural } from '@/lib/format';
import { channelPhraseLabels } from '@/lib/labels';
import { send as otpSend, verify as otpVerify } from '@/routes/sign/otp';
import type { SignerAuth } from '@/types/models';

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
    /** `errors.code` / `errors.otp` / `errors.pin` da resposta 422. */
    errors: Record<string, string>;
    /** `limits.otp_length` e `limits.otp_ttl_minutes` das props. */
    codeLength?: number;
    ttlMinutes?: number;
    /**
     * Título da etapa. Fase 2 §2.4: o aprovador confirma "para aprovar" e o
     * visualizador "para ver o documento"; o padrão é o texto da Fase 1.
     */
    heading?: string;
    /**
     * Fase 2 §2.9 (`SignerAuthProps::for`): canal do código, destino mascarado, simulador,
     * disponibilidade e a etapa do PIN. Ausente = código por e-mail, como na Fase 1.
     */
    auth?: SignerAuth | null;
    /** Conteúdo extra logo abaixo do aviso de privacidade (ex.: fotos que serão pedidas). */
    extra?: React.ReactNode;
}

const CHANNEL_ICONS = {
    email: Mail,
    sms: MessageSquare,
    whatsapp: MessageCircle,
} as const;

/** Segundos que faltam até `until`; 0 quando já passou. */
function secondsUntil(until: string | null): number {
    if (!until) {
        return 0;
    }

    const diff = new Date(until).getTime() - Date.now();

    return diff > 0 ? Math.ceil(diff / 1000) : 0;
}

/** Contagem regressiva em segundos até `until`, atualizada a cada segundo. */
function useCountdown(until: string | null): number {
    const [seconds, setSeconds] = useState(() => secondsUntil(until));

    useEffect(() => {
        setSeconds(secondsUntil(until));
    }, [until]);

    useEffect(() => {
        if (seconds <= 0) {
            return;
        }

        const timer = window.setInterval(() => {
            setSeconds((value) => (value > 0 ? value - 1 : 0));
        }, 1000);

        return () => window.clearInterval(timer);
    }, [seconds]);

    return seconds;
}

function clock(seconds: number): string {
    const minutes = Math.floor(seconds / 60);

    return `${minutes}:${String(seconds % 60).padStart(2, '0')}`;
}

/**
 * Etapa "Confirmar identidade" (arquitetura §4.1–4.3; DESIGN §4.21).
 *
 * O código **não** é enviado sozinho: o signatário pede. É o que o aviso de
 * privacidade promete ("Não solicitar o código não gera nenhum aceite") e o
 * que a arquitetura §4.1 descreve ("botão Receber código").
 *
 * Fase 2 §2.9 (`auth`): o código pode sair por SMS ou WhatsApp, para o número mascarado.
 * Ele prova a posse do canal, não a identidade. Com PIN do remetente, depois do código vem
 * a etapa do PIN (`auth.step === 'pin'`). Sem `auth`, a tela é a da Fase 1.
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
    heading = 'Confirme sua identidade para assinar',
    auth = null,
    extra,
}: OtpCardProps) {
    const CODE_LENGTH = codeLength;
    const requested = otp?.sent_at != null;
    const [sending, setSending] = useState(false);
    const countdown = useCountdown(otp?.resend_available_at ?? null);
    const submittedCode = useRef<string | null>(null);

    const channel = auth?.channel ?? 'email';
    const phrase = channelPhraseLabels[channel];
    const destination = auth?.destination || emailMasked;
    const ChannelIcon = CHANNEL_ICONS[channel];
    const byChannel = channel === 'email' ? '' : ` por ${phrase}`;
    const unavailable = auth !== null && !auth.available;
    const pin = auth?.pin ?? null;
    const pinStep = auth?.step === 'pin' && pin !== null && pin.step_active;
    const pinBlocked = pin?.blocked === true;
    const lockSeconds = useCountdown(pin?.locked_until ?? null);
    const pinLocked = lockSeconds > 0;

    const form = useForm({ code: '' });
    const { data, setData, post, processing, reset } = form;

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

    const simulatedBadge = auth?.simulated ? (
        <Badge
            variant="warning"
            className="px-1.5 py-px text-[10.5px]"
            title="Ambiente de testes: a mensagem não chega ao celular."
        >
            simulado
        </Badge>
    ) : null;

    const simulatedNotice =
        auth?.simulated && auth.notice ? (
            <p className="text-warning text-[12px] leading-[1.5]">
                {auth.notice}
            </p>
        ) : null;

    const renderCodeStep = () => {
        if (unavailable) {
            return (
                <div
                    role="alert"
                    className="border-danger-border bg-danger-bg flex flex-col gap-1.5 rounded-[10px] border p-3.5 text-[13px] leading-[1.5]"
                >
                    <span className="text-danger flex items-center gap-2 font-semibold">
                        <ShieldAlert className="size-4 shrink-0" />
                        Não é possível enviar o código{byChannel} agora
                    </span>
                    {auth?.unavailable_reason && (
                        <span className="text-text-secondary">
                            {auth.unavailable_reason}
                        </span>
                    )}
                    <span className="text-text-secondary">
                        Fale com {senderName} ({organizationName}) para receber
                        o documento por outro canal.
                    </span>
                </div>
            );
        }

        if (!requested) {
            return (
                <>
                    <div className="border-border bg-sidebar flex flex-col gap-1.5 rounded-[10px] border p-3.5 text-[13px]">
                        <span className="flex items-center gap-2.5">
                            <ChannelIcon className="text-primary size-4 shrink-0" />
                            <span>
                                Enviaremos um código de {CODE_LENGTH} dígitos
                                {byChannel} para <b>{destination}</b>.
                            </span>
                            {simulatedBadge}
                        </span>
                        {simulatedNotice}
                    </div>
                    <Button
                        type="button"
                        size="xl"
                        onClick={sendCode}
                        disabled={sending || pinLocked}
                    >
                        {sending ? (
                            <Spinner className="size-4" />
                        ) : (
                            <KeyRound className="size-4" />
                        )}
                        Receber código por {phrase}
                    </Button>
                    {errors.otp && (
                        <p role="alert" className="text-danger text-[12.5px]">
                            {errors.otp}
                        </p>
                    )}
                </>
            );
        }

        return (
            <div className="border-border bg-sidebar flex flex-col gap-2.5 rounded-[10px] border p-3.5">
                <div className="flex flex-wrap items-center gap-2.5 text-[13px]">
                    <ChannelIcon className="text-primary size-4 shrink-0" />
                    <span>
                        Enviamos um código{byChannel} para <b>{destination}</b>
                    </span>
                    {simulatedBadge}
                </div>
                {simulatedNotice}

                <InputOTP
                    maxLength={CODE_LENGTH}
                    value={data.code}
                    inputMode="numeric"
                    autoFocus
                    disabled={pinLocked}
                    aria-label={`Código de ${CODE_LENGTH} dígitos recebido por ${phrase}`}
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
                        disabled={countdown > 0 || sending || pinLocked}
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
                        data.code.length !== CODE_LENGTH ||
                        processing ||
                        pinLocked
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
        );
    };

    return (
        <div className="border-border bg-card shadow-card flex flex-col gap-4 rounded-[14px] border p-5 sm:p-[22px]">
            <div>
                <p className="text-muted-foreground text-[11px] font-bold tracking-[.16em] uppercase">
                    Olá, {firstName}
                </p>
                <h1 className="mt-1.5 text-[20px] leading-[1.25] font-bold tracking-[-.01em]">
                    {heading}
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

            {extra}

            {pinBlocked ? (
                <div
                    role="alert"
                    className="border-danger-border bg-danger-bg flex flex-col gap-1.5 rounded-[10px] border p-3.5 text-[13px] leading-[1.5]"
                >
                    <span className="text-danger flex items-center gap-2 font-semibold">
                        <Ban className="size-4 shrink-0" />
                        PIN bloqueado
                    </span>
                    <span className="text-text-secondary">
                        O PIN foi bloqueado depois de várias tentativas
                        incorretas. Fale com {senderName} ({organizationName}):
                        só quem enviou o documento pode definir um PIN novo.
                    </span>
                    {errors.pin && (
                        <span className="text-danger">{errors.pin}</span>
                    )}
                </div>
            ) : pinStep && pin ? (
                <PinCard
                    token={token}
                    pin={pin}
                    senderName={senderName}
                    error={errors.pin}
                />
            ) : (
                <>
                    {pinLocked && (
                        <div
                            role="status"
                            className="border-warning-border bg-warning-bg text-warning flex flex-col gap-1 rounded-[10px] border p-3.5 text-[13px] leading-[1.5]"
                        >
                            <span className="flex items-center gap-2 font-semibold">
                                <TimerReset className="size-4 shrink-0" />
                                PIN bloqueado temporariamente
                            </span>
                            <span>
                                Depois de várias tentativas incorretas, o PIN
                                fica bloqueado até{' '}
                                {formatTime(pin?.locked_until ?? null)} (faltam{' '}
                                <span className="tabular">
                                    {clock(lockSeconds)}
                                </span>
                                ). Em seguida, peça um novo código e informe o
                                PIN de novo.
                            </span>
                        </div>
                    )}
                    {!pinLocked && errors.pin && (
                        <p role="alert" className="text-danger text-[12.5px]">
                            {errors.pin}
                        </p>
                    )}
                    {renderCodeStep()}
                    {pin?.required && !unavailable && (
                        <p className="text-muted-foreground flex items-start gap-1.5 text-[12px] leading-[1.5]">
                            <KeyRound className="mt-px size-3.5 shrink-0" />
                            Depois do código, você vai informar o PIN que{' '}
                            {senderName} combinou com você.
                        </p>
                    )}
                </>
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
