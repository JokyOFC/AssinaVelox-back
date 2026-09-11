import { Head, router, usePage } from '@inertiajs/react';
import { CheckCircle2, Lock, Tablet } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { KioskQueue } from '@/components/in-person/kiosk-queue';
import { KioskShell } from '@/components/in-person/kiosk-shell';
import { ParticipantAcceptance } from '@/components/in-person/participant-acceptance';
import { ParticipantCodeCard } from '@/components/in-person/participant-code-card';
import { ParticipantPinCard } from '@/components/in-person/participant-pin-card';
import type { KioskProps } from '@/components/in-person/types';
import { TERMINAL_ICONS, TerminalCard } from '@/components/sign/terminal-card';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/format';
import {
    end as kioskEnd,
    lock as kioskLock,
    complete as kioskComplete,
    participant as kioskParticipant,
    show as kioskShow,
} from '@/routes/in_person/kiosk';
import {
    send as kioskOtpSend,
    verify as kioskOtpVerify,
} from '@/routes/in_person/kiosk/otp';
import { verify as kioskPinVerify } from '@/routes/in_person/kiosk/pin';

/**
 * Dispositivo presencial (Fase 2 §2.6, docs/fase-2/presencial-e-lote.md §2.4).
 *
 * Telas: fila bloqueada (`queue`) → vez de um participante (`participant`:
 * código → PIN → documento e aceite) → fila bloqueada de novo. Nenhum dado de
 * um participante chega à vez do seguinte: a cada troca o servidor apaga o
 * estado do dispositivo e a página é remontada do zero.
 */
