<?php

namespace App\Services\BulkGeneration\Spreadsheet;

use App\Services\BulkGeneration\BulkGenerationLimits;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\BooleanCell;
use OpenSpout\Common\Entity\Cell\DateIntervalCell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\ErrorCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;
use XMLReader;
use ZipArchive;

/**
 * Leitura de planilha do lote (T6: dado não confiável; nada é avaliado nem executado).
 *
 * - **Formato pelo CONTEÚDO**, conferido com a extensão: `.xlsx` precisa ser um pacote ZIP
 *   OOXML de planilha; `.csv` precisa ser texto. `.xls` antigo, XLSX protegido por senha
 *   (contêiner OLE), XLSM com macro, PDF ou imagem renomeados são recusados com motivo claro.
 * - **CSV** por funções nativas (`fgetcsv`, escape RFC 4180): BOM UTF-8 removido, arquivo que
 *   não é UTF-8 lido como Windows-1252 (o "CSV" do Excel em português), separador `;` ou `,`
 *   detectado no cabeçalho.
 * - **XLSX** por streaming (OpenSpout 5.3, `LIBXML_NONET`): só a PRIMEIRA ABA VISÍVEL; abas
 *   ocultas são ignoradas. Antes de abrir, o pacote é medido descompactando em streaming
 *   (zip bomb) e conferido (entradas obrigatórias, sem `vbaProject.bin`). Célula de fórmula
 *   é recusada — nem a fórmula nem o valor calculado em cache são usados.
 * - Limites: tamanho do arquivo, linhas de dados, colunas, caracteres por célula.
 */
final class SpreadsheetReader
{
    private const MAX_ZIP_ENTRIES = 5000;

    private const OLE_SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    /** Piso do orçamento de células materializadas por aba (≈ 0,3 s de OpenSpout no pior caso). */
    private const MIN_CELL_BUDGET = 200_000;

    /**
     * @throws SpreadsheetRejectedException
     */
    public function read(string $path, string $extension, BulkGenerationLimits $limits): ParsedSheet
    {
        $size = @filesize($path);

        if ($size === false || $size === 0) {
            throw SpreadsheetRejectedException::make('empty', 'O arquivo está vazio.');
        }

        if ($size > $limits->maxFileBytes) {
            throw SpreadsheetRejectedException::make('too_large', sprintf(
                'O arquivo passa do limite de %s. Divida a planilha em lotes menores.',
                self::humanBytes($limits->maxFileBytes),
            ));
        }

        $format = $this->detectFormat($path, strtolower($extension));

        return $format === 'xlsx'
            ? $this->readXlsx($path, (int) $size, $limits)
            : $this->readCsv($path, $limits);
    }

    /**
     * @return 'csv'|'xlsx'
     *
     * @throws SpreadsheetRejectedException
     */
    public function detectFormat(string $path, string $extension): string
    {
        $handle = @fopen($path, 'rb');
        $magic = $handle !== false ? (string) fread($handle, 8) : '';

        if ($handle !== false) {
            fclose($handle);
        }

        if ($magic === self::OLE_SIGNATURE) {
            throw SpreadsheetRejectedException::make(
                'legacy_or_protected',
                'Este arquivo é uma planilha .xls antiga ou está protegido por senha. Salve como .xlsx (sem senha) ou CSV e envie de novo.',
            );
        }

        $isZip = str_starts_with($magic, "PK\x03\x04");

        if ($extension === 'xlsx') {
            if (! $isZip) {
                throw SpreadsheetRejectedException::make('invalid_xlsx', 'O arquivo não é uma planilha XLSX válida. Salve de novo pelo Excel ou envie em CSV.');
            }

            return 'xlsx';
        }

        if (in_array($extension, ['csv', 'txt'], true)) {
            if ($isZip || str_starts_with($magic, '%PDF')) {
                throw SpreadsheetRejectedException::make('invalid_csv', 'O arquivo não é um CSV de texto. Confira o formato e envie de novo.');
            }

            return 'csv';
        }

        throw SpreadsheetRejectedException::make('unsupported_type', 'Envie a planilha em CSV ou XLSX.');
    }

    // -- CSV ----------------------------------------------------------------------------

    /**
     * @throws SpreadsheetRejectedException
     */
    private function readCsv(string $path, BulkGenerationLimits $limits): ParsedSheet
    {
        $bytes = (string) file_get_contents($path);

        if (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF")) {
            throw SpreadsheetRejectedException::make('utf16', 'Este CSV está em UTF-16 ("Texto Unicode"). Salve como "CSV UTF-8" ou "CSV (separado por vírgulas)" e envie de novo.');
        }

        if (str_contains($bytes, "\0")) {
            throw SpreadsheetRejectedException::make('invalid_csv', 'O arquivo não é um CSV de texto. Confira o formato e envie de novo.');
        }

        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }

        if (! mb_check_encoding($bytes, 'UTF-8')) {
            // "CSV (separado por vírgulas)" do Excel em português sai em Windows-1252.
            $bytes = (string) mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
        }

        $delimiter = $this->detectDelimiter($bytes);

        $handle = fopen('php://temp', 'w+b');

