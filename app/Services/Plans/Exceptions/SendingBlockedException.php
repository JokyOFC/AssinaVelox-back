<?php

namespace App\Services\Plans\Exceptions;

use App\Models\Subscription;
use RuntimeException;

/**
 * O plano da organização não permite enviar agora. `errorCode` é estável (para testes e
 * telemetria); a mensagem é a que o usuário lê, em PT-BR.
 */
class SendingBlockedException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $remaining = null,
    ) {
        parent::__construct($message);
    }

    public static function noSubscription(): self
    {
        return new self(
            'no_subscription',
            'Esta conta não tem um plano ativo. Escolha um plano para enviar documentos.',
        );
    }

    public static function pastDue(Subscription $subscription): self
    {
        return new self(
            'subscription_past_due',
            'O pagamento do plano está em atraso, então novos envios estão bloqueados. Regularize a cobrança para voltar a enviar.',
        );
    }

    public static function subscriptionInactive(Subscription $subscription): self
    {
        return new self(
            'subscription_inactive',
            'O plano desta conta está '.mb_strtolower($subscription->status->label()).' e não permite novos envios.',
        );
    }

    public static function quotaExhausted(int $quota): self
    {
        return new self(
            'quota_exhausted',
            "Você já usou os {$quota} documentos do seu plano neste período. Faça upgrade para continuar enviando.",
            0,
        );
    }
}
