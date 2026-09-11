import { router } from '@inertiajs/react';
import {
    KeyRound,
    Mail,
    MessageCircle,
    MessageSquare,
    ShieldAlert,
} from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';
import type { PresenceOtp } from '@/components/in-person/types';
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
import { plural } from '@/lib/format';
import { channelPhraseLabels } from '@/lib/labels';
import type { SignerAuth } from '@/types/models';

const CHANNEL_ICONS = {
    email: Mail,
    sms: MessageSquare,
    whatsapp: MessageCircle,
} as const;

function secondsUntil(until: string | null): number {
    if (!until) {
        return 0;
    }

    const diff = new Date(until).getTime() - Date.now();

    return diff > 0 ? Math.ceil(diff / 1000) : 0;
}

function useCountdown(until: string | null): number {
    const [seconds, setSeconds] = useState(() => secondsUntil(until));

    useEffect(() => {
        setSeconds(secondsUntil(until));

        if (!until) {
            return;
        }

        const timer = window.setInterval(() => {
            setSeconds(secondsUntil(until));
        }, 1000);

        return () => window.clearInterval(timer);
    }, [until]);

    return seconds;
}

/**
 * Código de uso único do PRÓPRIO participante (presencial) ou da pessoa dona
 * do lote. Mesmo comportamento do cartão do fluxo individual, com as rotas
 * recebidas por props: o código sai pelo canal da pessoa (e-mail por padrão;
 * SMS/WhatsApp quando o remetente escolheu) e prova a posse do canal — não é
 * mostrado a quem conduz a sessão.
 */
export function ParticipantCodeCard({
    heading,
    intro,
    auth = null,
    emailMasked,
    otp,
    sendUrl,
    verifyUrl,
    errors,
    codeLength = 6,
    notice,
    privacyUrl,
    children,
}: {
    heading: string;
    intro?: ReactNode;
    auth?: SignerAuth | null;
    emailMasked: string;
    otp: PresenceOtp | null;
    sendUrl: string;
    verifyUrl: string;
    errors: Record<string, string>;
    codeLength?: number;
    notice: PrivacyNoticeContent;
    privacyUrl: string;
    children?: ReactNode;
}) {
    const channel = auth?.channel ?? 'email';
    const phrase = channelPhraseLabels[channel];
    const destination = auth?.destination || emailMasked;
    const ChannelIcon = CHANNEL_ICONS[channel];
    const byChannel = channel === 'email' ? '' : ` por ${phrase}`;
    const unavailable = auth !== null && !auth.available;
    const requested = otp?.sent_at != null && (otp.attempts_left ?? 0) > 0;

    const [sending, setSending] = useState(false);
    const [verifying, setVerifying] = useState(false);
    const [code, setCode] = useState('');
    const countdown = useCountdown(otp?.resend_available_at ?? null);

    useEffect(() => {
        if (errors.code) {
            setCode('');
        }
    }, [errors.code]);

    const send = () => {
        setSending(true);
        router.post(
            sendUrl,
            {},
            { preserveScroll: true, onFinish: () => setSending(false) },
        );
    };

    const verify = (value: string) => {
        if (value.length !== codeLength || verifying) {
            return;
        }

        setVerifying(true);
        router.post(
            verifyUrl,
            { code: value },
            {
                preserveScroll: true,
                onFinish: () => setVerifying(false),
            },
        );
    };

    return (
        <div className="border-border bg-card shadow-card flex flex-col gap-4 rounded-[14px] border p-5 sm:p-[22px]">
            <div>
                <h1 className="text-[20px] leading-[1.25] font-bold tracking-[-.01em]">
                    {heading}
                </h1>
                {intro && (
                    <div className="text-text-secondary mt-1.5 text-[13.5px] leading-[1.55]">
                        {intro}
                    </div>
                )}
            </div>

            {unavailable ? (
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
                </div>
            ) : !requested ? (
                <>
                    <div className="border-border bg-sidebar flex flex-col gap-1.5 rounded-[10px] border p-3.5 text-[13px]">
                        <span className="flex items-center gap-2.5">
                            <ChannelIcon className="text-primary size-4 shrink-0" />
                            <span>
                                Enviaremos um código de {codeLength} dígitos
                                {byChannel} para <b>{destination}</b>.
                            </span>
                            {auth?.simulated && (
                                <Badge variant="warning">simulado</Badge>
                            )}
                        </span>
                        {auth?.simulated && auth.notice && (
                            <span className="text-warning text-[12px]">
                                {auth.notice}
                            </span>
                        )}
                    </div>
                    <Button
                        type="button"
                        size="xl"
                        onClick={send}
                        disabled={sending || countdown > 0}
                    >
                        {sending ? (
                            <Spinner className="size-4" />
                        ) : (
                            <KeyRound className="size-4" />
                        )}
                        {countdown > 0
                            ? `Aguarde ${countdown}s`
                            : `Receber código por ${phrase}`}
                    </Button>
                </>
            ) : (
                <div className="border-border bg-sidebar flex flex-col gap-3 rounded-[10px] border p-3.5">
                    <div className="flex flex-wrap items-center gap-2.5 text-[13px]">
                        <ChannelIcon className="text-primary size-4 shrink-0" />
                        <span>
                            Enviamos um código{byChannel} para{' '}
                            <b>{destination}</b>
                        </span>
                        {auth?.simulated && (
                            <Badge variant="warning">simulado</Badge>
                        )}
                    </div>

                    <InputOTP
                        maxLength={codeLength}
                        value={code}
                        onChange={setCode}
                        onComplete={verify}
                        inputMode="numeric"
                        autoFocus
                        disabled={verifying}
                        aria-label="Código de confirmação"
                    >
                        <InputOTPGroup>
                            {Array.from({ length: codeLength }, (_, index) => (
                                <InputOTPSlot key={index} index={index} />
                            ))}
                        </InputOTPGroup>
                    </InputOTP>

                    <div className="flex flex-wrap items-center justify-between gap-2 text-[12.5px]">
                        <span className="text-muted-foreground">
                            {plural(
                                otp?.attempts_left ?? 0,
                                'tentativa restante',
                                'tentativas restantes',
                            )}
                        </span>
                        <Button
                            type="button"
                            variant="link"
                            size="xxs"
                            onClick={send}
                            disabled={sending || countdown > 0}
                        >
                            {countdown > 0
                                ? `Reenviar em ${countdown}s`
                                : 'Reenviar código'}
                        </Button>
                    </div>

                    <Button
                        type="button"
                        onClick={() => verify(code)}
                        disabled={code.length !== codeLength || verifying}
                    >
                        {verifying && <Spinner className="size-4" />}
                        Confirmar código
                    </Button>
                </div>
            )}

            {(errors.otp || errors.code) && (
                <p role="alert" className="text-danger text-[12.5px]">
                    {errors.otp ?? errors.code}
                </p>
            )}

            <PrivacyNotice notice={notice} privacyUrl={privacyUrl} />

            {children}
        </div>
    );
}