        if ($handle === false) {
            throw SpreadsheetRejectedException::make('read_failed', 'Não foi possível ler a planilha. Tente novamente.');
        }

        try {
            fwrite($handle, $bytes);
            unset($bytes);
            rewind($handle);

            $collector = new SheetCollector($limits);
            $line = 0;

            while (($record = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $line++;

                $cells = [];

                foreach ($record as $index => $value) {
                    $cells[(int) $index] = CellSanitizer::text($value === null ? '' : (string) $value, $limits->maxCellChars);
                }

                $collector->push($line, $cells);
            }

            return $collector->finish('csv', $delimiter);
        } finally {
            fclose($handle);
        }
    }

    private function detectDelimiter(string $bytes): string
    {
        // Primeira linha física não vazia, ignorando o que está entre aspas.
        foreach (preg_split('/\r\n|\n|\r/', substr($bytes, 0, 65536)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $unquoted = preg_replace('/"[^"]*"/', '', $line) ?? $line;
            $semicolons = substr_count($unquoted, ';');
            $commas = substr_count($unquoted, ',');

            return $commas > $semicolons ? ',' : ';';
        }

        return ';';
    }

    // -- XLSX ---------------------------------------------------------------------------

    /**
     * @throws SpreadsheetRejectedException
     */
    private function readXlsx(string $path, int $size, BulkGenerationLimits $limits): ParsedSheet
    {
        $worksheets = $this->inspectPackage($path, $size, $limits);

        foreach ($worksheets as $worksheet) {
            $this->assertCellBudget($path, $worksheet, $limits);
        }

        $temp = storage_path('app/tmp/bulk-xlsx');

        if (! is_dir($temp)) {
            @mkdir($temp, 0775, true);
        }

        $reader = new Reader(new Options(
            SHOULD_FORMAT_DATES: false,
            SHOULD_PRESERVE_EMPTY_ROWS: true,
            tempFolder: $temp !== '' ? $temp : null,
        ));

        $collector = new SheetCollector($limits);
        $hiddenIgnored = false;
        $found = false;

        try {
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                if (! $sheet->isVisible()) {
                    $hiddenIgnored = true;

                    continue;
                }

                $found = true;
                $line = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $line++;

                    $cells = [];

                    foreach ($row->cells as $index => $cell) {
                        $cells[(int) $index] = $this->xlsxCell($cell, $limits->maxCellChars);
                    }

                    $collector->push($line, $cells);
                }

                break; // só a primeira aba visível
            }
        } catch (SpreadsheetRejectedException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw SpreadsheetRejectedException::make('invalid_xlsx', 'Não foi possível ler esta planilha XLSX. Salve de novo pelo Excel ou envie em CSV.');
        } finally {
            try {
                $reader->close();
            } catch (Throwable) {
                // nada a fazer
            }
        }

        if (! $found) {
            throw SpreadsheetRejectedException::make('no_visible_sheet', 'A planilha não tem nenhuma aba visível.');
        }

