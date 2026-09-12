<?php

namespace App\Services\Webhooks;

use App\Models\Organization;
use App\Services\Envelopes\DomainFeatures;

/**
 * Flag `outbound_webhooks` (roadmap §1 T8): interruptor global da instalação E
 * `plans.features.outbound_webhooks` do plano vigente. Nasce desligada.
 *
 * Desligada: o gancho da trilha não cria entrega nenhuma (nem consulta o banco quando o
 * interruptor global está desligado), a varredura de retentativas não faz nada, entregas já
 * enfileiradas são canceladas ao serem processadas e as rotas de gestão respondem 404.
 *
 * Contrato para HandleInertiaRequests::features() (fora desta área): a chave
 * `outbound_webhooks` deve vir de {@see self::enabled()} para a organização corrente.
 */
final class WebhooksFeature
{
    public const FLAG = 'outbound_webhooks';

    public static function globallyEnabled(): bool
    {
        return config('assinavelox.features.'.self::FLAG, false) === true;
    }

    public static function enabled(?Organization $organization): bool
    {
        return DomainFeatures::enabled(self::FLAG, $organization);
    }
}
