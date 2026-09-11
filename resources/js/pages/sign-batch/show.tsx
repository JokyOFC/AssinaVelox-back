import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Layers } from 'lucide-react';
import { useState } from 'react';
import { BatchItemList } from '@/components/batch/batch-item-list';
import { BatchShell } from '@/components/batch/batch-shell';
import { ParticipantAcceptance } from '@/components/in-person/participant-acceptance';
import { ParticipantCodeCard } from '@/components/in-person/participant-code-card';
import type { BatchProps } from '@/components/in-person/types';
import { TERMINAL_ICONS, TerminalCard } from '@/components/sign/terminal-card';
import { Button } from '@/components/ui/button';
import { formatDateMedium, plural } from '@/lib/format';
import { leave as batchLeave, show as batchShow } from '@/routes/sign/batch';
import {
    authorize as batchAuthorize,
    open as batchOpen,
} from '@/routes/sign/batch/items';
import {
    send as batchOtpSend,
    verify as batchOtpVerify,
} from '@/routes/sign/batch/otp';

/**
 * Página pública do lote (Fase 2 §2.7, docs/fase-2/presencial-e-lote.md §3.5).
 *
 * Um código por e-mail abre a LISTA; cada documento é aberto, revisado e
 * autorizado separadamente — cada autorização grava o aceite só daquele
 * documento. Não há "autorizar todos", e itens encerrados aparecem sem botão.
 */
export default function SignBatchShow(props: BatchProps) {
    const {
        screen,
        sender,
        batch,
        recipient,
        otp,
        items,
        current,
        privacy,
        legal,
        limits,
    } = props;
    const errors = usePage().props.errors as Record<string, string>;
    const [busy, setBusy] = useState<string | null>(null);

    if (screen === 'unavailable' || screen === 'none' || screen === 'invalid') {
        return (
            <>
                <Head title="Documentos pendentes" />
                <div className="mx-auto w-full max-w-[520px]">
                    <TerminalCard
                        icon={TERMINAL_ICONS.invalid}
                        title={
                            screen === 'unavailable'
                                ? 'Assinatura em lote indisponível'
                                : screen === 'invalid'
                                  ? 'Link inválido'
                                  : 'Abra o link recebido por e-mail'
                        }
                    >
                        {screen === 'unavailable'
                            ? 'Este recurso não está ativo nesta instalação. Use o link individual de cada documento.'
                            : screen === 'invalid'
                              ? 'Este link não existe, venceu ou foi substituído por um mais recente. Verifique o e-mail mais recente ou use o link individual de cada documento.'
                              : 'Para ver os documentos pendentes, abra o link que você recebeu por e-mail.'}
                    </TerminalCard>
                </div>
            </>
        );
    }

    if (!batch || !recipient) {
        return null;
    }

    if (screen === 'identify') {
        return (
            <>
                <Head title="Documentos pendentes" />
                <div className="mx-auto flex w-full max-w-[480px] flex-col gap-4">
                    <ParticipantCodeCard
                        heading={`Olá, ${recipient.first_name}`}
                        intro={
                            <>
                                <b className="text-foreground">
                                    {sender.organization_name}
                                </b>{' '}
                                tem{' '}
                                {plural(
                                    batch.items_count,
                                    'documento aguardando',
                                    'documentos aguardando',
                                )}{' '}
                                o seu aceite. Confirme o código para ver a
                                lista. Depois, você abre, revisa e autoriza cada
                                documento separadamente.
                            </>
                        }
                        emailMasked={recipient.email_masked}
                        otp={otp}
                        sendUrl={batchOtpSend().url}
                        verifyUrl={batchOtpVerify().url}
                        errors={errors}
                        codeLength={limits.otp_length}
                        notice={{
                            summary: privacy?.summary ?? '',
                            body: privacy?.notice ?? null,
                        }}
                        privacyUrl={legal.privacy_url}
                    />
                    <p className="text-muted-foreground text-center text-[12.5px]">
                        Link válido até {formatDateMedium(batch.expires_at)}.
                    </p>
                </div>
            </>
        );
    }

    if (screen === 'item' && current) {
        return (
            <>
                <Head title={current.envelope.title} />
                <div className="flex flex-col gap-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Button asChild variant="ghost" size="sm">
                            <Link href={batchShow().url}>
                                <ArrowLeft className="size-4" />
                                Voltar para a lista
                            </Link>
                        </Button>
                        <span className="text-muted-foreground text-[12.5px]">
                            Esta autorização vale só para{' '}
                            <b className="text-foreground">
                                {current.envelope.title}
                            </b>
                            .
                        </span>
                    </div>
                    <ParticipantAcceptance
                        key={current.id}
                        signing={current}
                        participantName={recipient.first_name}
                        submitUrl={batchAuthorize(current.id).url}
                        privacy={current.privacy}
                        legal={legal}
                        limits={limits}
                        heading={
                            current.action.type === 'approve'
                                ? 'Aprovar este documento'
                                : 'Autorizar este documento'
                        }
                    />
                </div>
            </>
        );
    }

    const open = (id: string) => {
        setBusy(id);
        router.post(
            batchOpen(id).url,
            {},
            { preserveState: false, onFinish: () => setBusy(null) },
        );
    };

    const done = items.filter((item) => item.state === 'done').length;

    return (
        <>
            <Head title="Documentos pendentes" />
            <div className="mx-auto flex w-full max-w-[760px] flex-col gap-4">
                <div className="border-border bg-card shadow-card flex flex-col gap-4 rounded-[14px] border p-5 sm:p-[22px]">
                    <div>
                        <h1 className="flex items-center gap-2 text-[20px] leading-[1.25] font-bold tracking-[-.01em]">
                            <Layers className="text-primary size-5" />
                            Documentos de {sender.organization_name}
                        </h1>
                        <p className="text-text-secondary mt-1.5 text-[13.5px] leading-[1.55]">
                            {done} de {items.length} com aceite registrado. Abra
                            cada documento, confira e autorize: cada autorização
                            registra o aceite só daquele documento. Documentos
                            que chegarem depois não entram nesta lista.
                        </p>
                    </div>

                    {errors.item && (
                        <p role="alert" className="text-danger text-[13px]">
                            {errors.item}
                        </p>
                    )}

                    <BatchItemList items={items} onOpen={open} busyId={busy} />
                </div>

                <div className="flex justify-end">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            router.post(
                                batchLeave().url,
                                {},
                                { preserveState: false },
                            )
                        }
                    >
                        Sair da lista neste navegador
                    </Button>
                </div>
            </div>
        </>
    );
}

SignBatchShow.layout = BatchShell;
