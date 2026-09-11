<?php

use App\Integrations\Cpf\CpfVerificationFactory;
use App\Integrations\Cpf\FakeCpfVerificationProvider;
use App\Integrations\Sms\FakeSmsProvider;

/*
|--------------------------------------------------------------------------
| Revisão adversarial da Fase 2, onda B — o simulador de CPF ignora o interruptor de simulação
|--------------------------------------------------------------------------
| docs/fase-2/identidade.md §2.2: o driver `fake` "Só responde com
| `assinavelox.channels.allow_simulated` ligado". O código não faz isso:
| FakeCpfVerificationProvider::isConfigured() devolve `true` sempre ("quem impede o uso em
| produção é a fábrica") e CpfVerificationFactory só olha APP_ENV. Numa homologação com a
| simulação DESLIGADA (ex.: um ambiente de aceite do cliente, APP_ENV=staging), o simulador de CPF
| responde e grava `cpf_check` "simulado" nos aceites, enquanto os simuladores de SMS/WhatsApp,
| com o mesmo interruptor, ficam corretamente indisponíveis. O interruptor que a documentação
| diz controlar os simuladores não controla este.
*/

it('com allow_simulated desligado, o simulador de CPF continua respondendo', function () {
    config()->set('assinavelox.channels.allow_simulated', false);
    config()->set('assinavelox.cpf_lookup.driver', 'fake');

    $previous = app()['env'];
    app()['env'] = 'staging';

    try {
        $provider = app(CpfVerificationFactory::class)->make();

        // Controle: com o mesmo interruptor, o simulador de SMS fica indisponível.
        expect(app(FakeSmsProvider::class)->isConfigured())->toBeFalse();

        expect($provider instanceof FakeCpfVerificationProvider && $provider->isConfigured())
            ->toBeFalse('O simulador de CPF responde com ASSINAVELOX_CHANNELS_ALLOW_SIMULATED=false.');
    } finally {
        app()['env'] = $previous;
    }
});
