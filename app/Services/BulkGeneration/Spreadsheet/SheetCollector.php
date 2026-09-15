<?php

namespace App\Services\BulkGeneration\Spreadsheet;

use App\Services\BulkGeneration\BulkGenerationLimits;

/**
 * Monta o {@see ParsedSheet} linha a linha, aplicando os limites de estrutura: primeira linha
 * não vazia = cabeçalho; colunas até `max_columns`; linhas de dados não vazias até `max_rows`;
 * linhas vazias toleradas até um teto (uma planilha com uma célula na linha 1.000.000 não
 * obriga a varrer um milhão de linhas).
 *
 * Recebe só texto já saneado ({@see CellSanitizer}) ou {@see RejectedCell}.
 */
final class SheetCollector
{
    public const MAX_EMPTY_LINES = 10_000;

    /** @var list<string>|null */
    private ?array $headers = null;

    /** @var list<array{line: int, cells: array<int, string|RejectedCell>}> */
    private array $rows = [];

    private int $emptyLines = 0;

    public function __construct(private readonly BulkGenerationLimits $limits) {}

    /**
     * @param  array<int, string|RejectedCell>  $cells
     *
     * @throws SpreadsheetRejectedException
     */
    public function push(int $line, array $cells): void
    {
        if (self::isEmpty($cells)) {
            if (++$this->emptyLines > self::MAX_EMPTY_LINES) {
                throw SpreadsheetRejectedException::make('too_many_empty_lines', 'A planilha tem linhas vazias demais. Apague as linhas vazias do fim e envie de novo.');
            }

            return;
        }

        if ($this->headers === null) {
            $this->headers = $this->headersFrom($cells);

            return;
        }

        if (count($this->rows) >= $this->limits->maxRows) {
            throw SpreadsheetRejectedException::make('too_many_rows', sprintf(
                'A planilha passa do limite de %s linhas por lote. Divida em lotes menores.',
                number_format($this->limits->maxRows, 0, ',', '.'),
            ));
        }

        $width = count($this->headers);
        $kept = [];

        foreach ($cells as $index => $cell) {
            if ($index < $width) {
                $kept[$index] = $cell;
            }
        }

        $this->rows[] = ['line' => $line, 'cells' => $kept];
    }

    /**
     * @param  'csv'|'xlsx'  $format
     *
     * @throws SpreadsheetRejectedException
     */
    public function finish(string $format, ?string $delimiter = null, bool $hiddenSheetsIgnored = false): ParsedSheet
    {
        if ($this->headers === null) {
            throw SpreadsheetRejectedException::make('no_header', 'A planilha está vazia. A primeira linha deve ter os nomes das colunas.');
        }

        if ($this->rows === []) {
            throw SpreadsheetRejectedException::make('no_rows', 'A planilha só tem o cabeçalho. Inclua ao menos uma linha de dados.');
        }

        return new ParsedSheet($format, $this->headers, $this->rows, $delimiter, $hiddenSheetsIgnored);
    }

    /**
     * @param  array<int, string|RejectedCell>  $cells
     * @return list<string>
     *
     * @throws SpreadsheetRejectedException
     */
    private function headersFrom(array $cells): array
    {
        $last = -1;

        foreach ($cells as $index => $cell) {
            if ($cell instanceof RejectedCell || $cell !== '') {
                $last = max($last, $index);
            }
        }

        $width = $last + 1;

        if ($width > $this->limits->maxColumns) {
            throw SpreadsheetRejectedException::make('too_many_columns', sprintf(
                'A planilha tem mais de %d colunas. Deixe só as colunas usadas pelo modelo.',
                $this->limits->maxColumns,
            ));
        }

        $headers = [];

        for ($index = 0; $index < $width; $index++) {
            $cell = $cells[$index] ?? '';
            $label = is_string($cell) ? CellSanitizer::header($cell) : '';
            $headers[] = $label !== '' ? $label : 'Coluna '.self::columnLetter($index);
        }

        return $headers;
    }

    /**
     * @param  array<int, string|RejectedCell>  $cells
     */
    private static function isEmpty(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell instanceof RejectedCell || $cell !== '') {
                return false;
            }
        }

        return true;
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    public static function columnLetter(int $index): string
    {
        $letters = '';
        $index++;

        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letters = chr(65 + $mod).$letters;
            $index = intdiv($index - $mod, 26);
        }

        return $letters;
    }
}