        return $collector->finish('xlsx', null, $hiddenIgnored);
    }

    private function xlsxCell(Cell $cell, int $maxChars): string|RejectedCell
    {
        return match (true) {
            $cell instanceof FormulaCell => RejectedCell::formula(),
            $cell instanceof ErrorCell => RejectedCell::spreadsheetError(),
            $cell instanceof EmptyCell => '',
            $cell instanceof DateTimeCell => CellSanitizer::date($cell->getValue()),
            $cell instanceof DateIntervalCell => RejectedCell::unsupported(),
            $cell instanceof BooleanCell => $cell->getValue() ? 'true' : 'false',
            $cell instanceof NumericCell => CellSanitizer::number($cell->getValue()),
            default => $this->stringCell($cell, $maxChars),
        };
    }

    private function stringCell(Cell $cell, int $maxChars): string|RejectedCell
    {
        $value = $cell->getValue();

        return is_string($value) ? CellSanitizer::text($value, $maxChars) : RejectedCell::unsupported();
    }

    /**
     * Largura REAL das linhas, antes do OpenSpout (revisão adversarial da onda F).
     *
     * O OpenSpout materializa, para cada linha, uma célula por coluna até a última célula
     * declarada: uma única `<c r="XFD1"/>` vira 16.384 células — e um XLSX de poucos KB com isso
     * em cada linha prendia o PHP por minutos dentro da requisição. Aqui a aba é lida em streaming
     * (XMLReader, `LIBXML_NONET`, só os atributos `r` de `<row>`/`<c>`), somando a largura de cada
     * linha; passou do orçamento (linhas × colunas do limite, com folga), a planilha é recusada
     * antes de qualquer célula ser montada. Custo linear no tamanho do XML, já limitado pela
     * checagem de expansão do ZIP.
     *
     * @throws SpreadsheetRejectedException
     */
    private function assertCellBudget(string $path, string $worksheet, BulkGenerationLimits $limits): void
    {
        $budget = max(self::MIN_CELL_BUDGET, ($limits->maxRows + 1) * $limits->maxColumns);
        $reader = new XMLReader;

        if (! @$reader->open('zip://'.$path.'#'.$worksheet, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw SpreadsheetRejectedException::make('invalid_xlsx', 'Não foi possível ler esta planilha XLSX. Salve de novo pelo Excel ou envie em CSV.');
        }

        $total = 0;
        $rowWidth = 0;
        $column = 0;

        try {
            while (@$reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                if ($reader->localName === 'row') {
                    $total += $rowWidth;
                    $rowWidth = 0;
                    $column = 0;
                } elseif ($reader->localName === 'c') {
                    $reference = $reader->getAttribute('r');
                    $column = is_string($reference) && $reference !== '' ? self::columnIndex($reference) : $column + 1;
                    $rowWidth = max($rowWidth, $column);
                } else {
                    continue;
                }

                if ($total + $rowWidth > $budget) {
                    throw SpreadsheetRejectedException::make('too_wide', sprintf(
                        'A planilha tem células preenchidas ou formatadas muito à direita (além da coluna %s). Apague as colunas que não são usadas — inclusive as vazias com formatação — e envie de novo.',
                        SheetCollector::columnLetter($limits->maxColumns - 1),
                    ));
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * "XFD123" → 16384 (1 = A). Referência sem letras conta como a coluna seguinte.
     */
    private static function columnIndex(string $reference): int
    {
        $index = 0;
        $length = strlen($reference);

        for ($i = 0; $i < $length && $i < 4; $i++) {
            $char = ord($reference[$i]);

            if ($char >= 65 && $char <= 90) {
                $index = $index * 26 + ($char - 64);
            } elseif ($char >= 97 && $char <= 122) {
                $index = $index * 26 + ($char - 96);
            } else {
                break;
            }
        }

        return max(1, $index);
    }

    /**
     * Pacote ZIP: entradas obrigatórias, sem macro, expansão medida em streaming.
     *
     * @return list<string> partes de planilha (`xl/worksheets/*.xml`) para a medida de largura
     *
     * @throws SpreadsheetRejectedException
     */
    private function inspectPackage(string $path, int $size, BulkGenerationLimits $limits): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw SpreadsheetRejectedException::make('zip_unavailable', 'A leitura de XLSX não está disponível nesta instalação. Envie em CSV.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw SpreadsheetRejectedException::make('invalid_xlsx', 'O arquivo não é uma planilha XLSX válida. Salve de novo pelo Excel ou envie em CSV.');
        }

        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ZIP_ENTRIES) {
                throw SpreadsheetRejectedException::make('invalid_xlsx', 'O arquivo não é uma planilha XLSX válida. Salve de novo pelo Excel ou envie em CSV.');
            }

            foreach (['[Content_Types].xml', 'xl/workbook.xml'] as $required) {
                if ($zip->locateName($required) === false) {
                    throw SpreadsheetRejectedException::make('invalid_xlsx', 'O arquivo não é uma planilha XLSX válida (parece ser outro tipo de documento compactado).');
                }
            }

            if ($zip->locateName('xl/vbaProject.bin', ZipArchive::FL_NOCASE) !== false) {
                throw SpreadsheetRejectedException::make('macro', 'A planilha contém macros. Salve como .xlsx comum (sem macros) e envie de novo.');
            }

            $this->assertExpansionWithinLimits($zip, $size, $limits->maxUncompressedBytes);

            $worksheets = [];

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);

                if (preg_match('#^xl/worksheets/[A-Za-z0-9_\-]+\.xml$#', $name) === 1) {
                    $worksheets[] = $name;
                }
            }

            return $worksheets;
        } finally {
            $zip->close();
        }
    }

    /**
     * @throws SpreadsheetRejectedException
     */
    private function assertExpansionWithinLimits(ZipArchive $zip, int $size, int $maxUncompressed): void
    {
        $ratioCeiling = $size * 200;
        $ceiling = max(1024 * 1024, min($maxUncompressed, $ratioCeiling));
        $total = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false) {
                throw SpreadsheetRejectedException::make('invalid_xlsx', 'O arquivo XLSX está corrompido.');
            }

            $name = (string) $stat['name'];

            if (str_ends_with($name, '/')) {
                continue;
            }

            $stream = $zip->getStream($name);

            if (! is_resource($stream)) {
                throw SpreadsheetRejectedException::make('invalid_xlsx', 'O arquivo XLSX contém partes que não puderam ser lidas.');
            }

            try {
                while (! feof($stream)) {
                    $buffer = fread($stream, 256 * 1024);

                    if ($buffer === false) {
                        throw SpreadsheetRejectedException::make('invalid_xlsx', 'O arquivo XLSX contém partes que não puderam ser lidas.');
                    }

                    $total += strlen($buffer);

                    if ($total > $ceiling) {
                        throw SpreadsheetRejectedException::make('too_large_uncompressed', 'O conteúdo da planilha é grande demais (ou tem compressão suspeita). Divida em lotes menores.');
                    }
                }
            } finally {
                fclose($stream);
            }
        }
    }

    public static function humanBytes(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? number_format($bytes / (1024 * 1024), $bytes % (1024 * 1024) === 0 ? 0 : 1, ',', '.').' MB'
            : number_format($bytes / 1024, 0, ',', '.').' KB';
    }
}