export default function InPersonKiosk(props: KioskProps) {
    const { screen, kiosk, queue, participant, done, ended, legal, limits } =
        props;
    const errors = usePage().props.errors as Record<string, string>;
    const [busy, setBusy] = useState<string | null>(null);
    const [confirmEnd, setConfirmEnd] = useState(false);
    const [ending, setEnding] = useState(false);

    // Inatividade: quando o prazo passa, recarrega — o servidor bloqueia a tela.
    useEffect(() => {
        if (!kiosk) {
            return;
        }

        const deadline =
            new Date(kiosk.last_activity_at).getTime() +
            kiosk.idle_minutes * 60_000 +
            2_000;
        const wait = Math.max(5_000, deadline - Date.now());
        const timer = window.setTimeout(
            () => router.reload({ only: [] }),
            wait,
        );

        return () => window.clearTimeout(timer);
    }, [kiosk]);

    const lock = () =>
        router.post(kioskLock().url, {}, { preserveState: false });

    if (screen === 'unavailable') {
        return (
            <Centered title="Assinatura presencial">
                <TerminalCard
                    icon={TERMINAL_ICONS.invalid}
                    title="Assinatura presencial indisponível"
                >
                    Este recurso não está ativo nesta instalação.
                </TerminalCard>
            </Centered>
        );
    }

    if (screen === 'none' || !kiosk) {
        return (
            <Centered title="Assinatura presencial">
                <TerminalCard
                    icon={TERMINAL_ICONS.canceled}
                    title="Nenhuma sessão presencial neste dispositivo"
                >
                    {ended?.message && (
                        <span className="mb-1.5 block">{ended.message}</span>
                    )}
                    Quem conduz a assinatura abre uma sessão em Documentos ›
                    Assinatura presencial, neste dispositivo.
                </TerminalCard>
            </Centered>
        );
    }

    const header = (
        <div className="border-border bg-card flex flex-wrap items-center justify-between gap-3 rounded-[12px] border px-4 py-3 text-[13px]">
            <span className="flex min-w-0 items-center gap-2">
                <Tablet className="text-primary size-4 shrink-0" />
                <span className="truncate">
                    <b>{kiosk.device_label}</b> · sessão aberta por{' '}
                    {kiosk.host_name ?? 'quem conduz a assinatura'} em{' '}
                    {formatDateTime(kiosk.started_at)}
                </span>
            </span>
            <span className="text-muted-foreground">
                Bloqueia após {kiosk.idle_minutes} min sem uso
            </span>
        </div>
    );

    if (screen === 'participant' && participant) {
        const notice = {
            summary: participant.privacy.summary,
            body: participant.privacy.notice,
        };
        const lockButton = (
            <Button type="button" variant="outline" onClick={lock}>
                <Lock className="size-4" />
                Não sou {participant.first_name} — bloquear tela
            </Button>
        );

        return (
            <>
                <Head title={`Presencial · ${kiosk.envelope.title}`} />
                <div key={participant.id} className="flex flex-col gap-4">
                    {header}

                    {participant.step === 'sign' && participant.signing ? (
                        <ParticipantAcceptance
                            signing={participant.signing}
                            participantName={participant.name}
                            submitUrl={kioskComplete().url}
                            privacy={participant.privacy}
                            legal={legal}
                            limits={limits}
                            captureStep={participant.identity_capture}
                            footer={lockButton}
                        />
                    ) : (
                        <div className="mx-auto flex w-full max-w-[480px] flex-col gap-4">
                            {participant.step === 'pin' &&
                            participant.auth.pin ? (
                                <ParticipantPinCard
                                    pin={participant.auth.pin}
                                    verifyUrl={kioskPinVerify().url}
                                    error={errors.pin}
                                />
                            ) : (
                                <ParticipantCodeCard
                                    heading={`Olá, ${participant.first_name}`}
                                    intro={
                                        <>
                                            Para ver e{' '}
                                            {participant.action.type ===
                                            'approve'
                                                ? 'aprovar'
                                                : 'assinar'}{' '}
                                            <b className="text-foreground">
                                                {kiosk.envelope.title}
                                            </b>
                                            , confirme o código enviado para
                                            você. Só você recebe esse código.
                                        </>
                                    }
                                    auth={participant.auth}
                                    emailMasked={participant.auth.destination}
                                    otp={participant.otp}
                                    sendUrl={kioskOtpSend().url}
                                    verifyUrl={kioskOtpVerify().url}
                                    errors={errors}
                                    codeLength={limits.otp_length}
                                    notice={notice}
                                    privacyUrl={legal.privacy_url}
                                />
                            )}
                            {lockButton}
                        </div>
                    )}
                </div>
            </>
        );
    }

    const select = (id: string) => {
        setBusy(id);
        router.post(
            kioskParticipant().url,
            { recipient: id },
            { preserveState: false, onFinish: () => setBusy(null) },
        );
    };

    const available = queue.filter((item) => item.state === 'available');

    return (
        <>
            <Head title={`Presencial · ${kiosk.envelope.title}`} />
            <div className="mx-auto flex w-full max-w-[720px] flex-col gap-4">
                {header}

                {done && (
                    <div
                        role="status"
                        className="border-success-border bg-success-bg text-success flex items-center gap-2.5 rounded-[12px] border p-4 text-[14px] font-semibold"
                    >
                        <CheckCircle2 className="size-5 shrink-0" />
                        Aceite registrado. A tela foi bloqueada — devolva o
                        dispositivo.
                    </div>
                )}

                <div className="border-border bg-card shadow-card flex flex-col gap-4 rounded-[14px] border p-5 sm:p-[22px]">
                    <div>
                        <h1 className="flex items-center gap-2 text-[20px] leading-[1.25] font-bold tracking-[-.01em]">
                            <Lock className="text-primary size-5" />
                            {kiosk.envelope.title}
                        </h1>
                        <p className="text-text-secondary mt-1.5 text-[13.5px] leading-[1.55]">
                            {available.length > 0
                                ? 'Toque no seu nome para começar. Você vai confirmar um código enviado para o seu e-mail ou celular antes de ver o documento.'
                                : 'Ninguém da fila pode registrar aceite agora.'}
                        </p>
                    </div>

                    {errors.participant && (
                        <p role="alert" className="text-danger text-[13px]">
                            {errors.participant}
                        </p>
                    )}

                    <KioskQueue queue={queue} onSelect={select} busyId={busy} />
                </div>

                <div className="flex justify-end">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => setConfirmEnd(true)}
                    >
                        Encerrar sessão presencial
                    </Button>
                </div>

                {/* Diálogo do design system (nunca a caixa nativa do
                    navegador): este é o dispositivo entregue aos
                    participantes. Sem nomes nem dados de ninguém da fila. */}
                <ConfirmDialog
                    open={confirmEnd}
                    onOpenChange={(next) => {
                        if (!ending) {
                            setConfirmEnd(next);
                        }
                    }}
                    title="Encerrar a sessão presencial?"
                    description="A sessão termina para todos neste dispositivo: quem ainda não assinou não poderá mais registrar o aceite por aqui. Uma sessão encerrada não pode ser reaberta — para continuar, quem conduz a assinatura precisa abrir uma nova."
                    confirmLabel="Encerrar para todos"
                    cancelLabel="Continuar a sessão"
                    destructive
                    processing={ending}
                    onConfirm={() => {
                        setEnding(true);
                        router.post(
                            kioskEnd().url,
                            {},
                            {
                                preserveState: false,
                                onSuccess: () => router.visit(kioskShow().url),
                                onFinish: () => {
                                    setEnding(false);
                                    setConfirmEnd(false);
                                },
                            },
                        );
                    }}
                />
            </div>
        </>
    );
}

function Centered({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <>
            <Head title={title} />
            <div className="mx-auto w-full max-w-[520px]">{children}</div>
        </>
    );
}

InPersonKiosk.layout = KioskShell;
