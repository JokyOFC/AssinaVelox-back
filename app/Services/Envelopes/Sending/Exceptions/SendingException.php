<?php

namespace App\Services\Envelopes\Sending\Exceptions;

use RuntimeException;

/**
 * Falha de domínio no envio/reenvio. `errorCode` é estável (testes, telemetria); a
 * `message` é o texto PT-BR exibido ao usuário.
 */
class SendingException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function invalidStatus(): self
    {
        return new self('invalid_status', 'Ação indisponível no status atual.');
    }

    public static function alreadySent(): self
    {
        return new self('already_sent', 'Este documento já foi enviado para assinatura.');
    }

    /**
     * @param  list<string>  $issues
     */
    public static function incomplete(array $issues): self
    {
        return new self(
            'incomplete',
            $issues === []
                ? 'Este documento ainda não está pronto para envio.'
                : 'Não é possível enviar: '.mb_strtolower(mb_substr($issues[0], 0, 1)).mb_substr($issues[0], 1),
            ['issues' => $issues],
        );
    }

    public static function noSentVersion(): self
    {
        return new self('no_sent_version', 'O documento ainda não tem uma versão preparada para assinatura.');
    }

    public static function nothingToResend(): self
    {
        return new self('nothing_to_resend', 'Não há signatários pendentes para reenviar.');
    }

    public static function resendThrottled(int $minutes): self
    {
        return new self(
            'resend_throttled',
            "O convite foi reenviado há pouco. Aguarde {$minutes} minuto(s) antes de reenviar de novo.",
            ['retry_after_minutes' => $minutes],
        );
    }

    public static function resendLimitReached(int $max): self
    {
        return new self(
            'resend_limit',
            "Este signatário já recebeu o número máximo de reenvios ({$max}). Edite o e-mail dele ou fale por outro canal.",
            ['max' => $max],
        );
    }

    public static function recipientNotPending(): self
    {
        return new self('recipient_not_pending', 'Este signatário não está mais aguardando assinatura.');
    }

    public static function notTheirTurn(): self
    {
        return new self('not_their_turn', 'Este signatário ainda não chegou a vez dele na ordem de assinatura.');
    }

    public static function dispatchFailed(): self
    {
        return new self(
            'dispatch_failed',
            'Não foi possível emitir os convites agora. Nenhum documento foi descontado do seu plano — tente enviar novamente em instantes.',
        );
    }
}
