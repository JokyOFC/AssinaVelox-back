<?php

namespace App\Services\Envelopes\Contracts;

use App\Models\Recipient;

/**
 * Ponto de extensão entre a edição de destinatários (B-FIELDS) e o envio (B-SEND).
 *
 * Quando o nome ou o e-mail de um destinatário já notificado muda, o link antigo é
 * REVOGADO imediatamente por `RecipientSync` (invariante de segurança: quem tinha o
 * e-mail anterior não pode continuar acessando o documento). A emissão de um novo
 * `recipient_access_link` e o reenvio do convite são responsabilidade do envio.
 *
 * O contrato é opcional: `RecipientSync` só chama a implementação quando ela está
 * registrada no container (`app()->bound(RotatesInvitations::class)`). Sem implementação,
 * a interface avisa o remetente para reenviar o convite manualmente.
 */
interface RotatesInvitations
{
    /**
     * Emite um novo link de convite para o destinatário e despacha a notificação.
     *
     * Devolve `false` quando a rotação não se aplica — envelope ainda em preparo (não há
     * versão congelada nem link a emitir), envelope terminal, ou destinatário que ainda
     * aguarda a vez no sequencial. A interface precisa saber a diferença: dizer "novo
     * convite enviado" quando nada foi enviado é pior do que pedir o reenvio manual.
     *
     * @param  string  $reason  Motivo curto para a trilha (ex.: `recipient_updated`).
     * @return bool `true` se um convite realmente saiu
     */
    public function rotate(Recipient $recipient, string $reason): bool;
}
