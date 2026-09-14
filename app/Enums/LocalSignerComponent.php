<?php

namespace App\Enums;

use App\Integrations\LocalSigner\FakeLocalSigner;
use App\Integrations\LocalSigner\NexuLocalSigner;

/**
 * Componente que fala com o token/cartão na máquina do participante (Fase 3 §3.4).
 *
 * - `simulated`: {@see FakeLocalSigner} — PKCS#12 de TESTE no
 *   servidor, só em ambiente de teste/local. Nenhum token é usado.
 * - `nexu`: {@see NexuLocalSigner} — API local do NexU (fork
 *   comunitário). Produção DESABILITADA até o piloto (docs/fase-3/assinatura-externa-a3.md).
 */
enum LocalSignerComponent: string
{
    case Simulated = 'simulated';
    case Nexu = 'nexu';

    public function label(): string
    {
        return match ($this) {
            self::Simulated => 'Simulador de componente local (simulado — nenhum token foi usado)',
            self::Nexu => 'NexU (componente local para token/cartão)',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
