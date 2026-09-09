<?php

namespace App\Services\Signing\Contracts;

use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Signing\SignerNotifier;

/**
 * Ponto de extensão do fluxo público para a camada de envio.
 *
 * O signatário produz três fatos que exigem uma mensagem que **não é dele**: chegou a vez do
 * próximo, alguém recusou, o envelope foi encerrado. Emitir o link novo e escrever o e-mail é
 * responsabilidade de quem cuida do envio; o fluxo público apenas avisa.
 *
 * Divisão combinada entre os módulos: quem **muda estado** é este módulo
 * (`RecordAcceptance` / `RecordRefusal`) — avançar a ordem, marcar `refused`/`canceled`,
 * revogar links e sessões, gravar a trilha, tudo sob lock. Quem **produz mensagem** é a
 * implementação deste contrato. Um único escritor por transição; um único lugar que sabe
 * emitir link e escrever e-mail.
 *
 * Sem implementação registrada no container, {@see SignerNotifier}
 * apenas registra um aviso no log (sem token, sem código, sem e-mail completo) e segue: um
 * aceite gravado não é desfeito porque um e-mail não saiu.
 */
interface SignerNotifications
{
    /**
     * Chegou a vez destes destinatários no modo sequencial: emitir link e convidar.
     *
     * @param  list<Recipient>  $recipients
     */
    public function inviteRecipients(Envelope $envelope, array $recipients): void;

    /**
     * Um signatário concluiu o aceite: avisar o remetente (evento `recipient_signed`).
     */
    public function notifySenderSigned(Envelope $envelope, Recipient $signedBy): void;

    /**
     * Um signatário recusou: avisar o remetente com o motivo.
     */
    public function notifySenderRefused(Envelope $envelope, Recipient $refusedBy): void;

    /**
     * O envelope foi encerrado antes de concluir e os pendentes foram cancelados.
     *
     * @param  list<Recipient>  $canceled
     */
    public function notifyEnvelopeClosed(Envelope $envelope, array $canceled, string $reason): void;
}
