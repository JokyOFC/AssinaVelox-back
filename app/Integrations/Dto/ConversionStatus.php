<?php

namespace App\Integrations\Dto;

use App\Enums\DocumentProcessingStatus;

/**
 * Destino do documento após a conversão/inspeção.
 *
 * - ready: PDF utilizável para preparação.
 * - blocked: o documento em si não pode ser preparado (PDF protegido por senha,
 *   já assinado digitalmente, inválido). Original preservado; não repetir.
 * - failed: a conversão falhou (imagem inválida, LibreOffice sem saída...).
 */
enum ConversionStatus: string
{
    case Ready = 'ready';
    case Blocked = 'blocked';
    case Failed = 'failed';

    public function toDocumentProcessingStatus(): DocumentProcessingStatus
    {
        return match ($this) {
            self::Ready => DocumentProcessingStatus::Ready,
            self::Blocked => DocumentProcessingStatus::Blocked,
            self::Failed => DocumentProcessingStatus::Failed,
        };
    }
}
