<?php

namespace App\Notifications\Contracts;

use App\Integrations\Email\DeliveryContext;

/**
 * Notificação cuja tentativa de envio é registrada em `delivery_attempts`.
 *
 * Toda notificação que sai pelo canal `App\Notifications\Channels\TrackedMailChannel`
 * precisa implementar isto: o contexto diz a que organização, envelope, destinatário e
 * propósito a mensagem pertence, e qual é o `correlation_id` que torna a repetição
 * idempotente.
 */
interface TracksDelivery
{
    public function deliveryContext(object $notifiable): DeliveryContext;
}
