import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import {
    newRequestKey,
    parseAmountToCents,
} from '@/components/billing/request-key';
import { ConfirmDialog } from '@/components/confirm-dialog';
import type { ConfirmsPassword } from '@/components/confirms-password';
import { SegmentedControl } from '@/components/segmented-control';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatCurrency } from '@/lib/format';

export interface RefundTarget {
    id: string;
    label: string;
    refundable_cents: number;
    currency: string;
    /** Estorno integral só quando nada foi estornado ainda. */
    fully_refundable: boolean;
}

/**
 * Pedido de estorno (Fase 2, onda D). Integral por padrão; parcial só quando
 * `allowPartial` (equipe da plataforma). A senha é confirmada ANTES do envio —
 * a rota exige `password.confirm` — e cada abertura gera uma chave de pedido
 * nova, reenviada igual em qualquer repetição.
 *
 * O texto não promete prazo de devolução: o prazo por meio de pagamento é NÃO
 * CONFIRMADO na documentação do provedor.
 */
export function RefundDialog({
    target,
    onOpenChange,
    submitUrl,
    allowPartial,
    password,
    planEffectNote,
}: {
    target: RefundTarget | null;
    onOpenChange: (open: boolean) => void;
    submitUrl: string | null;
    allowPartial: boolean;
    password: ConfirmsPassword;
    planEffectNote?: string;
}) {
    const [mode, setMode] = useState<'total' | 'partial'>('total');
    const [amount, setAmount] = useState('');
    const [reason, setReason] = useState('');
    const [requestKey, setRequestKey] = useState(newRequestKey);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const open = target !== null;

    useEffect(() => {
        if (open) {
            setMode(target.fully_refundable ? 'total' : 'partial');
            setAmount('');
            setReason('');
            setErrors({});
            setRequestKey(newRequestKey());
        }
    }, [open, target]);

    const partial = mode === 'partial';
    const cents = partial ? parseAmountToCents(amount) : null;
    const amountInvalid =
        partial &&
        (cents === null ||
            (target !== null && cents > target.refundable_cents));

    const submit = () => {
        if (!target || !submitUrl) {
            return;
        }

        if (amountInvalid) {
            setErrors({
                amount_cents: `Informe um valor entre ${formatCurrency(1, target.currency)} e ${formatCurrency(target.refundable_cents, target.currency)}.`,
            });

            return;
        }

        password.ensure(() => {
            setProcessing(true);
            router.post(
                submitUrl,
                {
                    reason,
                    idempotency_key: requestKey,
                    ...(partial && cents !== null
                        ? { amount_cents: cents }
                        : {}),
                },
                {
                    preserveScroll: true,
                    onSuccess: () => onOpenChange(false),
                    onError: (bag) => setErrors(bag),
                    onFinish: () => setProcessing(false),
                },
            );
        });
    };

    return (
        <ConfirmDialog
            open={open}
            onOpenChange={onOpenChange}
            destructive
            processing={processing}
            disabled={reason.trim().length < 5}
            title="Estornar pagamento?"
            description={
                target
                    ? `${target.label} — saldo estornável ${formatCurrency(target.refundable_cents, target.currency)}. O Mercado Pago devolve o valor ao meio usado no pagamento.`
                    : undefined
            }
            confirmLabel={partial ? 'Estornar valor' : 'Estornar integralmente'}
            cancelLabel="Voltar"
            onConfirm={submit}
        >
            <div className="grid gap-3">
                {allowPartial && target?.fully_refundable && (
                    <SegmentedControl
                        size="sm"
                        ariaLabel="Tipo de estorno"
                        value={mode}
                        onChange={setMode}
                        options={[
                            { value: 'total', label: 'Integral' },
                            { value: 'partial', label: 'Parcial' },
                        ]}
                    />
                )}

                {partial && (
                    <div className="grid gap-1.5">
                        <Label htmlFor="refund-amount">Valor a estornar</Label>
                        <Input
                            id="refund-amount"
                            inputMode="decimal"
                            placeholder="Ex.: 10,00"
                            value={amount}
                            onChange={(event) => setAmount(event.target.value)}
                        />
                        {errors.amount_cents && (
                            <p className="text-danger text-[12.5px]">
                                {errors.amount_cents}
                            </p>
                        )}
                    </div>
                )}

                <div className="grid gap-1.5">
                    <Label htmlFor="refund-reason">Motivo</Label>
                    <Textarea
                        id="refund-reason"
                        rows={3}
                        maxLength={500}
                        placeholder="Por que este pagamento está sendo estornado?"
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                    />
                    {errors.reason && (
                        <p className="text-danger text-[12.5px]">
                            {errors.reason}
                        </p>
                    )}
                </div>

                <p className="text-muted-foreground text-[12.5px] leading-[1.55]">
                    {partial
                        ? 'Estorno parcial não altera o plano nem a cota do ciclo.'
                        : (planEffectNote ??
                          'Se este pagamento pagou o ciclo atual, o plano não é renovado: vale até o fim do ciclo e depois a conta volta ao plano Grátis. A cota já usada não é devolvida. Documentos já assinados continuam válidos.')}{' '}
                    Se o Mercado Pago não responder a tempo, nada é repetido às
                    cegas: o provedor é consultado antes de qualquer nova
                    tentativa.
                </p>
            </div>
        </ConfirmDialog>
    );
}
