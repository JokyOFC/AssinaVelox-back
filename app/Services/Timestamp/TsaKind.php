<?php

namespace App\Services\Timestamp;

/**
 * Origem de um carimbo do tempo (roadmap T3). O rótulo diz exatamente o que o carimbo é.
 *
 * - `operator`: TSA da própria AssinaVelox — "carimbo do tempo da operadora — não é carimbo
 *   ICP-Brasil";
 * - `commercial`: TSA comercial contratada (reservado, sem implementação);
 * - `icp_brasil`: SÓ ACT credenciada pelo ITI (DOC-ICP-11 §2.7.2) — nenhum provedor
 *   configurado hoje, e o serviço que grava recusa o valor;
 * - `simulated`: simulador identificado, sem valor jurídico e sem RFC 3161.
 */
enum TsaKind: string
{
    case Operator = 'operator';
    case Commercial = 'commercial';
    case IcpBrasil = 'icp_brasil';
    case Simulated = 'simulated';

    public const OPERATOR_LABEL = 'Carimbo do tempo da operadora — não é carimbo ICP-Brasil';

    public function label(): string
    {
        return match ($this) {
            self::Operator => self::OPERATOR_LABEL,
            self::Commercial => 'Carimbo do tempo de TSA comercial — não é carimbo ICP-Brasil',
            self::IcpBrasil => 'Emitido por ACT credenciada pelo ITI (ICP-Brasil)',
            self::Simulated => 'Carimbo do tempo SIMULADO — sem valor jurídico',
        };
    }

    /**
     * O que o carimbo prova, em uma frase (para evidências, verificação e README do dossiê).
     */
    public function statement(): string
    {
        return match ($this) {
            self::Operator => 'A AssinaVelox, operadora da plataforma, atesta com a chave da própria TSA que este resumo existia no horário indicado. Não é carimbo emitido por Autoridade de Carimbo do Tempo da ICP-Brasil.',
            self::Commercial => 'Uma TSA comercial atesta que este resumo existia no horário indicado. Não é carimbo ICP-Brasil.',
            self::IcpBrasil => 'Emitido por autoridade credenciada pelo ITI.',
            self::Simulated => 'Carimbo simulado para desenvolvimento: não é RFC 3161 e não prova nada.',
        };
    }
}
