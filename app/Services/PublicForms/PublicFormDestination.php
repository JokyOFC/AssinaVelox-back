<?php

namespace App\Services\PublicForms;

/**
 * O que acontece depois que quem preencheu confirma o e-mail.
 *
 *  - `auto_send`: o documento é gerado e enviado para assinatura na mesma hora. Só vale
 *    para modelo em PDF fixo com campo de assinatura para cada participante que assina.
 *  - `review`: o documento é gerado em rascunho e fica na fila de revisão; ninguém é
 *    convidado até alguém da organização aprovar.
 */
enum PublicFormDestination: string
{
    case AutoSend = 'auto_send';
    case Review = 'review';

    public function label(): string
    {
        return match ($this) {
            self::AutoSend => 'Enviar automaticamente',
            self::Review => 'Fila de revisão',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AutoSend => 'Depois da confirmação do e-mail, o documento é gerado e enviado para assinatura na hora.',
            self::Review => 'Depois da confirmação do e-mail, o documento é gerado em rascunho e só é enviado quando alguém da equipe aprovar.',
        };
    }
}
