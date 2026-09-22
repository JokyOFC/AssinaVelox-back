import { usePage } from '@inertiajs/react';
import { Info, ShieldCheck } from 'lucide-react';
import { useEffect, useState } from 'react';
import { getJson } from '@/components/dossier/http';
import { STATUS_VARIANT } from '@/components/identity/verification-step';
import type {
    IdentityVerificationIndex,
    IdentityVerificationItem,
} from '@/components/identity/verification-types';
import { Badge } from '@/components/ui/badge';
import { formatDateTime } from '@/lib/format';
import { index as identityVerificationsIndex } from '@/routes/envelopes/identity_verifications';

/**
 * Verificações faciais com documento no detalhe do envelope (Fase 4 §4.1, flag
 * `identity_verification`). Só para quem vê o envelope; nunca na verificação pública.
 *
 * Por participante com a exigência, cada tentativa com o que o PROVEDOR informou: estado,
 * provedor ("(simulado)" quando for), tipo do documento, data, protocolo e a mesma frase que
 * o participante viu ("Verifiky informou: aprovado"). Nunca imagem, dado lido do documento
 * nem pontuação — o servidor não os envia. 404 (flag desligada) ou nenhuma exigência: nada.
 */
export function IdentityVerificationPanel({
    envelopeId,
    recipients = [],
}: {
    envelopeId: string;
    /** Para nomear quem tem a exigência mas ainda não enviou nada. */
    recipients?: { id: string; name: string }[];
}) {
    const enabled = Boolean(usePage().props.features?.identity_verification);
    const [data, setData] = useState<IdentityVerificationIndex | null>(null);

    useEffect(() => {
        if (!enabled) {
            return;
        }

        let alive = true;

        void getJson<IdentityVerificationIndex>(
            identityVerificationsIndex(envelopeId).url,
        ).then((response) => {
            if (alive && response.ok && response.body) {
                setData(response.body);
            }
        });

        return () => {
            alive = false;
        };
    }, [enabled, envelopeId]);

    const required = data ? Object.keys(data.requirements) : [];

    if (!enabled || !data || required.length === 0) {
        return null;
    }

    const byRecipient = new Map<string, IdentityVerificationItem[]>();

    for (const item of data.items) {
        if (item.recipient_id === null) {
            continue;
        }

        byRecipient.set(item.recipient_id, [
            ...(byRecipient.get(item.recipient_id) ?? []),
            item,
        ]);
    }

    const nameOf = (recipientId: string): string =>
        byRecipient.get(recipientId)?.[0]?.recipient_name ??
        recipients.find((recipient) => recipient.id === recipientId)?.name ??
        'Participante';

    return (
        <section
            className="border-border bg-card flex flex-col gap-3 rounded-[12px] border p-4"
            aria-labelledby="identity-verifications-title"
        >
            <h2
                id="identity-verifications-title"
                className="flex items-center gap-1.5 text-[14px] font-semibold"
            >
                <ShieldCheck className="text-primary size-4" />
                Verificação facial com documento
            </h2>

            <p className="border-border bg-sidebar text-text-secondary flex items-start gap-2 rounded-[10px] border p-3 text-[12.5px] leading-[1.5]">
                <Info className="text-primary mt-0.5 size-3.5 shrink-0" />
                <span>{data.notice}</span>
            </p>

            <ul className="flex flex-col gap-3">
                {required.map((recipientId) => {
                    const attempts = [
                        ...(byRecipient.get(recipientId) ?? []),
                    ].sort((a, b) => a.attempt - b.attempt);

                    return (
                        <li
                            key={recipientId}
                            className="border-border flex flex-col gap-2 rounded-[10px] border p-3"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="text-[13px] font-semibold">
                                    {nameOf(recipientId)}
                                </span>
                                <span className="text-muted-foreground tabular text-[12px]">
                                    {attempts.length === 0
                                        ? 'Nenhum envio ainda'
                                        : `Tentativas: ${attempts.length} de ${data.max_attempts}`}
                                </span>
                            </div>

                            {attempts.length === 0 ? (
                                <p className="text-muted-foreground text-[12.5px] leading-[1.5]">
                                    Exigida antes do aceite. O participante
                                    ainda não enviou as fotos ao provedor{' '}
                                    {data.provider_label}.
                                </p>
                            ) : (
                                <ol className="flex flex-col gap-2">
                                    {attempts.map((item) => (
                                        <li
                                            key={item.id}
                                            className="bg-sidebar flex flex-col gap-1 rounded-[8px] p-2.5 text-[12.5px] leading-[1.5]"
                                        >
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Badge
                                                    variant={
                                                        STATUS_VARIANT[
                                                            item.status
                                                        ]
                                                    }
                                                    dot
                                                >
                                                    {item.status_label}
                                                </Badge>
                                                <span className="text-muted-foreground tabular text-[12px]">
                                                    Tentativa {item.attempt}
                                                </span>
                                                {item.attached && (
                                                    <Badge variant="outline">
                                                        Vinculada ao aceite
                                                    </Badge>
                                                )}
                                            </div>

                                            <span className="text-muted-foreground">
                                                {[
                                                    `Provedor: ${item.provider_label}${item.simulated ? ' (simulado)' : ''}`,
                                                    item.document_type_label
                                                        ? `Documento: ${item.document_type_label}`
                                                        : null,
                                                    item.completed_at_local
                                                        ? `Resposta em ${item.completed_at_local}`
                                                        : `Enviada em ${formatDateTime(item.submitted_at)}`,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </span>

                                            {item.message && (
                                                <span>{item.message}</span>
                                            )}

                                            {item.provider_verification_id && (
                                                <span
                                                    className="text-muted-foreground truncate font-mono text-[10.5px]"
                                                    title={
                                                        item.provider_verification_id
                                                    }
                                                >
                                                    Protocolo{' '}
                                                    {
                                                        item.provider_verification_id
                                                    }
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
