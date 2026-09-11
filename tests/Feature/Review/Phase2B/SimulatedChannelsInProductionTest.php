<?php

use App\Enums\DeliveryChannel;
use App\Integrations\Cpf\CpfVerificationFactory;
use App\Integrations\Cpf\OwnServiceCpfVerificationProvider;
use App\Integrations\Sms\FakeSmsProvider;
use App\Integrations\WhatsApp\FakeWhatsAppProvider;
use App\Services\Signing\Channels\ChannelAvailability;

require_once __DIR__.'/../../Phase2/Channels/Support/ChannelHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da Fase 2, onda B — simulador de SMS/WhatsApp ativável em produção
|--------------------------------------------------------------------------
| Regra da onda: o adaptador Fake é identificado e a produção fica DESABILITADA. Os
| simuladores de CPF e de CNPJ são recusados em produção pela fábrica, independentemente de
| configuração (CpfVerificationFactory, CnpjLookupFactory). O simulador de SMS/WhatsApp, não:
| `SimulatedMessagingProvider::isConfigured()` só olha `assinavelox.channels.allow_simulated`,
| que vem de `ASSINAVELOX_CHANNELS_ALLOW_SIMULATED` (config/assinavelox.php:302). Um `.env`
| copiado de homologação para produção com essa variável em `true` liga o canal "simulado"
| no wizard e na página pública: o remetente escolhe SMS, o código nunca chega ao celular e a
| evidência registra "Código por SMS". A própria mensagem do simulador promete que ele
| "nunca funciona em produção" (SimulatedMessagingProvider::missingRequirements()).
*/

it('com APP_ENV=production, o simulador de SMS/WhatsApp continua disponível se a variável de simulação estiver ligada', function () {
    ['organization' => $organization] = createOrganizationWithOwner();
    channelsEnable($organization);

    // O .env de homologação levado para produção.
    config()->set('assinavelox.channels.allow_simulated', true);
    config()->set('assinavelox.cpf_lookup.driver', 'fake');

    $previous = app()['env'];
    app()['env'] = 'production';

    try {
        $sms = app(ChannelAvailability::class)->describe(DeliveryChannel::Sms, $organization);
        $whatsapp = app(ChannelAvailability::class)->describe(DeliveryChannel::Whatsapp, $organization);
        $cpf = app(CpfVerificationFactory::class)->make();

        // Controle: o simulador de CPF, na mesma situação, é recusado em produção.
        expect($cpf)->toBeInstanceOf(OwnServiceCpfVerificationProvider::class);

        expect($sms['provider'])->toBe(FakeSmsProvider::NAME)
            ->and($whatsapp['provider'])->toBe(FakeWhatsAppProvider::NAME)
            ->and($sms['available'])->toBeFalse('SMS simulado disponível em produção')
            ->and($whatsapp['available'])->toBeFalse('WhatsApp simulado disponível em produção')
            ->and(app(FakeSmsProvider::class)->isConfigured())->toBeFalse();
    } finally {
        app()['env'] = $previous;
    }
});
