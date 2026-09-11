import { router, usePage } from '@inertiajs/react';
import { Layers } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { batch as recipientBatch } from '@/routes/envelopes/recipients';

/**
 * "Enviar link de lote" para um participante (integração —
 * docs/fase-2/presencial-e-lote.md §6). Só aparece com a flag
 * `batch_signing` compartilhada ligada. O servidor decide se há documentos
 * suficientes e responde com o aviso (flash) — o remetente vê quantos
 * documentos entraram, não quais.
 *
 * Antes de enviar, um diálogo explica o alcance (revisão da onda B): o link
 * abre TODOS os documentos pendentes do participante nesta conta, não só
 * este, e o e-mail não pode ser desfeito.
 */
export function SendBatchLinkButton({
    envelopeId,
    recipientId,
    recipientName,
    size = 'xs',
}: {
    envelopeId: string;
    recipientId: string;
    recipientName?: string;
    size?: 'xs' | 'sm' | 'default';
}) {
    // `batch_signing` ainda não está no tipo `Features` compartilhado (integração).
    const enabled = Object.entries(usePage().props.features ?? {}).some(
        ([key, value]) => key === 'batch_signing' && value === true,
    );
    const [confirming, setConfirming] = useState(false);
    const [sending, setSending] = useState(false);

    if (!enabled) {
        return null;
    }

    const send = () => {
        setSending(true);
        router.post(
            recipientBatch({
                envelope: envelopeId,
                recipient: recipientId,
            }).url,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setSending(false);
                    setConfirming(false);
                },
            },
        );
    };

    return (
        <>
            <Button
                type="button"
                variant="outline"
                size={size}
                disabled={sending}
                onClick={() => setConfirming(true)}
            >
                {sending ? (
                    <Spinner className="size-4" />
                ) : (
                    <Layers className="size-4" />
                )}
                Enviar link de lote
            </Button>
            <ConfirmDialog
                open={confirming}
                onOpenChange={(open) => !sending && setConfirming(open)}
                processing={sending}
                title="Enviar link de lote?"
                description={
                    <>
                        {recipientName ?? 'O participante'} vai receber por
                        e-mail um único link com{' '}
                        <b>todos os documentos pendentes dele nesta conta</b> —
                        não só este, mas também os de outros documentos e pastas
                        enviados para o mesmo e-mail. Cada documento continua
                        sendo conferido e autorizado separadamente. O e-mail não
                        pode ser desfeito depois de enviado.
                    </>
                }
                confirmLabel="Enviar link"
                onConfirm={send}
            />
        </>
    );
}
