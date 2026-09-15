<?php

namespace App\Services\BulkGeneration\Spreadsheet;

/**
 * Resultado da leitura: cabeçalho (primeira linha não vazia) e linhas de dados não vazias.
 *
 * `rows[].line` é a linha da planilha (cabeçalho = linha do cabeçalho, normalmente 1), para o
 * usuário localizar o erro. `rows[].cells` é indexado pela coluna (0 = A) e só tem texto
 * literal ou {@see RejectedCell}.
 */
final class ParsedSheet
{
    /**
     * @param  'csv'|'xlsx'  $format
     * @param  list<string>  $headers
     * @param  list<array{line: int, cells: array<int, string|RejectedCell>}>  $rows
     */
    public function __construct(
        public readonly string $format,
        public readonly array $headers,
        public readonly array $rows,
        public readonly ?string $delimiter = null,
        public readonly bool $hiddenSheetsIgnored = false,
    ) {}

    public function rowCount(): int
    {
        return count($this->rows);
    }
}
