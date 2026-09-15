<?php

namespace App\Services\BulkGeneration;

/**
 * Situação de uma linha do lote.
 *
 *   valid | invalid                         (pré-validação; nenhum envelope)
 *   pending ──▶ queued ──▶ processing ──▶ created | failed
 *      └─────────┴── cancelar ──▶ canceled
 */
enum BulkRowStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Created = 'created';
    case Failed = 'failed';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Válida',
            self::Invalid => 'Com erro',
            self::Pending => 'Aguardando',
            self::Queued => 'Na fila',
            self::Processing => 'Gerando',
            self::Created => 'Gerado',
            self::Failed => 'Falhou',
            self::Canceled => 'Cancelada',
        };
    }

    /**
     * Linhas que ainda vão (ou podem) virar envelope.
     *
     * @return list<string>
     */
    public static function inFlightValues(): array
    {
        return [self::Pending->value, self::Queued->value, self::Processing->value];
    }
}
