<?php

namespace App\Services\Risk;

use RuntimeException;

/**
 * Falha de domínio do antifraude (decisão, pedido de revisão). `errorCode` é estável; a
 * mensagem é o texto PT-BR mostrado a quem agiu.
 */
final class RiskException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function reasonRequired(): self
    {
        return new self('reason_required', 'Descreva o motivo da decisão (pelo menos 10 caracteres).');
    }

    public static function alreadyDecided(): self
    {
        return new self('already_decided', 'Este caso já foi decidido. Um novo caso é aberto se surgirem sinais ou um pedido de revisão.');
    }

    public static function caseChanged(): self
    {
        return new self('case_changed', 'O caso mudou enquanto você analisava: chegaram sinais novos. Recarregue a página e revise os sinais antes de decidir.');
    }

    public static function conflictOfInterest(): self
    {
        return new self('conflict_of_interest', 'Você é membro desta organização e não pode decidir o caso dela. Outra pessoa da equipe precisa revisar.');
    }

    public static function appealNotApplicable(): self
    {
        return new self('appeal_not_applicable', 'Não há restrição nem observação ativa nesta conta, então não há o que revisar.');
    }

    public static function appealAlreadyRequested(): self
    {
        return new self('appeal_already_requested', 'Já existe um pedido de revisão em análise para esta conta.');
    }
}
