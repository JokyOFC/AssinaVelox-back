<?php

namespace App\Services\Templates;

use DOMDocument;
use DOMElement;
use ZipArchive;

/**
 * Regras de segurança específicas de DOCX de MODELO, somadas às do upload comum
 * (`UploadInspector`: tipo real, zip bomb, entradas com caminho inválido).
 *
 * Um modelo é aberto pelo PHPWord e convertido pelo LibreOffice muitas vezes, com dados de
 * terceiros. Por isso é recusado qualquer DOCX que:
 *
 *  - carregue macros (`vbaProject.bin`, `vbaData.xml`, tipo de conteúdo `macroEnabled`);
 *  - carregue controles ActiveX ou objetos incorporados (`word/activeX/`,
 *    `word/embeddings/`) — conteúdo executável no Office e opaco para nós;
 *  - referencie algo EXTERNO que não seja um link comum (`TargetMode="External"` em modelo
 *    anexado `attachedTemplate`, imagem vinculada, `subDocument`, `frame`, OLE…): abrir o
 *    documento faria o conversor buscar conteúdo remoto;
 *  - use campos que importam conteúdo ou disparam DDE (`INCLUDETEXT`, `INCLUDE`,
 *    `INCLUDEPICTURE`, `DDE`, `DDEAUTO`, `IMPORT`, `LINK`).
 *
 * Tudo é lido como XML (DOMDocument, sem rede e sem DTD), nunca por expressão regular sobre o
 * texto bruto: o XML tem várias grafias para o mesmo conteúdo — referência de caractere
 * (`&#73;NCLUDEPICTURE`), atributo entre aspas simples, instrução de campo dividida em vários
 * `<w:instrText>` (o próprio Word grava assim) — e a regra precisa ver o que o Word e o
 * LibreOffice veem. Qualquer parte com `<!DOCTYPE>` (que o OOXML nunca usa e que permitiria
 * entidades) ou XML malformado é recusada.
 *
 * O mesmo serviço inspeciona o DOCX PREENCHIDO (TemplateDocumentRenderer::filledDocx): um
 * valor de variável colocado dentro de uma instrução de campo não pode virar um campo proibido.
 */
final class DocxSafety
{
    /** @var list<string> tipos de relacionamento externo aceitos (hyperlink transicional e estrito) */
    private const HYPERLINK_TYPES = [
        'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink',
        'http://purl.oclc.org/ooxml/officeDocument/relationships/hyperlink',
    ];

    /**
     * Tipos de campo recusados, comparados com o INÍCIO da instrução já sem espaços e em
     * maiúsculas (nenhum tipo de campo legítimo do Word começa com essas palavras). `INCLUDE`
     * é o sinônimo legado de `INCLUDETEXT`.
     *
     * @var list<string>
     */
    private const DANGEROUS_FIELDS = ['INCLUDETEXT', 'INCLUDEPICTURE', 'INCLUDE', 'DDEAUTO', 'DDE', 'IMPORT', 'LINK'];

    private const MACRO_CONTENT_TYPES = '/macroEnabled|vbaProject|ms-office\.activeX/i';

    /** Partes XML lidas por inteiro têm teto próprio (o total já foi limitado no upload). */
    private const MAX_PART_BYTES = 20 * 1024 * 1024;

