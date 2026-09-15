<?php

namespace App\Services\BulkGeneration;

/**
 * Situação de um lote (docs/fase-3/geracao-em-lote.md §3).
 *
 *   draft ──mapear+validar──▶ validated ──confirmar (reserva a cota)──▶ running ──▶ completed
 *     │                          │                                        │
 *     └────────── descartar ─────┘                                        └─cancelar─▶ canceled
 */
enum BulkGenerationStatus: string
{
    case Draft = 'draft';
    case Validated = 'validated';
    case Running = 'running';
    case Completed = 'completed';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Mapeando colunas',
            self::Validated => 'Pré-validado',
            self::Running => 'Gerando',
            self::Completed => 'Concluído',
            self::Canceled => 'Cancelado',
        };
    }

    /** Ainda editável: o arquivo pode ser remapeado e o lote descartado. */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Validated;
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Canceled;
    }
}
