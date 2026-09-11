<?php

namespace App\Integrations\Cnpj;

use App\Integrations\Contracts\CnpjLookupProvider;
use App\Support\TaxId;
use Psr\Log\LoggerInterface;

/**
 * Consulta de CNPJ **SIMULADA** (desenvolvimento e testes). Identificada no nome
 * (`fake_cnpj`), no log (aviso a cada uso) e no resultado (`details.simulated = true`,
 * razão social terminando em "(simulado)"), para que nenhum dado inventado pareça real.
 *
 * Fixtures: {@see self::NOT_FOUND} responde "não encontrado"; {@see self::UNAVAILABLE}
 * simula tempo esgotado; qualquer outro CNPJ válido devolve uma empresa simulada.
 *
 * Recusado em produção por {@see CnpjLookupFactory}.
 */
final class FakeCnpjLookup implements CnpjLookupProvider
{
    public const NAME = 'fake_cnpj';

    public const NOT_FOUND = '11444777000161';

    public const UNAVAILABLE = '19131243000197';

    public function __construct(private readonly LoggerInterface $logger) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function lookup(string $cnpj, ?string $correlationId = null): array
    {
        $digits = TaxId::digits($cnpj);

        $this->logger->warning('[SIMULADO] Consulta de CNPJ respondida pelo adaptador fake (nenhum dado real).', [
            'cnpj' => TaxId::mask($digits),
            'correlation_id' => $correlationId,
        ]);

        if ($digits === self::UNAVAILABLE) {
            throw new CnpjLookupUnavailable('simulated_timeout', 'Consulta de CNPJ simulada: tempo esgotado.');
        }

        if ($digits === self::NOT_FOUND) {
            return ['found' => false, 'provider' => self::NAME, 'details' => ['reason' => 'not_found', 'simulated' => true]];
        }

        return [
            'found' => true,
            'provider' => self::NAME,
            'legal_name' => 'EMPRESA SIMULADA LTDA (simulado)',
            'trade_name' => 'Empresa Simulada (simulado)',
            'status' => 'ATIVA',
            'address' => [
                'street' => 'RUA SIMULADA',
                'number' => '100',
                'district' => 'CENTRO',
                'city' => 'SÃO PAULO',
                'state' => 'SP',
                'postal_code' => '01000000',
            ],
            'details' => ['simulated' => true],
        ];
    }
}
