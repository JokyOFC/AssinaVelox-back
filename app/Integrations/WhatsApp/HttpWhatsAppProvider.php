<?php

namespace App\Integrations\WhatsApp;

use App\Enums\DeliveryChannel;
use App\Integrations\Contracts\WhatsAppProvider;
use App\Integrations\Sms\OwnServiceMessagingProvider;

/**
 * Adaptador de PRODUÇÃO do serviço próprio de WhatsApp Business — DESABILITADO até existir
 * documentação (ver {@see OwnServiceMessagingProvider}). Nunca Evolution API, WPPConnect ou
 * Baileys.
 */
final class HttpWhatsAppProvider extends OwnServiceMessagingProvider implements WhatsAppProvider
{
    public const NAME = 'whatsapp_servico_proprio';

    public function channel(): DeliveryChannel
    {
        return DeliveryChannel::Whatsapp;
    }

    public function name(): string
    {
        return self::NAME;
    }

    protected function servicesKey(): string
    {
        return 'assinavelox_whatsapp';
    }

    protected function envPrefix(): string
    {
        return 'ASSINAVELOX_WHATSAPP';
    }

    protected function documentationGaps(): array
    {
        return [
            'Documentação oficial da API de WhatsApp Business do serviço próprio: endpoint de envio por template e de consulta de status.',
            'Templates pré-aprovados por finalidade (código de confirmação, convite, reenvio) com os nomes e parâmetros exatos.',
            'Esquema de autenticação e credenciais de homologação e de produção.',
            'Catálogo de erros e de status (aceito, entregue, lido, falha), limites de envio e custo por conversa.',
            'Formato do webhook de status: cabeçalhos, algoritmo da assinatura, janela de tempo e política de reentrega.',
            'Política de idempotência do serviço e o que acontece em tempo esgotado.',
            'Decisão entre número da operadora e número do cliente como remetente.',
        ];
    }
}
