import { router } from '@inertiajs/react';
import { GitBranch, UserRoundCog } from 'lucide-react';
import { useState } from 'react';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/format';
import {
    approve as delegationApprove,
    reject as delegationReject,
} from '@/routes/envelopes/delegations';
import {
    delegationStatusTones,
    stepStatusTones,
    type EnvelopeFlowState,
    type FlowDelegationRequest,
} from './types';
import { useEnvelopeFlow } from './use-envelope-flow';

/**
 * Etapas e delegações no detalhe do documento (Fase 3 §3.3 — docs/fase-3/etapas-e-delegacao.md
 * §5). Some por inteiro com as flags desligadas (404) e quando o envelope não tem etapas nem
 * pedidos de delegação. Confirmar/recusar é de quem pode enviar (`can_decide`).
 */
export function EnvelopeFlowPanel({ envelopeId }: { envelopeId: string }) {
    const { state, status } = useEnvelopeFlow(envelopeId, 'envelopes/show');

    if (status !== 'ready' || state === null) {
        return null;
    }

    const steps = state.steps?.enabled ? state.steps : null;
    const requests = state.delegation?.requests ?? [];

    if (steps === null && requests.length === 0) {
        return null;
    }

    return (
        <section className="border-border bg-card shadow-card flex flex-col gap-4 rounded-xl border p-5">
            {steps && <StepsTimeline state={state} />}
            {requests.length > 0 && (
                <div className="flex flex-col gap-2">
                    <h3 className="flex items-center gap-2 text-[14px] font-semibold">
                        <UserRoundCog className="text-primary size-4" />
                        Delegações
                    </h3>
                    {requests.map((request) => (
                        <DelegationRow
                            key={request.id}
                            envelopeId={state.envelope.id}
                            request={request}
                        />
                    ))}
                </div>
            )}
        </section>
    );
}

function StepsTimeline({ state }: { state: EnvelopeFlowState }) {
    const items = state.steps?.items ?? [];

    return (
        <div className="flex flex-col gap-2">
            <h3 className="flex items-center gap-2 text-[14px] font-semibold">
                <GitBranch className="text-primary size-4" />
                Etapas do fluxo
            </h3>
            <ol className="flex flex-col gap-2">
                {items.map((item) => {
                    const members = state.participants.filter(
                        (p) => p.step === item.index,
                    );

                    return (
                        <li
                            key={item.id}
                            className="border-border rounded-[10px] border p-3"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="text-[13.5px] font-semibold">
                                    {item.index}.{' '}
                                    {item.name ?? `Etapa ${item.index}`}
                                </span>
                                <Badge variant={stepStatusTones[item.status]}>
                                    {item.status_label}
                                </Badge>
                            </div>
                            <p className="text-muted-foreground mt-1 text-[12.5px]">
                                {members
                                    .map(
                                        (m) =>
                                            `${m.name} (${m.status_label.toLowerCase()})`,
                                    )
                                    .join(', ') || '—'}
                            </p>
                            {item.summary.length > 0 && (
                                <ul className="text-text-secondary mt-1.5 list-disc pl-5 text-[12px]">
                                    {item.summary.map((line, i) => (
                                        <li key={i}>{line}</li>
                                    ))}
                                </ul>
                            )}
                            {item.condition === null && item.index > 1 && (
                                <p className="text-muted-foreground mt-1 text-[12px]">
                                    Sem condição: sempre se aplica.
                                </p>
                            )}
                        </li>
                    );
                })}
            </ol>
        </div>
    );
}

function DelegationRow({
    envelopeId,
    request,
}: {
    envelopeId: string;
    request: FlowDelegationRequest;
}) {
    const [rejecting, setRejecting] = useState(false);
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);

    const post = (url: string, data: Record<string, string> = {}) => {
        setBusy(true);
        router.post(url, data, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
            onSuccess: () => {
                setRejecting(false);
                setNote('');
            },
        });
    };

    return (
        <div className="border-border flex flex-col gap-2 rounded-[10px] border p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-[13.5px]">
                    <span className="font-semibold">{request.from.name}</span> →{' '}
                    <span className="font-semibold">{request.to.name}</span>{' '}
                    <span className="text-muted-foreground">
                        ({request.to.email})
                    </span>
                </span>
                <Badge variant={delegationStatusTones[request.status]}>
                    {request.status_label}
                </Badge>
            </div>
            <p className="text-text-secondary text-[12.5px]">
                Motivo: “{request.reason}”
            </p>
            <p className="text-muted-foreground text-[12px]">
                Pedido em {formatDateTime(request.requested_at)}
                {request.delegated_at &&
                    ` · em vigor desde ${formatDateTime(request.delegated_at)}`}
                {request.rejected_at &&
                    ` · recusado em ${formatDateTime(request.rejected_at)}`}
                {request.decision_note && ` · “${request.decision_note}”`}
            </p>

            {request.can_decide && !rejecting && (
                <div className="flex flex-wrap gap-2">
                    {/* Confirmar vale na hora e não se desfaz pela interface: a consequência é
                        dita antes, como na página pública para quem pede. */}
                    <AlertDialog>
                        <AlertDialogTrigger asChild>
                            <Button type="button" size="sm" disabled={busy}>
                                Confirmar delegação
                            </Button>
                        </AlertDialogTrigger>
                        <AlertDialogContent>
                            <AlertDialogHeader>
                                <AlertDialogTitle>
                                    Confirmar a delegação para {request.to.name}
                                    ?
                                </AlertDialogTitle>
                                <AlertDialogDescription>
                                    O link de {request.from.name} deixa de
                                    valer. {request.to.name} recebe convite e
                                    código próprios, fica com a mesma etapa e os
                                    mesmos campos (e com as fotos ou o vídeo
                                    curto exigidos, se houver) e registra o
                                    próprio aceite, que é dele, não de{' '}
                                    {request.from.name}. Não é possível
                                    desfazer.
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                                <AlertDialogCancel>Voltar</AlertDialogCancel>
                                <AlertDialogAction
                                    disabled={busy}
                                    onClick={() =>
                                        post(
                                            delegationApprove({
                                                envelope: envelopeId,
                                                delegation: request.id,
                                            }).url,
                                        )
                                    }
                                >
                                    Confirmar delegação
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </AlertDialogContent>
                    </AlertDialog>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={busy}
                        onClick={() => setRejecting(true)}
                    >
                        Recusar
                    </Button>
                </div>
            )}

            {request.can_decide && rejecting && (
                <div className="grid gap-2">
                    <Textarea
                        rows={2}
                        maxLength={500}
                        value={note}
                        placeholder="Observação para o participante (opcional)"
                        aria-label="Observação da recusa"
                        onChange={(event) => setNote(event.target.value)}
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="destructive"
                            disabled={busy}
                            onClick={() =>
                                post(
                                    delegationReject({
                                        envelope: envelopeId,
                                        delegation: request.id,
                                    }).url,
                                    { note },
                                )
                            }
                        >
                            Recusar pedido
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => setRejecting(false)}
                        >
                            Voltar
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}
