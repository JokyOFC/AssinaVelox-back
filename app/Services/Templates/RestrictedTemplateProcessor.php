<?php

namespace App\Services\Templates;

use PhpOffice\PhpWord\TemplateProcessor;

/**
 * `TemplateProcessor` do PHPWord 1.4 reduzido ao que o modelo precisa: LER os marcadores
 * `${chave}` e trocá-los por texto, numa passada só.
 *
 * Não usamos `setValue()`/`setValues()` do PHPWord: eles trocam uma variável por vez, então
 * um valor que contenha `${outra}` seria expandido pela troca seguinte (injeção de
 * marcador). Aqui a troca é um único `preg_replace_callback` por parte do documento, e o
 * valor sai escapado para XML — nunca vira marcação WordprocessingML nem marcador. Blocos,
 * clones, imagens, macros e qualquer outro recurso do PHPWord ficam de fora.
 *
 * O arquivo só chega aqui depois de {@see DocxSafety} e da inspeção do upload.
 */
final class RestrictedTemplateProcessor extends TemplateProcessor
{
    /**
     * Marcadores válidos e malformados em corpo, cabeçalhos e rodapés.
     *
     * @return array{keys: list<string>, invalid: list<string>}
     */
    public function scanMarkers(): array
    {
        $keys = [];
        $invalid = [];

        foreach ($this->parts() as $xml) {
            $scan = PlaceholderEngine::scanDocx($xml);

            foreach ($scan['keys'] as $key) {
                $keys[$key] = true;
            }

            foreach ($scan['invalid'] as $marker) {
                $invalid[$marker] = true;
            }
        }

        return ['keys' => array_keys($keys), 'invalid' => array_keys($invalid)];
    }

    /**
     * @param  array<string, string>  $values  texto puro por chave (chave desconhecida → vazio)
     */
    public function fill(array $values): void
    {
        $callback = static function (array $match) use ($values): string {
            return self::xmlText($values[$match[1]] ?? '');
        };

        $this->tempDocumentMainPart = (string) preg_replace_callback(PlaceholderEngine::DOCX_MARKER, $callback, (string) $this->tempDocumentMainPart);

        foreach ($this->tempDocumentHeaders as $index => $xml) {
            $this->tempDocumentHeaders[$index] = (string) preg_replace_callback(PlaceholderEngine::DOCX_MARKER, $callback, (string) $xml);
        }

        foreach ($this->tempDocumentFooters as $index => $xml) {
            $this->tempDocumentFooters[$index] = (string) preg_replace_callback(PlaceholderEngine::DOCX_MARKER, $callback, (string) $xml);
        }
    }

    /**
     * Texto puro → conteúdo de `<w:t>`: caracteres inválidos em XML removidos, `& < > " '`
     * escapados, quebras de linha como `<w:br/>` (fechando e reabrindo o `<w:t>`).
     */
    public static function xmlText(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $escaped = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return str_replace("\n", '</w:t><w:br/><w:t xml:space="preserve">', $escaped);
    }

    /**
     * Uso só de leitura (varredura de marcadores): fecha o pacote e apaga a cópia temporária
     * que o PHPWord cria no construtor — sem isto ela ficaria no diretório temporário.
     */
    public function discard(): void
    {
        try {
            $this->zipClass->close();
        } catch (\Throwable) {
            // Já fechado.
        }

        if ($this->tempDocumentFilename !== '' && is_file($this->tempDocumentFilename)) {
            @unlink($this->tempDocumentFilename);
        }
    }

    /**
     * @return list<string>
     */
    private function parts(): array
    {
        return array_values(array_map('strval', [
            $this->tempDocumentMainPart,
            ...$this->tempDocumentHeaders,
            ...$this->tempDocumentFooters,
        ]));
    }
}
