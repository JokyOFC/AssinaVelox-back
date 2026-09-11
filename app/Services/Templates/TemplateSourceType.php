<?php

namespace App\Services\Templates;

use App\Enums\DocumentSourceType;

/**
 * Fonte do conteúdo de um modelo (docs/fase-2/modelos.md §2).
 *
 * - `docx`: arquivo do Word com marcadores `${variavel}`; preenchido pelo PHPWord e
 *   convertido pelo pipeline normal (LibreOffice).
 * - `html`: HTML controlado pela aplicação, sanitizado, com marcadores `{{variavel}}`;
 *   renderizado em PDF pelo DOMPDF sem acesso a rede nem a arquivo local.
 * - `pdf`: PDF fixo, sem variáveis no corpo, com campos pré-posicionados por papel.
 */
enum TemplateSourceType: string
{
    case Docx = 'docx';
    case Html = 'html';
    case Pdf = 'pdf';

    public function label(): string
    {
        return match ($this) {
            self::Docx => 'Word (DOCX)',
            self::Html => 'Texto (HTML)',
            self::Pdf => 'PDF fixo',
        };
    }

    /**
     * Aceita variáveis no corpo do documento.
     */
    public function supportsVariables(): bool
    {
        return $this !== self::Pdf;
    }

    /**
     * Aceita campos pré-posicionados. Só o PDF fixo tem layout conhecido de antemão: em
     * DOCX/HTML o tamanho do texto preenchido desloca as páginas, então os campos são
     * posicionados no passo 3 do wizard (decisão pendente do roadmap §2.1, resolvida assim).
     */
    public function supportsFields(): bool
    {
        return $this === self::Pdf;
    }

    /**
     * Precisa de arquivo enviado (DOCX/PDF). O HTML é escrito no próprio editor.
     */
    public function requiresFile(): bool
    {
        return $this !== self::Html;
    }

    public function documentSourceType(): ?DocumentSourceType
    {
        return match ($this) {
            self::Docx => DocumentSourceType::Docx,
            self::Pdf => DocumentSourceType::Pdf,
            self::Html => null,
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
