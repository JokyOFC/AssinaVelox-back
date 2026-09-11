<?php

namespace App\Integrations\Cpf;

use App\Integrations\Contracts\CpfVerificationProvider;
use Illuminate\Contracts\Foundation\Application;
use Psr\Log\LoggerInterface;

/**
 * Escolhe o adaptador da consulta cadastral de CPF por `assinavelox.cpf_lookup.driver`.
 *
 * - `disabled` / `own_service` (padrão): {@see OwnServiceCpfVerificationProvider}, que não
 *   chama nada e responde "inconclusivo — não configurado".
 * - `fake`: {@see FakeCpfVerificationProvider} (simulador identificado criado pela área
 *   C-CAN; reaproveitado aqui, não duplicado). Só com `assinavelox.channels.allow_simulated`
 *   ligado e fora de produção (o mesmo interruptor dos simuladores de SMS e WhatsApp); fora
 *   disso o simulador é recusado e vale o desabilitado.
 * - `bound`: o que estiver ligado a {@see CpfVerificationProvider} no contêiner — o gancho
 *   para a integração (IntegrationsServiceProvider) e para dublês de teste. Sem binding, vale
 *   o desabilitado.
 */
final class CpfVerificationFactory
{
    public function __construct(
        private readonly Application $app,
        private readonly LoggerInterface $logger,
    ) {}

    public function make(): CpfVerificationProvider
    {
        $driver = (string) config('assinavelox.cpf_lookup.driver', 'disabled');

        if ($driver === 'bound' && $this->app->bound(CpfVerificationProvider::class)) {
            return $this->app->make(CpfVerificationProvider::class);
        }

        if ($driver === 'fake') {
            $fake = $this->app->make(FakeCpfVerificationProvider::class);

            if ($fake->isConfigured()) {
                return $fake;
            }

            $this->logger->warning($this->app->environment('production')
                ? 'assinavelox.cpf_lookup.driver=fake é recusado em produção; consulta cadastral desabilitada.'
                : 'assinavelox.cpf_lookup.driver=fake com assinavelox.channels.allow_simulated desligado; consulta cadastral desabilitada.');
        }

        return $this->app->make(OwnServiceCpfVerificationProvider::class);
    }
}
