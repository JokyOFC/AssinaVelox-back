import { router } from '@inertiajs/react';
import { RotateCw } from 'lucide-react';
import { useEffect, useState } from 'react';
import { CopyButton } from '@/components/copy-button';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import { resend, show } from '@/routes/integrations/webhooks/deliveries';
import { DeliveryStatusBadge, HttpStatusBadge } from './badges';
import type { WebhookDeliveryDetail } from './types';

/**
 * Gaveta de detalhes de uma entrega: tentativas, resposta truncada e redigida,
 * corpo enviado (oculto quando quem olha não vê o documento) e reenvio manual
 * com o mesmo id de entrega.
 */
export function DeliverySheet({
    endpointId,
    deliveryId,
    onOpenChange,
}: {
    endpointId: string;
    deliveryId: string | null;
    onOpenChange: (open: boolean) => void;
}) {
    const [detail, setDetail] = useState<WebhookDeliveryDetail | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [resending, setResending] = useState(false);

    useEffect(() => {
        if (!deliveryId) {
            return;
        }

        const controller = new AbortController();

        fetch(show.url({ webhookEndpoint: endpointId, delivery: deliveryId }), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                setDetail((await response.json()) as WebhookDeliveryDetail);
                setError(null);
            })
            .catch((reason: unknown) => {
                if (
                    !(reason instanceof DOMException) ||
                    reason.name !== 'AbortError'
                ) {
                    setError('Não foi possível carregar a entrega.');
                }
            });

        return () => {
            controller.abort();
            setDetail(null);
            setError(null);
        };
    }, [endpointId, deliveryId]);

    const doResend = () => {
        if (!deliveryId) {
            return;
        }

        setResending(true);
        router.post(
            resend.url({ webhookEndpoint: endpointId, delivery: deliveryId }),
            {},
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onFinish: () => setResending(false),
            },
        );
    };

    return (
        <Sheet open={deliveryId !== null} onOpenChange={onOpenChange}>
            <SheetContent className="w-full overflow-y-auto sm:max-w-[560px]">
                <SheetHeader>
                    <SheetTitle>Detalhes da entrega</SheetTitle>
                    <SheetDescription>
                        Deduplique pelo id da entrega: ele é o mesmo em todas as
                        tentativas e no reenvio.
                    </SheetDescription>
                </SheetHeader>

                <div className="flex flex-col gap-5 px-4 pb-6">
                    {error && (
                        <p className="text-danger text-[13px]">{error}</p>
                    )}
                    {!detail && !error && (
                        <div className="text-muted-foreground flex items-center gap-2 text-[13px]">
                            <Spinner /> Carregando…
                        </div>
                    )}
                    {detail && (
                        <>
                            <dl className="grid grid-cols-[140px_minmax(0,1fr)] gap-x-3 gap-y-2 text-[13px]">
                                <dt className="text-muted-foreground">
                                    Evento
                                </dt>
                                <dd>
                                    <span className="font-medium">
                                        {detail.event_label}
                                    </span>{' '}
                                    <code className="text-primary font-mono text-[12px]">
                                        {detail.event_type}
                                    </code>
                                </dd>
                                <dt className="text-muted-foreground">
                                    Situação
                                </dt>
                                <dd>
                                    <DeliveryStatusBadge
                                        status={detail.status}
                                        label={detail.status_label}
                                    />
                                </dd>
                                <dt className="text-muted-foreground">
                                    Id da entrega
                                </dt>
                                <dd className="flex items-center gap-1 font-mono text-[12px] break-all">
                                    {detail.id}
                                    <CopyButton value={detail.id} />
                                </dd>
                                <dt className="text-muted-foreground">
                                    Id do evento
                                </dt>
                                <dd className="font-mono text-[12px] break-all">
                                    {detail.event_id}
                                </dd>
                                <dt className="text-muted-foreground">
                                    Documento
                                </dt>
                                <dd>{detail.envelope?.code ?? '—'}</dd>
                                <dt className="text-muted-foreground">
                                    Tentativas
                                </dt>
                                <dd className="tabular">
                                    {detail.attempts} de {detail.max_attempts}
                                </dd>
                                {detail.next_retry_at && (
                                    <>
                                        <dt className="text-muted-foreground">
                                            Próxima tentativa
                                        </dt>
                                        <dd>
                                            {formatDateTime(
                                                detail.next_retry_at,
                                            )}
                                        </dd>
                                    </>
                                )}
                                {detail.last_error_label && (
                                    <>
                                        <dt className="text-muted-foreground">
                                            Último erro
                                        </dt>
                                        <dd className="text-text-secondary">
                                            {detail.last_error_label}
                                        </dd>
                                    </>
                                )}
                            </dl>

                            <section>
                                <h3 className="mb-2 text-[13.5px] font-semibold">
                                    Tentativas
                                </h3>
                                {detail.history.length === 0 ? (
                                    <p className="text-muted-foreground text-[13px]">
                                        Nenhuma tentativa ainda.
                                    </p>
                                ) : (
                                    <ol className="border-border divide-border divide-y rounded-lg border">
                                        {detail.history.map((attempt, i) => (
                                            <li
                                                key={`${attempt.attempt ?? i}-${attempt.attempted_at ?? i}`}
                                                className="flex flex-col gap-1 px-3 py-2.5 text-[12.5px]"
                                            >
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-semibold">
                                                        {attempt.attempt ??
                                                            i + 1}
                                                        ª
                                                    </span>
                                                    <HttpStatusBadge
                                                        status={
                                                            attempt.response_code ??
                                                            null
                                                        }
                                                    />
                                                    <span className="text-text-secondary">
                                                        {attempt.outcome_label ??
                                                            attempt.outcome}
                                                    </span>
                                                    {attempt.trigger ===
                                                        'manual' && (
                                                        <span className="text-muted-foreground">
                                                            · reenvio manual
                                                        </span>
                                                    )}
                                                    <span className="text-muted-foreground ml-auto">
                                                        {formatDateTime(
                                                            attempt.attempted_at,
                                                        )}
                                                        {attempt.duration_ms !=
                                                            null &&
                                                            ` · ${attempt.duration_ms} ms`}
                                                    </span>
                                                </div>
                                                {attempt.error_label && (
                                                    <p className="text-text-secondary">
                                                        {attempt.error_label}
                                                    </p>
                                                )}
                                                {attempt.response_excerpt && (
                                                    <pre className="bg-muted overflow-x-auto rounded px-2 py-1.5 font-mono text-[11.5px] whitespace-pre-wrap">
                                                        {
                                                            attempt.response_excerpt
                                                        }
                                                    </pre>
                                                )}
                                            </li>
                                        ))}
                                    </ol>
                                )}
                            </section>

                            <section>
                                <h3 className="mb-2 text-[13.5px] font-semibold">
                                    Corpo enviado
                                </h3>
                                {detail.payload_hidden ? (
                                    <p className="text-muted-foreground text-[13px]">
                                        Oculto: você não tem acesso ao documento
                                        desta entrega.
                                    </p>
                                ) : (
                                    <pre className="bg-navy text-code-text max-h-[320px] overflow-auto rounded-xl p-3.5 font-mono text-[12px] leading-[1.6]">
                                        {JSON.stringify(
                                            detail.payload,
                                            null,
                                            2,
                                        )}
                                    </pre>
                                )}
                            </section>

                            {detail.can_resend && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={doResend}
                                    disabled={resending}
                                    className="self-start"
                                >
                                    {resending ? (
                                        <Spinner />
                                    ) : (
                                        <RotateCw className="size-3.5" />
                                    )}
                                    Reenviar com o mesmo id
                                </Button>
                            )}
                        </>
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}