    /**
     * @throws TemplateRejectedException
     */
    public function assertSafe(string $path): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw TemplateRejectedException::make('invalid_docx', 'O arquivo DOCX está corrompido ou não pôde ser aberto.');
        }

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = strtolower((string) $zip->getNameIndex($index));

                if (str_ends_with($name, 'vbaproject.bin') || str_ends_with($name, 'vbadata.xml') || str_contains($name, 'vbaproject')) {
                    throw $this->macro();
                }

                if (str_starts_with($name, 'word/activex/') || str_starts_with($name, 'word/embeddings/')) {
                    throw TemplateRejectedException::make(
                        'docx_embedded_objects',
                        'O modelo DOCX contém controles ActiveX ou objetos incorporados, que não são aceitos. Remova-os e envie de novo.',
                    );
                }

                $isRels = str_ends_with($name, '.rels');

                if (! $isRels && ! str_ends_with($name, '.xml')) {
                    continue;
                }

                $dom = $this->load($zip, $index);

                if ($dom === null) {
                    continue;
                }

                if ($isRels) {
                    $this->assertNoExternalRelationships($dom);

                    continue;
                }

                if ($name === '[content_types].xml') {
                    $this->assertNoMacroContentTypes($dom);
                }

                // Todas as partes XML (corpo, cabeçalhos, rodapés, notas, glossário…).
                $this->assertNoDangerousFields($dom);
            }

            $types = $zip->getFromName('[Content_Types].xml');

            if (is_string($types) && preg_match(self::MACRO_CONTENT_TYPES, $types)) {
                throw $this->macro();
            }
        } finally {
            $zip->close();
        }
    }

    private function macro(): TemplateRejectedException
    {
        return TemplateRejectedException::make(
            'docx_macros',
            'O modelo DOCX contém macros, que não são aceitas. Salve o arquivo como "Documento do Word (.docx)" sem macros e envie de novo.',
        );
    }

    private function part(ZipArchive $zip, int $index): string
    {
        $stat = $zip->statIndex($index);

        if ($stat === false || (int) $stat['size'] > self::MAX_PART_BYTES) {
            throw TemplateRejectedException::make('invalid_docx', 'O arquivo DOCX tem partes internas grandes demais para um modelo.');
        }

        return (string) $zip->getFromIndex($index);
    }

    /**
     * Parte XML como documento (sem rede, sem DTD, sem expansão de entidades externas).
     * Parte vazia → null (nada a inspecionar).
     */
    private function load(ZipArchive $zip, int $index): ?DOMDocument
    {
        $xml = $this->part($zip, $index);

        if (trim($xml) === '') {
            return null;
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $dom->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            throw TemplateRejectedException::make('invalid_docx', 'O arquivo DOCX está corrompido ou não pôde ser lido.');
        }

        if ($dom->doctype !== null) {
            throw TemplateRejectedException::make('invalid_docx', 'O arquivo DOCX tem uma estrutura interna não aceita. Salve-o de novo pelo Word como "Documento do Word (.docx)" e envie outra vez.');
        }

        return $dom;
    }

    private function assertNoExternalRelationships(DOMDocument $dom): void
    {
        foreach ($dom->getElementsByTagName('*') as $element) {
            if ($element->localName !== 'Relationship') {
                continue;
            }

            if (strtolower(trim((string) $this->attribute($element, 'TargetMode'))) !== 'external') {
                continue;
            }

            if (in_array(trim((string) $this->attribute($element, 'Type')), self::HYPERLINK_TYPES, true)) {
                continue;
            }

            throw TemplateRejectedException::make(
                'docx_external_reference',
                'O modelo DOCX referencia conteúdo externo (modelo anexado, imagem vinculada ou objeto remoto), o que não é aceito. Incorpore o conteúdo ao arquivo e envie de novo.',
            );
        }
    }

    private function assertNoMacroContentTypes(DOMDocument $dom): void
    {
        foreach ($dom->getElementsByTagName('*') as $element) {
            $type = $this->attribute($element, 'ContentType');

            if ($type !== null && preg_match(self::MACRO_CONTENT_TYPES, $type)) {
                throw $this->macro();
            }
        }
    }

    private function assertNoDangerousFields(DOMDocument $dom): void
    {
        foreach ($this->fieldInstructions($dom) as $instruction) {
            $normalized = strtoupper((string) preg_replace('/\s+/u', '', $instruction));

            foreach (self::DANGEROUS_FIELDS as $field) {
                if (str_starts_with($normalized, $field)) {
                    throw TemplateRejectedException::make(
                        'docx_dangerous_fields',
                        'O modelo DOCX contém campos que importam conteúdo externo (como INCLUDETEXT, INCLUDEPICTURE ou DDE). Remova esses campos e envie de novo.',
                    );
                }
            }
        }
    }

    /**
     * Instrução de cada campo da parte, montada como o Word a monta:
     *
     *  - campo simples: o atributo `w:instr` de `<w:fldSimple>`;
     *  - campo complexo: `fldChar begin` → textos de `<w:instrText>` CONCATENADOS (sem
     *    separador) → `fldChar separate` → resultado → `fldChar end`. Um campo aninhado na
     *    instrução contribui com o seu resultado para a instrução do campo de fora.
     *
     * Entidades e referências de caractere já chegam resolvidas pelo parser. A pilha de
     * campos abertos é guardada em três listas paralelas (instrução, resultado, separado).
     *
     * @return list<string>
     */
    private function fieldInstructions(DOMDocument $dom): array
    {
        $instructions = [];
        $openInstruction = [];
        $openResult = [];
        $openSeparated = [];

        foreach ($dom->getElementsByTagName('*') as $element) {
            $top = count($openInstruction) - 1;

            switch ($element->localName) {
                case 'fldSimple':
                    $instructions[] = (string) $this->attribute($element, 'instr');
                    break;

                case 'fldChar':
                    $type = strtolower((string) $this->attribute($element, 'fldCharType'));

                    if ($type === 'begin') {
                        $openInstruction[] = '';
                        $openResult[] = '';
                        $openSeparated[] = false;
                    } elseif ($type === 'separate' && $top >= 0) {
                        $openSeparated[$top] = true;
                    } elseif ($type === 'end' && $top >= 0) {
                        $instructions[] = $openInstruction[$top];
                        $childResult = $openResult[$top];

                        array_splice($openInstruction, $top);
                        array_splice($openResult, $top);
                        array_splice($openSeparated, $top);

                        $parent = $top - 1;

                        if ($parent >= 0) {
                            if ($openSeparated[$parent]) {
                                $openResult[$parent] .= $childResult;
                            } else {
                                $openInstruction[$parent] .= $childResult;
                            }
                        }
                    }

                    break;

                case 'instrText':
                case 'delInstrText':
                    if ($top < 0) {
                        // Instrução solta, fora de begin/end: inspecionada sozinha.
                        $instructions[] = $element->textContent;
                    } elseif (! $openSeparated[$top]) {
                        $openInstruction[$top] .= $element->textContent;
                    }

                    break;

                case 't':
                case 'delText':
                    if ($top >= 0 && $openSeparated[$top]) {
                        $openResult[$top] .= $element->textContent;
                    }

                    break;
            }
        }

        // Campos sem `end` (arquivo truncado) também são inspecionados.
        foreach ($openInstruction as $instruction) {
            $instructions[] = $instruction;
        }

        return $instructions;
    }

    /**
     * Valor do atributo pelo nome LOCAL (com ou sem prefixo de namespace), já com entidades
     * resolvidas.
     */
    private function attribute(DOMElement $element, string $localName): ?string
    {
        foreach ($element->attributes as $attribute) {
            if ($attribute->localName === $localName) {
                return (string) $attribute->nodeValue;
            }
        }

        return null;
    }
}
