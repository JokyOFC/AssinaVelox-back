<?php

namespace App\Services\Billing;

use App\Enums\PaymentStatus;

/**
 * Ordem dos eventos do provedor.
 *
 * O Mercado Pago entrega `payment.created` e `payment.updated` para o mesmo pagamento,
 * repete uma notificação até receber 200 (tentativas em 0, 15 e 30 min, 6 h, 48 h e
 * 96 h) e **não garante ordem**. Uma retentativa antiga chegando depois de uma nova
 * pode carregar um estado anterior; aplicá-la rebaixaria um pagamento já aprovado e
 * derrubaria o plano de um cliente que pagou.
 *
 * A regra é um posto de finalidade: um estado só é gravado quando o posto do estado que
 * chega é maior ou igual ao do estado atual.
 *
 * | posto | estados                                             | leitura                                   |
 * |-------|-----------------------------------------------------|-------------------------------------------|
 * | 0     | `pending`, `in_process`                              | ainda em curso                            |
 * | 1     | `authorized`, `rejected`, `cancelled`                | desfecho antes do crédito                 |
 * | 2     | `approved`                                          | creditado                                 |
 * | 3     | `in_mediation`, `refunded`, `charged_back`           | só acontece DEPOIS de um crédito          |
 *
 * Consequências concretas:
 *
 * - `approved` (2) nunca é rebaixado para `pending`/`in_process` (0) nem para
 *   `rejected`/`cancelled` (1) — e a documentação confirma que cancelar só é possível
 *   em `pending`, `in_process` ou `authorized`, então um `cancelled` posterior a um
 *   `approved` é sempre evento fora de ordem;
 * - `refunded` e `charged_back` (3) **passam**, porque são transições legítimas
 *   posteriores à aprovação;
 * - o mesmo estado repetido passa (a gravação é idempotente).
 *
 * Isso é independente de `payments.activated_at`: mesmo que um estorno rebaixe o
 * pagamento, a ativação já feita continua registrada e não é aplicada de novo.
 */
final class PaymentStatusTransition
{
    /**
     * @return int<0, 3>
     */
    public static function rank(PaymentStatus $status): int
    {
        return match ($status) {
            PaymentStatus::Pending, PaymentStatus::InProcess => 0,
            PaymentStatus::Authorized, PaymentStatus::Rejected, PaymentStatus::Cancelled => 1,
            PaymentStatus::Approved => 2,
            PaymentStatus::InMediation, PaymentStatus::Refunded, PaymentStatus::ChargedBack => 3,
        };
    }

    public static function allows(PaymentStatus $current, PaymentStatus $incoming): bool
    {
        return self::rank($incoming) >= self::rank($current);
    }

    /**
     * O evento que chega é mais antigo que o estado já gravado?
     */
    public static function isOutOfOrder(PaymentStatus $current, PaymentStatus $incoming): bool
    {
        return ! self::allows($current, $incoming);
    }
}
