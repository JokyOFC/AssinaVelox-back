<?php

namespace App\Services\Billing\Exceptions;

use RuntimeException;

/**
 * Uma operação financeira (estorno, cancelamento, conciliação) não pôde ser feita — Fase 2,
 * onda D. `errorCode` é estável (testes e telemetria); a mensagem é a que o usuário lê, em PT-BR,
 * e nunca carrega segredo nem dado do pagador.
 */
class BillingActionException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function featureDisabled(): self
    {
        return new self('feature_disabled', 'Este recurso de cobrança não está habilitado nesta instalação.');
    }

    public static function providerMismatch(): self
    {
        return new self('provider_mismatch', 'Este pagamento foi registrado por outro gateway (ou pelo dublê de desenvolvimento) e não pode ser operado pelo gateway atual.');
    }

    public static function notRefundable(): self
    {
        return new self('not_refundable', 'Só pagamentos aprovados pelo Mercado Pago podem ser estornados.');
    }

    public static function tooOld(int $days): self
    {
        return new self('too_old', "O Mercado Pago só permite estornar pagamentos aprovados há até {$days} dias.");
    }

    public static function ownerNotAllowed(): self
    {
        return new self('owner_not_allowed', 'Pela política de estornos desta instalação, só a equipe da plataforma pode estornar pagamentos. Fale com o suporte.');
    }

    public static function ownerTotalOnly(): self
    {
        return new self('owner_total_only', 'O proprietário da conta só pode pedir o estorno integral de um pagamento ainda não estornado. Estornos parciais são feitos pela equipe da plataforma.');
    }

    public static function ownerWindowExpired(int $days): self
    {
        return new self('owner_window_expired', "O prazo para o proprietário pedir estorno ({$days} dias após a aprovação) terminou. Fale com o suporte.");
    }

    public static function refundInProgress(): self
    {
        return new self('refund_in_progress', 'Já existe um estorno em andamento para este pagamento. Aguarde a confirmação do Mercado Pago antes de pedir outro.');
    }

    public static function nothingToRefund(): self
    {
        return new self('nothing_to_refund', 'Este pagamento já foi estornado por inteiro.');
    }

    public static function invalidAmount(string $remaining): self
    {
        return new self('invalid_amount', "Informe um valor entre R$ 0,01 e {$remaining}, o saldo ainda estornável deste pagamento.");
    }

    public static function idempotencyConflict(): self
    {
        return new self('idempotency_conflict', 'Esta chave de pedido já foi usada para outro pagamento. Abra o formulário de novo.');
    }

    public static function notCancellable(): self
    {
        return new self('not_cancellable', 'Só pagamentos pendentes podem ser cancelados. Pagamentos aprovados são desfeitos por estorno.');
    }

    public static function cancelRejected(): self
    {
        return new self('cancel_rejected', 'O Mercado Pago recusou o cancelamento: o pagamento não está mais pendente. Atualizamos o status a partir do provedor.');
    }

    public static function cancelInconclusive(): self
    {
        return new self('cancel_inconclusive', 'Não conseguimos confirmar o cancelamento com o Mercado Pago. Nada foi alterado aqui; o status será atualizado quando o provedor responder. Tente de novo em alguns instantes.');
    }
}
