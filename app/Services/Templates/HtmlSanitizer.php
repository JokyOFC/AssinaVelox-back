<?php

namespace App\Services\Templates;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Sanitização do HTML de modelos — conteúdo de CLIENTE, portanto não confiável (T6).
 *
 * Lista FECHADA (allowlist), aplicada sobre a árvore DOM — nunca por expressão regular
 * sobre o texto:
 *
 *  - elementos perigosos saem COM o conteúdo (script, style, iframe, object, embed, svg,
 *    math, form e controles, link, meta, base, img, mídia…);
 *  - elementos desconhecidos (inclusive `<a>`) são desembrulhados: o texto fica, a tag sai;
 *  - atributos: só `style` (filtrado) e alguns numéricos de tabela/lista. Todo `on*`,
 *    `href`, `src`, `srcset`, `xlink:*`, `class`, `id`, `formaction`… é removido;
 *  - CSS: só propriedades de uma lista fechada; valores sem `url(`, `expression`,
 *    `@import`, barra invertida, comentário ou função fora de rgb/rgba/hsl/hsla;
 *  - comentários, instruções de processamento e CDATA saem.
 *
 * O resultado ainda passa pelo DOMDocument do DOMPDF com rede e arquivo local desligados
 * ({@see HtmlPdfRenderer}) — duas camadas independentes.
 */
final class HtmlSanitizer
{
    public const MAX_LENGTH = 200_000;

    /** @var list<string> */
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup', 'small', 'span', 'div',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'hr', 'pre', 'code',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
    ];

    /** Removidos COM todo o conteúdo. @var list<string> */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'param',
        'template', 'noscript', 'svg', 'math', 'form', 'input', 'button', 'textarea', 'select',
        'option', 'optgroup', 'datalist', 'link', 'meta', 'base', 'title', 'head', 'audio',
        'video', 'source', 'track', 'canvas', 'img', 'picture', 'map', 'area', 'portal', 'xml',
        'xmp', 'plaintext', 'noembed', 'noframes', 'dialog', 'slot',
    ];

    /** Atributos numéricos permitidos por elemento. @var array<string, list<string>> */
    private const NUMERIC_ATTRIBUTES = [
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan'],
        'col' => ['span'],
        'colgroup' => ['span'],
        'ol' => ['start'],
        'table' => ['border', 'cellpadding', 'cellspacing'],
    ];

    /** @var list<string> */
    private const ALLOWED_CSS = [
        'color', 'background-color', 'text-align', 'text-decoration', 'text-indent', 'text-transform',
        'font-weight', 'font-style', 'font-size', 'line-height', 'letter-spacing', 'white-space',
        'vertical-align', 'width', 'max-width', 'min-width', 'height',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
        'border-color', 'border-width', 'border-style', 'border-collapse', 'border-spacing',
        'page-break-before', 'page-break-after', 'page-break-inside', 'list-style-type',
    ];

    /** @var list<string> */
    private const ALLOWED_CSS_FUNCTIONS = ['rgb', 'rgba', 'hsl', 'hsla'];

    public function sanitize(string $html): string
    {
        $html = mb_substr($html, 0, self::MAX_LENGTH);

        // Bytes nulos e caracteres de controle não têm lugar em HTML de documento.
        $html = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $html) ?? '';

        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            // LIBXML_NONET: o parser não resolve nada pela rede. O meta de charset garante a
            // leitura em UTF-8; o contêiner `#av-root` delimita o que foi enviado.
            $document->loadHTML(
                '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div id="av-root">'.$html.'</div></body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $document->getElementById('av-root');

        if (! $root instanceof DOMElement) {
            return '';
        }

        $this->cleanChildren($root);

        $output = '';

        foreach (iterator_to_array($root->childNodes) as $child) {
            $output .= (string) $document->saveHTML($child);
        }

        return trim($output);
    }

    private function cleanChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            $this->cleanNode($child);
        }
    }

    private function cleanNode(DOMNode $node): void
    {
        $parent = $node->parentNode;

        if ($parent === null) {
            return;
        }

        if ($node instanceof DOMText && $node->nodeType === XML_TEXT_NODE) {
            return;
        }

        if (! $node instanceof DOMElement) {
            // Comentário, CDATA, instrução de processamento, entidade…
            $parent->removeChild($node);

            return;
        }

        $tag = strtolower($node->localName ?? $node->nodeName);

        if (in_array($tag, self::DROP_WITH_CONTENT, true) || str_contains($tag, ':')) {
            $parent->removeChild($node);

            return;
        }

        $this->cleanChildren($node);

        if (! in_array($tag, self::ALLOWED_TAGS, true)) {
            // Desembrulha: filhos (já limpos) sobem, a tag sai.
            while ($node->firstChild !== null) {
                $parent->insertBefore($node->firstChild, $node);
            }

            $parent->removeChild($node);

            return;
        }

        $this->cleanAttributes($node, $tag);
    }

    private function cleanAttributes(DOMElement $element, string $tag): void
    {
        /** @var list<DOMAttr> $attributes */
        $attributes = iterator_to_array($element->attributes);

        foreach ($attributes as $attribute) {
            $name = strtolower($attribute->name);
            $value = (string) $attribute->value;

            if ($name === 'style') {
                $style = $this->sanitizeStyle($value);

                if ($style === '') {
                    $element->removeAttribute($attribute->name);
                } else {
                    $element->setAttribute('style', $style);
                }

                continue;
            }

            if (in_array($name, self::NUMERIC_ATTRIBUTES[$tag] ?? [], true) && preg_match('/^\d{1,3}$/', trim($value))) {
                $element->setAttribute($name, trim($value));

                continue;
            }

            $element->removeAttributeNode($attribute);
        }
    }

    public function sanitizeStyle(string $style): string
    {
        $kept = [];

        foreach (explode(';', $style) as $declaration) {
            if (! str_contains($declaration, ':')) {
                continue;
            }

            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            $property = strtolower($property);
            $lower = strtolower($value);

            if ($value === '' || ! in_array($property, self::ALLOWED_CSS, true)) {
                continue;
            }

            if (strlen($value) > 200
                || preg_match('/url\s*\(|expression|javascript|vbscript|@import|behavior|-moz-binding|\\\\|\/\*|[<>{}"\'`]/', $lower)
                || ! preg_match('/^[a-z0-9#.,%\s\-()!]+$/', $lower)) {
                continue;
            }

            if (preg_match_all('/([a-z-]+)\s*\(/', $lower, $functions) > 0
                && array_diff($functions[1], self::ALLOWED_CSS_FUNCTIONS) !== []) {
                continue;
            }

            $kept[] = $property.': '.$value;
        }

        return implode('; ', $kept);
    }
}
