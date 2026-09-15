<?php

namespace App\Services\BulkGeneration;

/**
 * O que aconteceu com o envelope de uma linha gerada (`bulk_generation_rows.outcome`).
 */
enum BulkRowOutcome: string
{
    /** Pronto para enviar, sem envio pedido ("revisar antes de enviar"). */
    case Ready = 'ready';
    /** Rascunho com pendência (ex.: modelo sem campos posicionados). */
    case Draft = 'draft';
    case Sent = 'sent';
    case Scheduled = 'scheduled';
    /** Envio pedido, mas não aconteceu (motivo em `outcome_message`). O envelope existe. */
    case NotSent = 'not_sent';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Pronto para enviar',
            self::Draft => 'Rascunho com pendência',
            self::Sent => 'Enviado',
            self::Scheduled => 'Envio agendado',
            self::NotSent => 'Gerado, não enviado',
        };
    }
}
