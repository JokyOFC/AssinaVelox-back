import { Barcode, CreditCard, QrCode, Wallet } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { formatDateMedium } from '@/lib/format';
import type { PaymentMethod } from '@/types';

const ICONS: Record<PaymentMethod['type'], LucideIcon> = {
    credit_card: CreditCard,
    debit_card: CreditCard,
    pix: QrCode,
    boleto: Barcode,
    account_money: Wallet,
    other: CreditCard,
};

/**
 * Forma de pagamento — **somente leitura** (RECONCILIACAO §4 Q21): o Checkout
 * Pro não guarda cartão do cliente, então o card mostra apenas o meio usado no
 * último pagamento aprovado. Não existe "alterar forma de pagamento": a
 * escolha é feita dentro do checkout, a cada ciclo, porque o Checkout Pro não
 * faz cobrança recorrente (docs/integracoes/mercado-pago.md §6.1).
 */
export function PaymentMethodCard({
    paymentMethod,
    lastPaidAt = null,
}: {
    paymentMethod: PaymentMethod | null;
    lastPaidAt?: string | null;
}) {
    const Icon = paymentMethod ? ICONS[paymentMethod.type] : CreditCard;

    return (
        <div className="border-border bg-card shadow-card flex flex-col gap-3 rounded-xl border p-5">
            <Heading
                variant="small"
                title="Forma de pagamento"
                description="Meio usado no último pagamento aprovado."
                action={<Badge variant="neutral">Somente leitura</Badge>}
            />

            {paymentMethod ? (
                <div className="border-border flex items-center gap-3 rounded-[10px] border p-3">
                    <span className="bg-navy flex h-[30px] w-11 shrink-0 items-center justify-center rounded-md text-white">
                        <Icon aria-hidden className="size-4" />
                    </span>
                    <span className="min-w-0">
                        <span className="block text-[13.5px] font-semibold">
                            {paymentMethod.label}
                            {paymentMethod.last_four &&
                                ` •••• ${paymentMethod.last_four}`}
                        </span>
                        <span className="text-muted-foreground block text-[12px]">
                            {lastPaidAt
                                ? `Último pagamento aprovado em ${formatDateMedium(lastPaidAt)}.`
                                : 'Registrado a partir do último pagamento aprovado.'}
                        </span>
                    </span>
                </div>
            ) : (
                <p className="text-muted-foreground text-[13px] leading-[1.6]">
                    Nenhum pagamento aprovado ainda — por isso não há meio de
                    pagamento para mostrar.
                </p>
            )}

            <p className="text-text-secondary text-[12.5px] leading-[1.6]">
                <b className="text-foreground">Não guardamos cartão.</b> Cada
                ciclo é um pagamento avulso no Checkout Pro do Mercado Pago:
                cartão de crédito ou débito, Pix, boleto e saldo em conta são
                escolhidos por você dentro do checkout, na hora de pagar. Para
                usar outro meio, basta escolher outro no próximo pagamento.
            </p>
        </div>
    );
}
