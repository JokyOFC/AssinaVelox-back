<?php

namespace App\Integrations\Sms;

use App\Enums\DeliveryChannel;
use App\Integrations\Contracts\SmsProvider;

/**
 * Adaptador de PRODUÇÃO do serviço próprio de SMS — DESABILITADO até existir documentação
 * (ver {@see OwnServiceMessagingProvider} e docs/fase-2/canais-e-pin.md §8).
 */
final class HttpSmsProvider extends OwnServiceMessagingProvider implements SmsProvider
{
    public const NAME = 'sms_servico_proprio';

    public function channel(): DeliveryChannel
    {
        return DeliveryChannel::Sms;
    }

    public function name(): string
    {
        return self::NAME;
    }

    protected function servicesKey(): string
    {
        return 'assinavelox_sms';
    }

    protected function envPrefix(): string
    {
        return 'ASSINAVELOX_SMS';
    }

    protected function documentationGaps(): array
    {
        return [
            'Documentação oficial da API de SMS do serviço próprio: endpoint de envio e endpoint de consulta de status.',
            'Esquema de autenticação e credenciais de homologação e de produção.',
            'Catálogo de erros e de status (como o serviço informa aceito, entregue, falha e devolvido).',
            'Limites de envio (por segundo, por dia, por destinatário) e custo por mensagem, para os limites por plano.',
            'Formato do webhook de status: cabeçalhos, algoritmo da assinatura, janela de tempo e política de reentrega.',
            'Política de idempotência do serviço (chave aceita, janela de deduplicação) e o que acontece em tempo esgotado.',
            'Remetente aprovado para o envio (número curto, número longo ou nome).',
        ];
    }
}
