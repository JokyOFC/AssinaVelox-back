<?php

namespace App\Services\Documents\Dto;

use App\Enums\DocumentSourceType;

/**
 * Resultado de UploadInspector::inspect(): o que o arquivo REALMENTE é, já validado.
 *
 * `displayName` é o nome exibido (sanitizado) e nunca é usado como caminho no disco —
 * o caminho é montado com ULIDs (DocumentIntake).
 */
final readonly class InspectedUpload
{
    /**
     * @param  array<string, mixed>  $details  metadados verificados (páginas de imagem, entradas do DOCX...)
     */
    public function __construct(
        public DocumentSourceType $sourceType,
        public string $mimeType,
        public string $extension,
        public int $sizeBytes,
        public string $displayName,
        public string $baseName,
        public array $details = [],
    ) {}
}
