<?php

namespace App\Integrations\Timestamp;

use App\Integrations\Contracts\Exceptions\ProviderDisabledException;
use App\Integrations\Contracts\TimestampProvider;

/**
 * Carimbo do tempo ICP-Brasil por ACT credenciada pelo ITI (roadmap §3.6) — CONTRATO, com a
 * produção DESABILITADA.
 *
 * Pelo DOC-ICP-11 §2.7.2 só um SCT de ACT credenciada, auditado e sincronizado pela EAT
 * produz carimbo aceito na ICP-Brasil. Não há contrato com ACT (viabilidade §4.2 item 7):
 * `isConfigured()` é sempre false e `timestamp()` lança {@see ProviderDisabledException}
 * dizendo o que falta. Não existe endpoint inventado aqui (T4).
 *
 * Esta é a ÚNICA classe que um dia poderá produzir `tsa_kind = icp_brasil`, e mesmo então
 * só depois de conferir a cadeia até a raiz ICP-Brasil. Para desenvolvimento há o
 * simulador {@see FakeIcpBrasilTimestampProvider}, que nunca grava `icp_brasil`.
 */
final class IcpBrasilTimestampProvider implements TimestampProvider
{
    public const NAME = 'act_icp_brasil';

    /** O que o proprietário precisa providenciar (docs/integracoes/carimbo-do-tempo-e-ltv.md §6). */
    public const MISSING = [
        'Contrato com uma ACT credenciada pelo ITI (ex.: ACT SERPRO), e-CNPJ e credenciais (Consumer Key/Secret) — viabilidade §4.2 item 7.',
        'Homologação do formato do endpoint da ACT (TSQ/TSR em DER ou JSON) e renovação do token OAuth2 por variável de ambiente.',
        'Preço por carimbo (define a cota por plano).',
        'Raiz ICP-Brasil fixada por impressão digital para conferir a cadeia antes de gravar icp_brasil.',
    ];

    public function name(): string
    {
        return self::NAME;
    }

    public function isSimulated(): bool
    {
        return false;
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function timestamp(string $digestHex, string $hashAlgorithm = 'sha256', ?string $correlationId = null): array
    {
        throw new ProviderDisabledException(self::NAME, self::MISSING);
    }
}
