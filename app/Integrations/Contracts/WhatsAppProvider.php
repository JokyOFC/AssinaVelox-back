<?php

namespace App\Integrations\Contracts;

use App\Integrations\Dto\DeliveryReceipt;

/**
 * Fase 2/3 — sem implementação. Mensagens WhatsApp via API oficial (templates
 * aprovados); nenhuma solução não oficial (Evolution/WPPConnect/Baileys) será
 * adotada.
 */
interface WhatsAppProvider
{
    /**
     * @param  string  $toE164  número no formato E.164
     * @param  array<string, string>  $parameters  variáveis do template aprovado
     */
    public function sendTemplate(string $toE164, string $template, array $parameters = [], ?string $correlationId = null): DeliveryReceipt;

    public function isConfigured(): bool;

    public function name(): string;
}
