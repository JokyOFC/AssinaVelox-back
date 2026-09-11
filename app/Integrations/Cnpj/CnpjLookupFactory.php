<?php

namespace App\Integrations\Cnpj;

use App\Integrations\Contracts\CnpjLookupProvider;
use Illuminate\Contracts\Foundation\Application;
use Psr\Log\LoggerInterface;

/**
 * Escolhe o adaptador de CNPJ pela configuração `assinavelox.cnpj.driver`.
 *
 * - `minha_receita` (padrão): a instância pública documentada (ou a auto-hospedada em
 *   `base_url`).
 * - `fake`: simulador identificado. Em produção é RECUSADO — o formulário não pode ser
 *   autopreenchido com dado inventado — e cai no adaptador real com um aviso no log.
 *
 * Fábrica em vez de binding no contêiner para não depender do IntegrationsServiceProvider
 * (fora da área C-ID); a integração pode acrescentar `bind(CnpjLookupProvider::class, ...)`
 * apontando para {@see self::make()}.
 */
final class CnpjLookupFactory
{
    public function __construct(
        private readonly Application $app,
        private readonly LoggerInterface $logger,
    ) {}

    public function make(): CnpjLookupProvider
    {
        $driver = (string) config('assinavelox.cnpj.driver', 'minha_receita');

        if ($driver === 'fake') {
            if (! $this->app->environment('production')) {
                return $this->app->make(FakeCnpjLookup::class);
            }

            $this->logger->warning('assinavelox.cnpj.driver=fake é recusado em produção; usando o Minha Receita.');
        }

        return $this->app->make(MinhaReceitaCnpjLookup::class);
    }
}
