<?php

namespace App\Services\Templates;

use App\Models\TemplateVariable;
use App\Models\TemplateVersion;
use App\Services\Pdf\Support\TemporaryDirectory;

/**
 * Produz o documento de uma versão de modelo a partir de valores JÁ VALIDADOS:
 *
 *  - HTML: corpo sanitizado DE NOVO (defesa em profundidade) → marcadores trocados por
 *    valores escapados ({@see PlaceholderEngine}) → DOMPDF trancado ({@see HtmlPdfRenderer});
 *  - DOCX: arquivo conferido pelo sha256 e por {@see DocxSafety} → marcadores trocados em
 *    uma passada, com escape XML ({@see RestrictedTemplateProcessor}) → DOCX preenchido,
 *    que segue para o pipeline normal de conversão.
 */
final class TemplateDocumentRenderer
{
    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
        private readonly HtmlPdfRenderer $pdf,
        private readonly DocxSafety $safety,
        private readonly TemplateStorage $storage,
    ) {}

    /**
     * Valores normalizados → texto PT-BR por chave.
     *
     * @param  array<string, string|int|float|bool|null>  $normalized
     * @return array<string, string>
     */
    public function formatted(TemplateVersion $version, array $normalized): array
    {
        $formatted = [];

        foreach ($version->variables as $variable) {
            $formatted[$variable->key] = VariableFormatter::format($variable->type, $variable->options ?? [], $normalized[$variable->key] ?? null);
        }

        return $formatted;
    }

    /**
     * Valores de exemplo para a pré-visualização do editor: o padrão (quando válido) ou o
     * rótulo entre colchetes — nunca dados reais.
     *
     * @return array<string, string>
     */
    public function sample(TemplateVersion $version, VariableValues $values): array
    {
        $sample = [];

        foreach ($version->variables as $variable) {
            /** @var TemplateVariable $variable */
            [$value, $error] = $values->normalize($variable->type, $variable->options ?? [], $variable->default_value, $variable->label, false);

            $sample[$variable->key] = $error === null && $value !== null && $variable->default_value !== null
                ? VariableFormatter::format($variable->type, $variable->options ?? [], $value)
                : '['.$variable->label.']';
        }

        return $sample;
    }

    /**
     * Corpo HTML preenchido (antes do DOMPDF). Exposto para os testes de injeção.
     *
     * @param  array<string, string>  $formatted
     */
    public function htmlBody(TemplateVersion $version, array $formatted): string
    {
        return PlaceholderEngine::renderHtml($this->sanitizer->sanitize((string) $version->html_body), $formatted);
    }

    /**
     * @param  array<string, string>  $formatted
     */
    public function htmlPdf(TemplateVersion $version, array $formatted, string $title): string
    {
        return $this->pdf->render($this->pdf->document($this->htmlBody($version, $formatted), $title));
    }

    /**
     * Caminho do DOCX preenchido dentro de `$directory`.
     *
     * @param  array<string, string>  $formatted
     *
     * @throws TemplateRejectedException
     */
    public function filledDocx(TemplateVersion $version, array $formatted, TemporaryDirectory $directory): string
    {
        $source = $this->storage->copyToTemporary($version, $directory, 'modelo.docx');

        $this->safety->assertSafe($source);

        $processor = new RestrictedTemplateProcessor($source);
        $processor->fill($formatted);

        $output = $directory->path('documento.docx');
        $processor->saveAs($output);

        // O arquivo gerado obedece às mesmas regras do modelo: um valor colocado dentro de uma
        // instrução de campo (`<w:instrText>`) viraria o próprio campo (ex.: INCLUDEPICTURE
        // buscando uma URL na conversão). O escape XML impede marcação, não isso.
        try {
            $this->safety->assertSafe($output);
        } catch (TemplateRejectedException) {
            throw TemplateRejectedException::make(
                'filled_docx_unsafe',
                'Algum valor preenchido transformaria um campo do Word em importação de conteúdo externo (como INCLUDEPICTURE). Revise os valores e tente de novo.',
            );
        }

        return $output;
    }
}
