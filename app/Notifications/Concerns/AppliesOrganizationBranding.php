<?php

namespace App\Notifications\Concerns;

use App\Models\Organization;
use App\Services\Branding\BrandingPresenter;
use App\Services\Branding\ParticipantMailSender;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Marca da organização nos e-mails AO PARTICIPANTE (Fase 2 §2.8, docs/fase-2/branding.md §5).
 *
 * Com a marca inativa (flag desligada ou nada salvo) a mensagem volta intacta — mesmo
 * template (`notifications::email`), mesmo remetente, sem Reply-To: é o e-mail da Fase 1.
 *
 * Com a marca ativa:
 * - o template passa a ser `mail.branded`: cabeçalho com logo e nome da organização,
 *   botão na cor primária e rodapé com "via AssinaVelox". **Nenhuma linha do texto muda**
 *   — saudação, parágrafos, avisos ("não encaminhe", prazo, código) e assinatura são os
 *   mesmos da mensagem montada pela notificação;
 * - `Reply-To` com o e-mail da organização, quando configurado;
 * - `From` próprio só com domínio verificado ({@see ParticipantMailSender}).
 *
 * Roda no job da fila (no `toMail`), então usa a marca vigente no momento do envio.
 */
trait AppliesOrganizationBranding
{
    protected function applyOrganizationBranding(MailMessage $message, ?Organization $organization): MailMessage
    {
        $brand = app(BrandingPresenter::class)->forEmail($organization);

        if ($brand === null) {
            return $message;
        }

        $message->markdown('mail.branded', ['brand' => $brand]);

        $sender = app(ParticipantMailSender::class)->resolve($organization);

        if ($sender['reply_to'] !== null) {
            $message->replyTo($sender['reply_to'], $sender['reply_to_name']);
        }

        if ($sender['from_address'] !== null) {
            $message->from($sender['from_address'], $sender['from_name']);
        }

        return $message;
    }
}
