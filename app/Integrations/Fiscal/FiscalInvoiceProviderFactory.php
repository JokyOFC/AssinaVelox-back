<?php

namespace App\Integrations\Fiscal;

use App\Integrations\Contracts\FiscalInvoiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * Escolhe o provedor de NFS-e a partir de `assinavelox.fiscal.provider` (Fase 2, onda D).
 *
 * - `none` (padrão): nenhum provedor — cada pagamento mostra "não emitida — integração fiscal
 *   pendente";
 * - `simulated`: o simulador identificado que já existe desde a onda B
 *   (`FakeFiscalInvoiceProvider`), só fora de produção; ele nunca emite nota;
 * - `sefin_nacional`: o adaptador do Sistema Nacional, desabilitado (isConfigured = false).
 *
 * O binding do contrato no container (`IntegrationsServiceProvider`, fora desta área) continua
 * como está; esta fábrica é o ponto de escolha usado pelos serviços de `App\Services\Fiscal`.
 */
final class FiscalInvoiceProviderFactory
{
    public const MODE_NONE = 'none';

    public const MODE_SIMULATED = 'simulated';

    public const MODE_SEFIN = 'sefin_nacional';

    public function __construct(
        private readonly Application $app,
        private readonly Repository $config,
    ) {}

    /**
     * @return 'none'|'simulated'|'sefin_nacional'
     */
    public function mode(): string
    {
        return match ((string) $this->config->get('assinavelox.fiscal.provider', self::MODE_NONE)) {
            self::MODE_SIMULATED => $this->app->environment('production') ? self::MODE_NONE : self::MODE_SIMULATED,
            self::MODE_SEFIN => self::MODE_SEFIN,
            default => self::MODE_NONE,
        };
    }

    public function make(): ?FiscalInvoiceProvider
    {
        return match ($this->mode()) {
            self::MODE_SIMULATED => $this->app->make(FakeFiscalInvoiceProvider::class),
            self::MODE_SEFIN => new SefinNacionalFiscalInvoiceProvider,
            default => null,
        };
    }
}
