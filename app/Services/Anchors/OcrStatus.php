<?php

namespace App\Services\Anchors;

/**
 * `documents.ocr_status` (Fase 3 §3.2). Nulo = nunca avaliado (flags desligadas).
 */
enum OcrStatus: string
{
    /** Todas as páginas têm texto selecionável: a busca de âncoras dispensa OCR. */
    case NotNeeded = 'not_needed';
    case Pending = 'pending';
    case Done = 'done';
    case Failed = 'failed';
    /** Há páginas sem texto, mas o OCR não está disponível neste servidor. */
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::NotNeeded => 'Texto selecionável — OCR dispensado',
            self::Pending => 'Lendo páginas escaneadas…',
            self::Done => 'Páginas escaneadas lidas por OCR',
            self::Failed => 'Falha no OCR — posicione os campos manualmente',
            self::Unavailable => 'OCR indisponível neste servidor',
        };
    }

    public static function fromStored(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}
