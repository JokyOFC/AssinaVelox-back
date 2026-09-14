<?php

namespace App\Services\Risk;

/**
 * Situação de um caso da fila de revisão (`risk_reviews.status`). O roadmap §3.7 previa
 * open | cleared | confirmed; `watching` foi acrescentado para a decisão "manter em
 * observação", que não é nem liberação nem confirmação (docs/fase-3/antifraude.md §4).
 */
enum RiskReviewStatus: string
{
    case Open = 'open';
    case Watching = 'watching';
    case Cleared = 'cleared';
    case Confirmed = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Aguardando revisão',
            self::Watching => 'Mantido em observação',
            self::Cleared => 'Liberado',
            self::Confirmed => 'Restrição confirmada',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status): array => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
