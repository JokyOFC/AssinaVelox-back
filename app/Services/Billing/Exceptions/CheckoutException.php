<?php

namespace App\Services\Billing\Exceptions;

use RuntimeException;

/**
 * O checkout não pôde ser iniciado. `errorCode` é estável (testes e telemetria); a
 * mensagem é a que o usuário lê, em PT-BR.
 *
 * `inconclusive` marca o caso em que a criação da preferência ficou sem resposta: o
 * pagamento local continua `pending` e sem preferência, e a próxima tentativa
 * **consulta o provedor antes de criar outra**.
 */
class CheckoutException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $inconclusive = false,
    ) {
        parent::__construct($message);
    }

    public static function gatewayDisabled(): self
    {
        return new self(
            'gateway_disabled',
            'O pagamento online ainda não está disponível nesta instalação: falta configurar as credenciais do Mercado Pago. Fale com o suporte para contratar um plano.',
        );
    }

    public static function planNotPayable(): self
    {
        return new self(
            'plan_not_payable',
            'Este plano não é contratado por pagamento online.',
        );
    }

    public static function inconclusive(): self
    {
        return new self(
            'inconclusive',
            'Não conseguimos confirmar a criação do checkout com o Mercado Pago. Nada foi cobrado. Tente novamente em alguns instantes — se a cobrança já tiver sido criada, ela será reaproveitada.',
            true,
        );
    }

    public static function rejected(): self
    {
        return new self(
            'rejected',
            'O Mercado Pago recusou a criação do checkout. Tente novamente mais tarde ou fale com o suporte.',
        );
    }
}
