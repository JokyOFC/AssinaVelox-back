<?php

namespace App\Integrations\Identity;

use App\Integrations\Contracts\IdentityVerificationProvider;
use App\Integrations\Identity\Verifiky\VerifikyIdentityVerificationProvider;
use Illuminate\Contracts\Foundation\Application;
use Psr\Log\LoggerInterface;

/**
 * Escolhe o adaptador da verificação facial por `assinavelox.identity_verification.driver`
 * (o mesmo desenho de App\Integrations\Cpf\CpfVerificationFactory):
 *
 * - `disabled` (padrão): {@see DisabledIdentityVerificationProvider} — não chama nada.
 * - `verifiky`: {@see VerifikyIdentityVerificationProvider}. Escolhido mesmo sem chave: aí ele
 *   próprio responde "não configurado", e a tela diz isso em vez de simular em silêncio.
 * - `fake`: {@see FakeIdentityVerificationProvider}, só com `channels.allow_simulated` ligado e
 *   fora de produção; recusado, vale o desligado.
 */
final class IdentityVerificationFactory
{
    public function __construct(
        private readonly Application $app,
        private readonly LoggerInterface $logger,
    ) {}

    public function make(): IdentityVerificationProvider
    {
        $driver = (string) config('assinavelox.identity_verification.driver', 'disabled');

        if ($driver === 'verifiky') {
            return $this->app->make(VerifikyIdentityVerificationProvider::class);
        }

        if ($driver === 'fake') {
            $fake = $this->app->make(FakeIdentityVerificationProvider::class);

            if ($fake->isConfigured()) {
                return $fake;
            }

            $this->logger->warning($this->app->environment('production')
                ? 'assinavelox.identity_verification.driver=fake é recusado em produção; verificação facial desabilitada.'
                : 'assinavelox.identity_verification.driver=fake com assinavelox.channels.allow_simulated desligado; verificação facial desabilitada.');
        }

        return $this->app->make(DisabledIdentityVerificationProvider::class);
    }
}
